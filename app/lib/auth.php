<?php
/** Security layer: authentication, sessions, role-based permissions, audit logging, number sequences. */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    session_name('BONOSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
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

/** Load the logged-in user from the session, applying the inactivity timeout. */
function load_session_user(): void
{
    $GLOBALS['__user'] = null;
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
    unset($GLOBALS['__perms']);
}

function attempt_login(string $username, string $password): bool
{
    $u = row('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$u || $u['status'] !== 'active') {
        audit('Failed login', 'Security', 'user', $username, null, null, 'Unknown or inactive account', '', null, $username);
        return false;
    }
    if ($u['locked_until'] && $u['locked_until'] > now()) {
        throw new AppError('This account is temporarily locked after repeated failed attempts. Try again in a few minutes.');
    }
    if (!password_verify($password, $u['password_hash'])) {
        $n = (int)$u['failed_attempts'] + 1;
        $lock = $n >= 5 ? date('Y-m-d H:i:s', time() + 300) : null;
        q('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?', [$n >= 5 ? 0 : $n, $lock, $u['id']]);
        audit('Failed login', 'Security', 'user', (string)$u['id'], null, null, "Attempt $n", '', (int)$u['id'], $username);
        return false;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    q('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = ? WHERE id = ?', [now(), $u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['uname'] = $u['username'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    $GLOBALS['__user'] = $u;
    unset($GLOBALS['__perms']);
    audit('Login', 'Security', 'user', (string)$u['id']);
    return true;
}

function logout(): void
{
    if (user()) audit('Logout', 'Security', 'user', (string)uid());
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $GLOBALS['__user'] = null;
}

function user_permissions(?int $userId = null): array
{
    $userId = $userId ?? uid();
    if (!$userId) return [];
    if ($userId === uid() && isset($GLOBALS['__perms'])) return $GLOBALS['__perms'];
    $u = row('SELECT role FROM users WHERE id = ?', [$userId]);
    if (!$u) return [];
    $set = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$u['role']]), 'permission');
    $set = array_flip($set);
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
    must(strlen($pw) >= 8, 'Password must be at least 8 characters long.');
    must(preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw), 'Password must contain letters and numbers.');
}

// ------------------------------------------------------------------ audit
function audit(string $action, string $module, ?string $recType = null, $recId = null, $prev = null, $new = null,
               string $reason = '', string $approvalRef = '', ?int $userId = null, ?string $username = null): void
{
    $u = user();
    $bd = null;
    try { $bd = function_exists('current_bd') ? current_bd() : null; } catch (Throwable $e) {}
    $enc = fn($v) => $v === null ? null : (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    q('INSERT INTO audit_logs(user_id, username, at, business_date, action, module, record_type, record_id, prev_value, new_value, reason, approval_ref, ip)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $userId ?? ($u['id'] ?? null), $username ?? ($u['username'] ?? 'system'), now(), $bd, $action, $module,
        $recType, $recId === null ? null : (string)$recId, $enc($prev), $enc($new), $reason ?: null, $approvalRef ?: null, client_ip(),
    ]);
}

// ------------------------------------------------------------------ numbering
/** Atomically allocate the next number in a named sequence. Numbers are never reused. */
function next_seq(string $name): int
{
    return tx(function () use ($name) {
        q('INSERT OR IGNORE INTO sequences(name, last) VALUES (?, 0)', [$name]);
        q('UPDATE sequences SET last = last + 1 WHERE name = ?', [$name]);
        return (int)val('SELECT last FROM sequences WHERE name = ?', [$name]);
    });
}

function make_number(string $prefixKey, string $seqName, bool $yearly, int $pad = 6): string
{
    $year = date('Y');
    $n = next_seq($yearly ? "$seqName-$year" : $seqName);
    return setting($prefixKey) . '-' . ($yearly ? "$year-" : '') . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}
