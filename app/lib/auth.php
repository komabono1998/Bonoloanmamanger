<?php
/**
 * Security layer: authentication (password + MFA), sessions, role-based permissions, audit logging, number sequences.
 * Permissions are enforced on the server for every action; hiding a menu item is never the control.
 */

/** Sessions in the database so any app server can serve any request (SESSION_DRIVER=db). */
final class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false
    {
        try { return (string)(val('SELECT data FROM app_sessions WHERE id = ?', [$id]) ?? ''); } catch (Throwable $e) { return ''; }
    }
    public function write(string $id, string $data): bool
    {
        try {
            q('INSERT INTO app_sessions(id, data, updated_at) VALUES (?,?,?) ON CONFLICT (id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at', [$id, $data, time()]);
            return true;
        } catch (Throwable $e) { return false; }
    }
    public function destroy(string $id): bool { try { q('DELETE FROM app_sessions WHERE id = ?', [$id]); } catch (Throwable $e) {} return true; }
    public function gc(int $max): int|false { try { return q('DELETE FROM app_sessions WHERE updated_at < ?', [time() - $max])->rowCount(); } catch (Throwable $e) { return 0; } }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    if (strtolower((string)env('SESSION_DRIVER', 'files')) === 'db') {
        try { val('SELECT 1 FROM app_sessions LIMIT 1'); session_set_save_handler(new DbSessionHandler(), true); }
        catch (Throwable $e) { /* not installed yet: the first (setup) requests use file sessions */ }
    }
    session_name(is_production() ? '__Host-BONOSESS' : 'BONOSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => request_is_https()]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function user(): ?array
{
    return $GLOBALS['__user'] ?? null;
}

function uid(): ?int
{
    $u = user();
    return $u ? (int)$u['id'] : null;
}

function reset_user_caches(): void
{
    unset($GLOBALS['__perms'], $GLOBALS['__branch_ids'], $GLOBALS['__branch']);
}

function find_user_by_name(string $username): ?array
{
    return row('SELECT * FROM users WHERE lower(username) = lower(?)', [$username]);
}

/** Load the logged-in user from the session, applying the inactivity timeout. */
function load_session_user(): void
{
    $GLOBALS['__user'] = null;
    reset_user_caches();
    if (empty($_SESSION['uid'])) return;
    $timeout = max(1, setting_int('session_timeout_min')) * 60;
    if (time() - ($_SESSION['last_activity'] ?? 0) > $timeout) {
        $uname = $_SESSION['uname'] ?? '';
        audit('Session locked (inactivity)', 'Security', 'user', (string)$_SESSION['uid'], null, null, '', '', (int)$_SESSION['uid'], $uname);
        $_SESSION = [];
        session_regenerate_id(true);
        flash('warn', 'You were signed out after a period of inactivity. Please sign in again.');
        return;
    }
    $_SESSION['last_activity'] = time();
    $u = row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['uid']]);
    if ($u) $GLOBALS['__user'] = $u;
    else $_SESSION = [];
}

function login_as(int $id): void
{
    $GLOBALS['__user'] = row('SELECT * FROM users WHERE id = ?', [$id]);
    reset_user_caches();
}

function log_login_event(?array $u, string $username, bool $ok, string $reason = ''): void
{
    q('INSERT INTO login_events(at, user_id, username, ip, user_agent, success, reason) VALUES (?,?,?,?,?,?,?)',
        [now(), $u['id'] ?? null, $username, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200), $ok ? 1 : 0, $reason ?: null]);
}

function mfa_required_for(array $u): bool
{
    $roles = array_map('trim', explode(',', setting('mfa_required_roles')));
    return in_array($u['role'], $roles, true);
}

/**
 * First factor. Returns 'ok' (signed in), 'mfa' (password correct, a code is needed) or 'fail'.
 * Failed attempts lock the account for 5 minutes after 5 tries; repeated failures from one IP are throttled.
 */
