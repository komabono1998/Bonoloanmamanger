<?php
/** Password reset / invitation links sent by email. Tokens are single-use, expire, and only their hash is stored. */
final class PasswordReset
{
    public static function create(int $userId, string $purpose = 'reset', ?int $requestedBy = null): string
    {
        $hours = $purpose === 'invite' ? 72 : 2;
        $token = bin2hex(random_bytes(24));
        q('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $userId]);
        q('INSERT INTO password_resets(user_id, token_hash, purpose, created_at, expires_at, requested_by) VALUES (?,?,?,?,?,?)',
            [$userId, hash('sha256', $token), $purpose, now(), date('Y-m-d H:i:s', time() + $hours * 3600), $requestedBy]);
        return $token;
    }

    /** Emails a link. Returns [sent(bool), link]. Active users with an email address only. */
    public static function send(int $userId, string $purpose = 'reset', ?int $requestedBy = null): array
    {
        $u = row('SELECT * FROM users WHERE id = ?', [$userId]);
        must((bool)$u, 'User not found.');
        must($u['status'] === 'active', 'This account is inactive.');
        must(!empty($u['email']), 'This user has no email address. Add one first.');
        $token = self::create($userId, $purpose, $requestedBy);
        $link = app_url('reset', ['token' => $token]);
        $invite = $purpose === 'invite';
        $body = '<p>Hello ' . e($u['full_name']) . ',</p><p>' . ($invite ? 'An account has been created for you. Your Staff ID is <b>' . e((string)$u['staff_no']) . '</b> and your username is <b>' . e($u['username']) . '</b>. Use the button below to choose your password.'
            : 'We received a request to reset your password. Use the button below to choose a new one.') . '</p><p>The link works once and expires in ' . ($invite ? '72 hours' : '2 hours') . '.</p>';
        $sent = send_mail($u['email'], $invite ? 'Welcome to ' . business_name() : 'Reset your password', mail_template($invite ? 'Set up your account' : 'Reset your password', $body, $invite ? 'Choose my password' : 'Choose a new password', $link));
        audit($invite ? 'Invitation email issued' : 'Password reset email issued', 'Users', 'user', $userId, null, $sent ? 'emailed' : 'logged (SMTP not configured)', '', '', $requestedBy ?? $userId, null, null);
        return [$sent, $link];
    }

    public static function find(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
        $r = row('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), now()]);
        return $r ?: null;
    }

    /** Sets the new password. Returns the user row. */
    public static function consume(string $token, string $password): array
    {
        $r = self::find($token);
        must((bool)$r, 'This link is invalid or has expired. Ask for a new one.');
        password_policy_check($password);
        $u = row('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
        must($u && $u['status'] === 'active', 'This account is inactive.');
        tx(function () use ($r, $u, $password) {
            update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => now(), 'must_change_password' => 0, 'failed_attempts' => 0, 'locked_until' => null], 'id = :id', ['id' => $u['id']]);
            q('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $u['id']]);
            audit('Password set through email link', 'Users', 'user', $u['id'], null, $r['purpose'], '', '', (int)$u['id'], $u['username'], null);
        });
        return $u;
    }

    /** Self-service "forgot password": always behaves the same so accounts cannot be probed. */
    public static function requestFor(string $identifier): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') return;
        $u = find_user_by_name($identifier);
        if (!$u || $u['status'] !== 'active' || empty($u['email'])) return;
        if ((int)val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [$u['id'], date('Y-m-d H:i:s', time() - 300)])) return;
        try { self::send((int)$u['id'], 'reset', null); } catch (Throwable $e) { error_log($e->getMessage()); }
    }
}
