<?php
/** General helpers: escaping, formatting, request/response, CSRF, flash messages. */

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function num($n): string
{
    return number_format((int)$n);
}

function ugx($n): string
{
    $n = (int)$n;
    return ($n < 0 ? '-' : '') . 'UGX ' . number_format(abs($n));
}

/** Parse a user-entered money value into whole UGX. */
function parse_money($s, string $label = 'Amount', bool $allowZero = false): int
{
    $s = trim(str_replace([',', ' ', 'UGX', 'ugx'], '', (string)$s));
    if ($s === '' || !preg_match('/^\d+(\.0+)?$/', $s)) {
        throw new AppError("$label must be a valid amount in whole UGX.");
    }
    $v = (int)$s;
    if ($v <= 0 && !$allowZero) throw new AppError("$label must be greater than zero.");
    return $v;
}

function today(): string { return date('Y-m-d'); }
function now(): string { return date('Y-m-d H:i:s'); }

function is_date($s): bool
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

function must_date($s, string $label = 'Date', bool $required = true): ?string
{
    $s = trim((string)$s);
    if ($s === '') {
        if ($required) throw new AppError("$label is required.");
        return null;
    }
    if (!is_date($s)) throw new AppError("$label is not a valid date.");
    return $s;
}

function fmt_date($d): string
{
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : (string)$d;
}

function fmt_dt($d): string
{
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('d M Y H:i', $t) : (string)$d;
}

function days_between(string $from, string $to): int
{
    return (int)round((strtotime($to) - strtotime($from)) / 86400);
}

function must(bool $cond, string $msg): void
{
    if (!$cond) throw new AppError($msg);
}

function clean($s): string
{
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

// ------------------------------------------------------------------ request
function base_url(): string
{
    static $b = null;
    if ($b === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $b = rtrim($dir, '/');
    }
    return $b;
}

function url(string $page = 'dashboard', array $params = []): string
{
    $q = array_merge(['p' => $page], $params);
    return base_url() . '/index.php?' . http_build_query($q);
}

function asset(string $path): string
{
    $f = BASE_PATH . '/assets/' . $path;
    return base_url() . '/assets/' . $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

function post(string $k, $default = '')
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $k, $default = '')
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'cli'; }

// ------------------------------------------------------------------ CSRF + flash
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        throw new AppError('Your session form expired. Please go back, refresh the page and try again.');
    }
}

/** One-time token to stop double submission of the same form (duplicate posting protection). */
function form_token(): string
{
    $t = bin2hex(random_bytes(12));
    return $t;
}

function token_field(): string
{
    return '<input type="hidden" name="txn_token" value="' . e(form_token()) . '">';
}

function flash(string $type, string $msg): void
{
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ------------------------------------------------------------------ amounts in words
function amount_in_words(int $n): string
{
    if ($n === 0) return 'Zero Uganda Shillings only';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $below1000 = function (int $x) use ($ones, $tens): string {
        $s = '';
        if ($x >= 100) { $s .= $ones[intdiv($x, 100)] . ' Hundred'; $x %= 100; if ($x) $s .= ' and '; }
        if ($x >= 20) { $s .= $tens[intdiv($x, 10)]; if ($x % 10) $s .= '-' . $ones[$x % 10]; }
        elseif ($x > 0) $s .= $ones[$x];
        return $s;
    };
    $parts = [];
    foreach ([[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand']] as [$v, $name]) {
        if ($n >= $v) { $parts[] = $below1000(intdiv($n, $v)) . ' ' . $name; $n %= $v; }
    }
    if ($n > 0) $parts[] = ($parts && $n < 100 ? 'and ' : '') . $below1000($n);
    return implode(' ', $parts) . ' Uganda Shillings only';
}

function badge(string $status): string
{
    $map = [
        'Active' => 'ok', 'Approved' => 'ok', 'Accepted' => 'ok', 'Closed' => 'muted', 'Reconciled' => 'ok', 'Paid' => 'ok',
        'Valid' => 'ok', 'Posted' => 'ok', 'Pledged' => 'ok', 'Held' => 'ok', 'Settled' => 'ok', 'Completed' => 'ok',
        'Overdue' => 'bad', 'Rejected' => 'bad', 'Declined' => 'bad', 'Void' => 'bad', 'Reversed' => 'bad', 'Cancelled' => 'muted',
        'Disputed' => 'bad', 'Investigation' => 'bad', 'Variance Investigation' => 'bad', 'Blacklisted' => 'bad', 'Failed' => 'bad',
        'Pending' => 'warn', 'Submitted' => 'warn', 'Under Review' => 'warn', 'Returned for Correction' => 'warn', 'Draft' => 'muted',
        'Open' => 'info', 'Reopened' => 'warn', 'Disbursed' => 'info', 'Due Today' => 'warn', 'Promise to Pay' => 'info',
        'Archived' => 'muted', 'inactive' => 'muted', 'active' => 'ok', 'Awaiting Manager Review' => 'warn',
    ];
    $c = $map[$status] ?? 'info';
    return '<span class="badge b-' . $c . '">' . e($status) . '</span>';
}
