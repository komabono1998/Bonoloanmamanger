<?php
/** Front controller: HTTPS + headers, routing, authentication gate, permission checks and page layout. */
require __DIR__ . '/app/bootstrap.php';
require BASE_PATH . '/app/lib/ui.php';

// ---------------------------------------------------------------- transport security and headers
if (is_production() && !request_is_https()) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}
$GLOBALS['csp_nonce'] = rtrim(base64_encode(random_bytes(16)), '=');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $GLOBALS['csp_nonce'] . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
if (request_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// page => [file, permission ('a|b' = any of; null = any signed-in user), menu group, menu label]
$ROUTES = [
    'dashboard'     => ['dashboard', 'dashboard.view', 'Overview', 'Dashboard'],
    'inbox'         => ['inbox', 'loan.approve|payment.reverse|expense.approve|till.reconcile|eod.approve|backup.authorize', 'Overview', 'My Approvals'],
    'compliance'    => ['compliance', 'compliance.view', 'Overview', 'Branch Compliance'],
    'clients'       => ['clients', 'client.view', 'Lending', 'Clients'],
    'guarantors'    => ['guarantors', 'client.view', 'Lending', 'Guarantors'],
    'loans'         => ['loans', 'loan.view', 'Lending', 'Loan Applications'],
    'assessment'    => ['assessment', 'loan.assess', 'Lending', 'Credit Assessment'],
    'approvals'     => ['approvals', 'loan.approve', 'Lending', 'Loan Approval'],
    'disbursements' => ['disbursements', 'loan.disburse|loan.disburse.authorize', 'Lending', 'Loan Disbursement'],
    'loan'          => ['loan', 'loan.view', null, null],
    'securities'    => ['securities', 'loan.view', 'Lending', 'Securities'],
    'schedules'     => ['schedules', 'loan.view', 'Collections', 'Repayment Schedules'],
    'payments'      => ['payments', 'payment.view', 'Collections', 'Payments'],
    'receipts'      => ['receipts', 'payment.view', 'Collections', 'Receipts'],
    'portfolio'     => ['portfolio', 'portfolio.view', 'Collections', 'Loan Portfolio'],
    'arrears'       => ['arrears', 'portfolio.view', 'Collections', 'Arrears & Defaulters'],
    'till'          => ['till', 'till.use|till.reconcile', 'Cash', 'Cashier & Till'],
    'cashbook'      => ['cashbook', 'expense.view', 'Cash', 'Expenses & Cashbook'],
    'eod'           => ['eod', 'eod.view', 'Cash', 'End of Day'],
    'reports'       => ['reports', 'report.view', 'Reports', 'Report Centre'],
    'reportaccess'  => ['reportaccess', 'user.manage', 'Administration', 'Report Access'],
    'importexport'  => ['importexport', 'import.run|export.run', 'Reports', 'Import / Export'],
    'audit'         => ['audit', 'audit.own|audit.financial|audit.branch|audit.all|audit.technical', 'Administration', 'Audit Trail'],
    'users'         => ['users', 'user.manage', 'Administration', 'Users & Access'],
    'branches'      => ['branches', 'branch.manage', 'Administration', 'Branches'],
    'settings'      => ['settings', 'settings.manage|settings.policy|limits.manage|branch.settings', 'Administration', 'Settings & Limits'],
    'eodsys'        => ['eodsys', 'backup.manage|system.health', 'Administration', 'End of Day & System Date'],
    'backup'        => ['backup', 'backup.manage|backup.request|backup.authorize', 'Administration', 'Backup & Recovery'],
    'security'      => ['security', 'security.monitor', 'Administration', 'Security Monitoring'],
    'system'        => ['system', 'system.health|compliance.view', 'Administration', 'System Health'],
    'search'        => ['search', 'client.view|loan.view|payment.view', null, null],
    'account'       => ['account', null, null, null],
    'mfa'           => ['mfa', null, null, null],
    'print'         => ['print', null, null, null],
    'file'          => ['file', null, null, null],
    'export'        => ['export', null, null, null],
];

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'dashboard')) ?: 'dashboard';
$layout = 'app';
$title = '';
$content = '';

if ($page === 'logo') {   // public: the institution logo (login page, print-outs)
    $aid = (int)setting('business_logo');
    $att = $aid ? row('SELECT * FROM attachments WHERE id = ?', [$aid]) : null;
    if (!$att || !Storage::exists(basename($att['stored_name']))) { http_response_code(404); exit; }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $att['mime']); header('Cache-Control: public, max-age=86400'); header('X-Content-Type-Options: nosniff');
    Storage::stream(basename($att['stored_name']));
    exit;
}
if ($page === 'health') { require BASE_PATH . '/app/pages/health.php'; exit; }

try {
    if (!is_installed()) {
        $page = 'setup';
        $layout = 'bare';
    } else {
        migrate();                         // applies any new migration files on upgrade
        load_session_user();
        if (!user()) {
            if (in_array($page, ['reset', 'forgot', 'register'], true)) { $layout = 'bare'; }
            else {
                if ($page !== 'login') $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
                $page = 'login';
                $layout = 'bare';
            }
        } elseif ($page === 'logout') {
            logout();
            flash('ok', 'You have been signed out.');
            redirect(url('login'));
        } elseif (in_array($page, ['login', 'setup', 'reset', 'forgot', 'register'], true)) {
            redirect(url('dashboard'));
        } elseif ($page === 'switch_branch') {
            post_action(function () {
                set_branch_context((int)post('branch'));
                $back = post('back');
                return ['Now working in ' . branch_name(branch_id()) . '.', (str_starts_with($back, '/') && !str_starts_with($back, '//')) ? $back : url('dashboard')];
            });
            redirect(url('dashboard'));
        }
    }

    if (!in_array($page, ['login', 'setup', 'reset', 'forgot', 'register'], true)) {
        if (!isset($ROUTES[$page])) { http_response_code(404); throw new AppError('That page does not exist.'); }
        [$file, $perm] = $ROUTES[$page];
        if ($perm && !can_any($perm)) {
            try { audit('Access denied', 'Security', 'page', $page, null, 'requires ' . $perm); } catch (Throwable $e) {}
            http_response_code(403); throw new AppError('You do not have permission to perform this action.');
        }
        $pageFile = BASE_PATH . '/app/pages/' . $file . '.php';
        if (user() && !empty(user()['must_change_password']) && !in_array($page, ['account', 'file'], true)) redirect(url('account'));
        if (user() && !empty($_SESSION['mfa_enroll']) && !in_array($page, ['mfa', 'logout'], true)) {
            flash('warn', 'Multi-factor authentication is required for your role. Please set it up to continue.');
            redirect(url('mfa'));
        }
    } else {
        $pageFile = BASE_PATH . '/app/pages/' . $page . '.php';
    }

    ob_start();
    require $pageFile;
    $content = ob_get_clean();
} catch (AppError $e) {
    if (ob_get_level() > 0) ob_end_clean();
    $content = '<div class="card"><h2>Notice</h2><p>' . e($e->getMessage()) . '</p><p><a class="btn" href="' . e(url('dashboard')) . '">Back to dashboard</a></p></div>';
} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    error_log((string)$e);
    http_response_code(500);
    $content = '<div class="card"><h2>Something went wrong</h2><p>The request could not be completed. The problem has been logged. Please try again or contact the administrator.</p><p><a class="btn" href="' . e(url('dashboard')) . '">Back to dashboard</a></p></div>';
}

if ($layout === 'print') { echo $content; exit; }
require BASE_PATH . '/app/views/layout.php';
