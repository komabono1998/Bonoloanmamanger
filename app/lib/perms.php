<?php
/** Permission catalogue and default role assignments. */

function permission_catalogue(): array
{
    return [
        'Dashboard' => ['dashboard.view' => 'View dashboard'],
        'Clients' => ['client.view' => 'View clients', 'client.manage' => 'Register / edit clients', 'client.sensitive' => 'View NIN and income details'],
        'Guarantors' => ['guarantor.manage' => 'Manage guarantors'],
        'Loans' => [
            'loan.view' => 'View loans and applications', 'loan.create' => 'Create / submit applications',
            'loan.assess' => 'Record credit assessments', 'loan.approve' => 'Approve / reject loans',
            'loan.disburse' => 'Disburse loans', 'loan.close' => 'Close loans / approve waivers',
            'loan.adjust' => 'Adjust approved terms (authorized)',
        ],
        'Payments' => [
            'payment.view' => 'View payments and receipts', 'payment.record' => 'Record payments',
            'payment.reverse' => 'Reverse payments', 'receipt.reprint' => 'Reprint receipts',
            'credit.manage' => 'Apply / refund customer credits',
        ],
        'Portfolio' => [
            'portfolio.view' => 'View loan portfolio', 'arrears.manage' => 'Record collection follow-ups',
            'penalty.adjust' => 'Adjust / waive penalties',
        ],
        'Security' => ['security.manage' => 'Register collateral', 'security.release' => 'Release collateral'],
        'Cash' => [
            'till.use' => 'Open and use a cashier till', 'till.reconcile' => 'Reconcile cashier sessions',
            'expense.view' => 'View expenses and cashbook', 'expense.create' => 'Record expenses',
            'expense.approve' => 'Approve expenses',
        ],
        'End of Day' => [
            'eod.view' => 'View End-of-Day', 'eod.manage' => 'Open / submit business day',
            'eod.approve' => 'Approve & lock business day', 'eod.reopen' => 'Request / authorize reopening',
        ],
        'Reports & data' => [
            'report.view' => 'View reports', 'report.export' => 'Export reports (CSV / Excel)',
            'import.run' => 'Import data', 'export.run' => 'Export sensitive records',
        ],
        'Administration' => [
            'audit.view' => 'View audit trail', 'user.manage' => 'Manage users and permissions',
            'settings.manage' => 'Change system settings', 'backup.manage' => 'Backup and restore',
        ],
    ];
}

function all_permissions(): array
{
    $o = [];
    foreach (permission_catalogue() as $g) foreach ($g as $k => $_) $o[] = $k;
    return $o;
}

function role_descriptions(): array
{
    return [
        'Administrator' => 'System configuration, users, backups, recovery and permissions.',
        'Manager' => 'Loan approvals, portfolio oversight, EOD review and reconciliation approval.',
        'Loan Officer' => 'Clients, applications, guarantors, security and credit assessment.',
        'Cashier' => 'Receives payments, issues receipts, disburses, runs a cash till.',
        'Accountant' => 'Financial reports, expense review, cashbook and reconciliation.',
        'Auditor' => 'Read-only inspection of records and reports.',
    ];
}

function default_role_permissions(): array
{
    $all = all_permissions();
    $view = ['dashboard.view', 'client.view', 'client.sensitive', 'loan.view', 'payment.view', 'portfolio.view', 'expense.view',
        'eod.view', 'report.view', 'audit.view'];
    return [
        'Administrator' => $all,
        'Manager' => array_values(array_diff($all, ['user.manage', 'settings.manage'])),
        'Loan Officer' => ['dashboard.view', 'client.view', 'client.manage', 'client.sensitive', 'guarantor.manage', 'loan.view',
            'loan.create', 'loan.assess', 'portfolio.view', 'arrears.manage', 'security.manage', 'payment.view', 'report.view'],
        'Cashier' => ['dashboard.view', 'client.view', 'loan.view', 'loan.disburse', 'payment.view', 'payment.record', 'receipt.reprint',
            'portfolio.view', 'till.use', 'expense.view', 'expense.create', 'eod.view'],
        'Accountant' => ['dashboard.view', 'client.view', 'loan.view', 'payment.view', 'portfolio.view', 'expense.view', 'expense.create',
            'expense.approve', 'till.reconcile', 'eod.view', 'report.view', 'report.export', 'audit.view'],
        'Auditor' => $view,
    ];
}