function attempt_login(string $username, string $password): string
{
    $recentIp = (int)val('SELECT COUNT(*) FROM login_events WHERE ip = ? AND success = 0 AND at > ?', [client_ip(), date('Y-m-d H:i:s', time() - 600)]);
    if ($recentIp >= 25) throw new AppError('Too many failed sign-in attempts from this location. Please wait a few minutes.');
    $u = find_user_by_name($username);
    if (!$u || $u['status'] !== 'active') {
        log_login_event(null, $username, false, 'unknown or inactive account');
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuuMTP9aRZ5lq5R5mGQ7iIq3m1qyT5l3yG');   // keep timing similar
        return 'fail';
    }
    if ($u['locked_until'] && $u['locked_until'] > now()) {
        log_login_event($u, $username, false, 'account locked');
        throw new AppError('This account is temporarily locked after repeated failed attempts. Try again in a few minutes.');
    }
    if (!password_verify($password, $u['password_hash'])) {
        register_failed_attempt($u, 'wrong password');
        return 'fail';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    if (!empty($u['mfa_enabled'])) {
        $_SESSION['mfa_uid'] = (int)$u['id'];
        $_SESSION['mfa_started'] = time();
        return 'mfa';
    }
    complete_login($u);
    return 'ok';
}

function register_failed_attempt(array $u, string $why): void
{
    $n = (int)$u['failed_attempts'] + 1;
    $lock = $n >= 5 ? date('Y-m-d H:i:s', time() + 300) : null;
    q('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?', [$n >= 5 ? 0 : $n, $lock, $u['id']]);
    log_login_event($u, $u['username'], false, $why . " (attempt $n)");
    audit('Failed login', 'Security', 'user', (string)$u['id'], null, null, $why, '', (int)$u['id'], $u['username']);
}

/** Second factor. Accepts an authenticator code or a one-time recovery code. */
function verify_mfa_login(string $code): bool
{
    $id = (int)($_SESSION['mfa_uid'] ?? 0);
    if (!$id || time() - (int)($_SESSION['mfa_started'] ?? 0) > 300) { unset($_SESSION['mfa_uid']); throw new AppError('The sign-in session expired. Please enter your password again.'); }
    $u = row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$id]);
    if (!$u) return false;
    if ($u['locked_until'] && $u['locked_until'] > now()) throw new AppError('This account is temporarily locked after repeated failed attempts. Try again in a few minutes.');
    $secret = decrypt_secret($u['mfa_secret']);
    $ok = $secret && Mfa::verify($secret, $code) !== null;
    if (!$ok) {
        $left = Mfa::consumeRecovery($u['mfa_recovery'], $code);
        if ($left !== null) { q('UPDATE users SET mfa_recovery = ? WHERE id = ?', [$left, $id]); audit('MFA recovery code used', 'Security', 'user', (string)$id, null, null, '', '', $id, $u['username']); $ok = true; }
    }
    if (!$ok) { register_failed_attempt($u, 'wrong MFA code'); return false; }
    unset($_SESSION['mfa_uid'], $_SESSION['mfa_started']);
    complete_login($u);
    return true;
}

function complete_login(array $u): void
{
    $ip = client_ip();
    $newIp = !empty($u['last_ip']) && $u['last_ip'] !== $ip;
    q('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = ?, last_ip = ? WHERE id = ?', [now(), $ip, $u['id']]);
    if (PHP_SAPI !== 'cli') {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['uname'] = $u['username'];
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        unset($_SESSION['branch']);
        // MFA enrolment is mandatory for the configured roles
        if (mfa_required_for($u) && empty($u['mfa_enabled'])) $_SESSION['mfa_enroll'] = 1; else unset($_SESSION['mfa_enroll']);
    }
    $GLOBALS['__user'] = $u;
    reset_user_caches();
    log_login_event($u, $u['username'], true, $newIp ? 'new IP address (was ' . $u['last_ip'] . ')' : '');
    audit('Login', 'Security', 'user', (string)$u['id'], null, null, $newIp ? 'new IP address' : '');
    $max = setting_int('password_max_age_days');
    if ($max > 0 && $u['password_changed_at'] && strtotime($u['password_changed_at']) < time() - $max * 86400) {
        q('UPDATE users SET must_change_password = 1 WHERE id = ?', [$u['id']]);
        $GLOBALS['__user']['must_change_password'] = 1;
    }
}

