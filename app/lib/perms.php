<?php
/**
 * Permission catalogue and default role profiles (SRS v3 section 3-4).
 * Principal roles: Loan Officer, Finance Officer, Branch Manager, COO, System Administrator.
 * "Cashier" is an optional operational profile for tellers (the spec lets a Finance Officer be granted collection rights instead).
 */

function permission_catalogue(): array
{
    return [
        'Dashboard' => ['dashboard.view' => 'View dashboard (scope follows the role)'],
        'Clients' => ['client.view' => 'View clients', 'client.manage' => 'Register / edit clients', 'client.sensitive' => 'View NIN and income details'],
        'Guarantors' => ['guarantor.manage' => 'Manage guarantors'],
        'Loans' => [
            'loan.view' => 'View loans and applications', 'loan.create' => 'Create / submit applications', 'loan.assess' => 'Record credit assessments',
            'loan.approve' => 'Approve / reject / return loans (within authority)', 'loan.disburse' => 'Execute disbursements (release funds)',
            'loan.disburse.authorize' => 'Authorize disbursements (within authority)', 'loan.close' => 'Close loans / approve waivers (within authority)',
            'loan.adjust' => 'Adjust approved terms / restructure (authorized)',
        ],
        'Payments' => [
            'payment.view' => 'View payments and receipts', 'payment.record' => 'Record payments (collections)', 'payment.reverse.request' => 'Request a payment reversal',
            'payment.reverse' => 'Approve payment reversals', 'receipt.reprint' => 'Reprint receipts', 'credit.manage' => 'Apply / refund customer credits',
        ],
        'Portfolio' => ['portfolio.view' => 'View loan portfolio', 'arrears.manage' => 'Record collection follow-ups', 'penalty.adjust' => 'Adjust / waive penalties (within authority)'],
        'Security' => ['security.manage' => 'Register collateral', 'security.release' => 'Release collateral'],
        'Cash' => [
            'till.use' => 'Open and use a cashier till', 'till.reconcile' => 'Reconcile cashier sessions', 'expense.view' => 'View expenses and cashbook',
            'expense.create' => 'Record expenses', 'expense.approve' => 'Approve expenses (within authority)',
        ],
        'End of Day' => [
            'eod.view' => 'View End-of-Day', 'eod.manage' => 'Open day / prepare and submit EOD', 'eod.approve' => 'Approve & lock the business day',
            'eod.reopen' => 'Request / authorize reopening',
        ],
        'Reports & data' => ['report.view' => 'View reports', 'report.export' => 'Export reports (CSV / Excel)', 'import.run' => 'Import data', 'export.run' => 'Export sensitive records'],
        'Oversight' => [
            'authority.branch' => 'Approval authority: Branch Manager level', 'authority.institution' => 'Approval authority: COO / institution level (escalations)',
            'branch.all' => 'Access all branches', 'compliance.view' => 'Institution compliance & EOD monitoring', 'limits.manage' => 'Configure approval limits',
            'settings.policy' => 'Approve operational / financial policy settings', 'branch.settings' => 'Edit own branch business-day settings',
        ],
        'Audit' => [
            'audit.own' => 'Audit trail: own activity', 'audit.financial' => 'Audit trail: financial scope', 'audit.branch' => 'Audit trail: branch scope',
            'audit.all' => 'Audit trail: institution-wide', 'audit.technical' => 'Audit trail: technical & security scope',
        ],
        'Administration' => [
            'user.manage' => 'Manage users, branch access and permissions', 'settings.manage' => 'Organization / technical settings', 'branch.manage' => 'Configure branches',
            'backup.request' => 'Request a backup', 'backup.authorize' => 'Authorize a recovery (restore)', 'backup.manage' => 'Execute and verify backups; execute authorized restore',
            'system.health' => 'View system health', 'security.monitor' => 'Review login activity and security events',
        ],
    ];
}

function all_permissions(): array
{
    $o = [];
    foreach (permission_catalogue() as $g) foreach ($g as $k => $_) $o[] = $k;
    return $o;
}

/** Permissions that move or approve money. A System Administrator may never hold these (separation of duties). */
function financial_authority_permissions(): array
{
    return ['loan.approve', 'loan.disburse', 'loan.disburse.authorize', 'loan.close', 'loan.adjust', 'loan.create', 'loan.assess', 'payment.record', 'payment.reverse',
        'payment.reverse.request', 'credit.manage', 'penalty.adjust', 'expense.approve', 'expense.create', 'till.use', 'till.reconcile', 'eod.approve', 'eod.manage', 'eod.reopen',
        'authority.branch', 'authority.institution', 'security.release'];
}

/** Technical permissions that only the System Administrator role may hold. */
function technical_permissions(): array
{
    return ['user.manage', 'settings.manage', 'branch.manage', 'backup.manage', 'system.health', 'security.monitor', 'audit.technical'];
}

