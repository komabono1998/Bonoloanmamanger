<?php
/** Front controller: routing, authentication gate, permission checks and page layout. */
require __DIR__ . '/app/bootstrap.php';
require BASE_PATH . '/app/lib/ui.php';

// page => [file, permission (null = any signed-in user), menu group, menu label]
$ROUTES = [
    'dashboard'     => ['dashboard', 'dashboard.view', 'Overview', 'Dashboard'],
    'clients'       => ['clients', 'client.view', 'Lending', 'Clients'],
    'guarantors'    => ['guarantors', 'client.view', 'Lending', 'Guarantors'],
    'loans'         => ['loans', 'loan.view', 'Lending', 'Loan Applications'],
    'assessment'    => ['assessment', 'loan.assess', 'Lending', 'Credit Assessment'],
    'approvals'     => ['approvals', 'loan.approve', 'Lending', 'Loan Approval'],
    'disbursements' => ['disbursements', 'loan.disburse', 'Lending', 'Loan Disbursement'],
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
    'reports'       => ['reports', 'report.view', 'Reports', 'Reports'],
    'importexport'  => ['importexport', 'import.run', 'Reports', 'Import / Export'],
    'audit'         => ['audit', 'audit.view', 'Administration', 'Audit Trail'],
    'users'         => ['users', 'user.manage', 'Administration', 'Users & Permissions'],
    'settings'      => ['settings', 'settings.manage', 'Administration', 'Settings'],
    'backup'        => ['backup', 'backup.manage', 'Administration', 'Backup & Restore'],
    'account'       => ['account', null, null, null],
    'print'         => ['print', null, null, null],
    'file'          => ['file', null, null, null],
    'export'        => ['export', null, null, null],
];

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'dashboard')) ?: 'dashboard';
$layout = 'app';
$title = '';
$content = '';

try {
    if (!is_installed()) {
        $page = 'setup';
        $layout = 'bare';
    } else {
        migrate();                         // applies any new migration files on upgrade
        load_session_user();
        if (!user()) {
            if (!in_array($page, ['login'], true)) {
                $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            }
            $page = 'login';
            $layout = 'bare';
        } elseif ($page === 'logout') {
            logout();
            flash('ok', 'You have been signed out.');
            redirect(url('login'));
        } elseif ($page === 'login' || $page === 'setup') {
            redirect(url('dashboard'));
        }
    }

    if ($page !== 'login' && $page !== 'setup') {
        if (!isset($ROUTES[$page])) { http_response_code(404); throw new AppError('That page does not exist.'); }
        [$file, $perm] = $ROUTES[$page];
        if ($perm && !can_any($perm)) { http_response_code(403); throw new AppError('You do not have permission to perform this action.'); }
        $pageFile = BASE_PATH . '/app/pages/' . $file . '.php';
        if (user() && !empty(user()['must_change_password']) && $page !== 'account') redirect(url('account'));
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
