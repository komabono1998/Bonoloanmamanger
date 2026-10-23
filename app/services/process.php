<?php
/**
 * Who does what in the loan process. Each step of the process is a bundle of permissions; the System Administrator decides which
 * roles carry each step, at any time. Today the whole process runs between two roles: the Loan Officer (prepares) and the
 * Branch Manager (verifies, approves, authorises and releases the funds). The existing safeguards still apply whoever holds a step:
 * nobody can approve, authorise or verify their own work.
 */
class Process
{
    /** step => [label, who it is about, permissions it grants] */
    public const STEPS = [
        'clients' => ['Register & maintain clients', 'Client profile, KYC document upload', ['client.view', 'client.manage']],
        'guarantors' => ['Capture guarantors', 'Guarantor details, consent and signed forms', ['guarantor.manage']],
        'collateral' => ['Register collateral', 'Security details and valuation', ['security.manage']],
        'application' => ['Prepare & submit applications', 'Loan application, links to client, guarantors and security', ['loan.view', 'loan.create']],
        'assessment' => ['Credit assessment', 'Repayment capacity and recommended terms', ['loan.assess']],
        'verify' => ['Verify KYC, documents & guarantors', 'Verification before an application advances', ['kyc.verify']],
        'approve' => ['Approve, reject or return applications', 'Decision within the approval limit', ['loan.approve']],
        'authorise' => ['Authorise disbursement', 'Pre-disbursement authority', ['loan.disburse.authorize']],
        'disburse' => ['Release the funds (disburse)', 'Payment, voucher and loan account activation', ['loan.disburse', 'till.use']],
    ];
    public const ROLES = ['Loan Officer', 'Branch Manager', 'Finance Officer', 'Cashier', 'Finance Manager', 'COO'];

    public const PRESETS = [
        'two_role' => ['Two roles: Loan Officer + Branch Manager', [
            'Loan Officer' => ['clients', 'guarantors', 'collateral', 'application', 'assessment'],
            'Branch Manager' => ['clients', 'guarantors', 'collateral', 'verify', 'approve', 'authorise', 'disburse'],
            'COO' => ['verify', 'approve', 'authorise'],
        ]],
        'separate' => ['Separated duties: officer, finance, cashier and manager', [
            'Loan Officer' => ['clients', 'guarantors', 'collateral', 'application', 'assessment'],
            'Branch Manager' => ['clients', 'guarantors', 'collateral', 'verify', 'approve', 'authorise'],
            'Finance Officer' => ['verify'],
            'Finance Manager' => ['verify'],
            'Cashier' => ['disburse'],
            'COO' => ['verify', 'approve', 'authorise'],
        ]],
    ];

    /** [role => [step => bool]] as currently granted (a step is held when the role has every permission of the step). */
    public static function matrix(): array
    {
        $m = [];
        foreach (self::ROLES as $role) {
            $have = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission');
            foreach (self::STEPS as $k => [, , $perms]) $m[$role][$k] = !array_diff($perms, $have);
        }
        return $m;
    }

    /** Make the roles hold exactly the chosen steps (only the process permissions are touched). $assign = [role => [step, ...]]. */
    public static function apply(array $assign, bool $log = true): void
    {
        tx(function () use ($assign, $log) {
            $roles = array_column(rows('SELECT role FROM roles'), 'role');
            foreach (self::ROLES as $role) {
                if (!in_array($role, $roles, true)) continue;
                $want = array_values(array_intersect(array_keys(self::STEPS), $assign[$role] ?? []));
                $grant = []; foreach ($want as $k) foreach (self::STEPS[$k][2] as $p) $grant[$p] = true;
                $all = []; foreach (self::STEPS as [, , $perms]) foreach ($perms as $p) $all[$p] = true;
                foreach (array_keys($all) as $p) {
                    // 'loan.view', 'client.view' and 'till.use' are shared with other work; only revoke them when no other held step needs them
                    if (isset($grant[$p])) q('INSERT INTO role_permissions(role, permission) VALUES (?, ?) ON CONFLICT (role, permission) DO NOTHING', [$role, $p]);
                    elseif (!in_array($p, ['loan.view', 'client.view'], true) && !($p === 'till.use' && $role === 'Cashier')) q('DELETE FROM role_permissions WHERE role = ? AND permission = ?', [$role, $p]);
                }
            }
            $GLOBALS['__settings_dirty'] = true;
            unset($GLOBALS['__perms'], $GLOBALS['__branch_ids']);
            if ($log) audit('Loan process roles changed', 'Administration', 'process', 'roles', null, json_encode($assign));
        });
    }

    public static function applyPreset(string $key, bool $log = true): void
    {
        must(isset(self::PRESETS[$key]), 'Unknown process profile.');
        self::apply(self::PRESETS[$key][1], $log);
        set_setting('process_profile', $key, null, false);
    }

    /** Steps nobody holds (the process would stall there). */
    public static function uncovered(): array
    {
        $m = self::matrix(); $out = [];
        foreach (self::STEPS as $k => [$label]) {
            $any = false; foreach (self::ROLES as $r) if (!empty($m[$r][$k])) $any = true;
            if (!$any) $out[] = $label;
        }
        return $out;
    }
}