function role_descriptions(): array
{
    return [
        'Loan Officer' => 'Client acquisition and registration, loan applications, guarantor documentation and credit assessment.',
        'Finance Officer' => 'Financial oversight, cashbook, collections review, reconciliation, reporting and End-of-Day preparation.',
        'Branch Manager' => 'Branch operations, delegated loan approval, financial oversight and End-of-Day authorization.',
        'COO' => 'Institution-wide oversight, escalated approvals and consolidated reporting.',
        'System Administrator' => 'User administration, technical configuration, security, backup and system health — no financial approval rights.',
        'Cashier' => 'Optional teller profile: receives payments, releases approved disbursements, runs a cash till.',
    ];
}

function principal_roles(): array
{
    return ['Loan Officer', 'Finance Officer', 'Branch Manager', 'COO', 'System Administrator'];
}

function default_role_permissions(): array
{
    return [
        'Loan Officer' => ['dashboard.view', 'client.view', 'client.manage', 'client.sensitive', 'guarantor.manage', 'loan.view', 'loan.create', 'loan.assess', 'portfolio.view', 'arrears.manage',
            'security.manage', 'payment.view', 'report.view', 'audit.own'],
        'Finance Officer' => ['dashboard.view', 'client.view', 'loan.view', 'payment.view', 'payment.reverse.request', 'receipt.reprint', 'portfolio.view', 'expense.view', 'expense.create',
            'till.reconcile', 'eod.view', 'eod.manage', 'eod.reopen', 'report.view', 'report.export', 'audit.financial'],
        'Branch Manager' => ['dashboard.view', 'client.view', 'client.manage', 'client.sensitive', 'guarantor.manage', 'loan.view', 'loan.approve', 'loan.disburse.authorize', 'loan.close', 'payment.view',
            'payment.reverse', 'receipt.reprint', 'credit.manage', 'portfolio.view', 'arrears.manage', 'penalty.adjust', 'security.manage', 'security.release', 'expense.view', 'expense.approve',
            'till.reconcile', 'eod.view', 'eod.approve', 'eod.reopen', 'report.view', 'report.export', 'authority.branch', 'branch.settings', 'backup.request', 'audit.branch', 'import.run', 'export.run'],
        'COO' => ['dashboard.view', 'client.view', 'client.sensitive', 'loan.view', 'loan.approve', 'loan.disburse.authorize', 'loan.close', 'loan.adjust', 'payment.view', 'payment.reverse',
            'portfolio.view', 'arrears.manage', 'penalty.adjust', 'expense.view', 'expense.approve', 'till.reconcile', 'eod.view', 'eod.approve', 'eod.reopen', 'report.view', 'report.export',
            'export.run', 'authority.institution', 'branch.all', 'compliance.view', 'limits.manage', 'settings.policy', 'backup.authorize', 'audit.all'],
        'System Administrator' => ['dashboard.view', 'user.manage', 'settings.manage', 'branch.manage', 'backup.manage', 'system.health', 'security.monitor', 'audit.technical'],
        'Cashier' => ['dashboard.view', 'client.view', 'loan.view', 'loan.disburse', 'payment.view', 'payment.record', 'receipt.reprint', 'portfolio.view', 'till.use', 'expense.view', 'expense.create', 'eod.view'],
    ];
}

/** Validate a proposed permission set against the mandatory security boundaries. Throws AppError. */
function assert_permission_boundaries(string $role, array $perms): void
{
    if ($role === 'System Administrator') {
        $bad = array_values(array_intersect($perms, financial_authority_permissions()));
        if ($bad) throw new AppError('Security boundary: a System Administrator cannot hold financial authority (' . implode(', ', $bad) . '). Technical administrators must not be able to bypass financial controls.');
    } else {
        $bad = array_values(array_intersect($perms, technical_permissions()));
        if ($bad) throw new AppError('Security boundary: technical permissions (' . implode(', ', $bad) . ') are reserved for the System Administrator role. The COO and business roles do not receive technical administrator rights.');
    }
    // Combinations such as creating AND approving a loan are allowed in small branches, but the services still
    // refuse self-approval, self-reconciliation and self-authorization at transaction time.
}

/** Reports a user may open: everything their role allows, minus any reports the administrator has switched off for that person. */
function report_denied_keys(?int $userId = null): array
{
    static $cache = [];
    $userId = $userId ?? (int)(uid() ?? 0);
    if (!isset($cache[$userId])) $cache[$userId] = array_column(rows('SELECT report_key FROM user_report_access WHERE user_id = ? AND allowed = 0', [$userId]), 'report_key');
    return $cache[$userId];
}

function report_allowed(string $key): bool
{
    return can('report.view') && !in_array($key, report_denied_keys(), true);
}