function logout(): void
{
    if (user()) audit('Logout', 'Security', 'user', (string)uid());
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $GLOBALS['__user'] = null;
    reset_user_caches();
}

function user_permissions(?int $userId = null): array
{
    $userId = $userId ?? uid();
    if (!$userId) return [];
    if ($userId === uid() && isset($GLOBALS['__perms'])) return $GLOBALS['__perms'];
    $u = row('SELECT role FROM users WHERE id = ?', [$userId]);
    if (!$u) return [];
    $set = array_flip(array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$u['role']]), 'permission'));
    foreach (rows('SELECT permission, granted FROM user_permissions WHERE user_id = ?', [$userId]) as $o) {
        if ($o['granted']) $set[$o['permission']] = true; else unset($set[$o['permission']]);
    }
    $res = array_keys($set);
    if ($userId === uid()) $GLOBALS['__perms'] = $res;
    return $res;
}

function can(string $perm): bool
{
    return in_array($perm, user_permissions(), true);
}

/** True if the user holds ANY of the permissions in a 'a|b' list. */
function can_any(string $perms): bool
{
    foreach (explode('|', $perms) as $p) if (can($p)) return true;
    return false;
}

function require_perm(string $perm): void
{
    if (!can($perm)) throw new AppError('You do not have permission to perform this action.');
}

function password_policy_check(string $pw): void
{
    $min = max(8, setting_int('password_min_length'));
    must(strlen($pw) >= $min, "Password must be at least $min characters long.");
    must(preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw), 'Password must contain letters and numbers.');
}

// ------------------------------------------------------------------ audit
function audit(string $action, string $module, ?string $recType = null, $recId = null, $prev = null, $new = null,
               string $reason = '', string $approvalRef = '', ?int $userId = null, ?string $username = null, ?int $branchId = null): void
{
    $u = user();
    $bd = null; $br = $branchId;
    try {
        if ($br === null && function_exists('branch_id') && $u) $br = branch_id() ?: null;
        $bd = function_exists('current_bd') && $br ? current_bd($br) : null;
    } catch (Throwable $e) {}
    $enc = fn($v) => $v === null ? null : (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    q('INSERT INTO audit_logs(branch_id, user_id, username, at, business_date, action, module, record_type, record_id, prev_value, new_value, reason, approval_ref, ip)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $br ?: null, $userId ?? ($u['id'] ?? null), $username ?? ($u['username'] ?? 'system'), now(), $bd, $action, $module,
        $recType, $recId === null ? null : (string)$recId, $enc($prev), $enc($new), $reason ?: null, $approvalRef ?: null, client_ip(),
    ]);
}

// ------------------------------------------------------------------ numbering
/** Atomically allocate the next number in a named sequence. Numbers are never reused. */
function next_seq(string $name): int
{
    return tx(function () use ($name) {
        q('INSERT INTO sequences(name, last) VALUES (?, 0) ON CONFLICT (name) DO NOTHING', [$name]);
        q('UPDATE sequences SET last = last + 1 WHERE name = ?', [$name]);      // row lock held until commit: concurrent users get distinct numbers
        return (int)val('SELECT last FROM sequences WHERE name = ?', [$name]);
    });
}

function make_number(string $prefixKey, string $seqName, bool $yearly, int $pad = 6): string
{
    $year = date('Y');
    $n = next_seq($yearly ? "$seqName-$year" : $seqName);
    return setting($prefixKey) . '-' . ($yearly ? "$year-" : '') . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}
