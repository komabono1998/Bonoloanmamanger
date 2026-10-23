<?php
/** System settings. Financial settings are only ever read at the moment a loan is approved (snapshot). */

function setting_defaults(): array
{
    return [
        // business identity
        'business_name' => 'Bono Loan Manager',
        'business_address' => '',
        'business_phone' => '',
        'business_email' => '',
        'business_logo' => '',
        'business_tagline' => '',
        'business_city' => '',
        'business_country' => 'Uganda',
        'business_phone2' => '',
        'business_website' => '',
        'registration_no' => '',
        'tax_id' => '',
        'institution_code' => '',
        'brand_color' => '#0b5c4d',
        'staff_prefix' => 'S',
        'setup_lending_confirmed' => '0',
        'admin_manages_policy' => '1',
        'smtp_host' => '', 'smtp_port' => '587', 'smtp_security' => 'tls', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from_email' => '', 'mail_from_name' => '',
        'currency' => 'UGX',
        // numbering
        'loan_prefix' => 'BLM',
        'client_prefix' => 'CL',
        'receipt_prefix' => 'RC',
        'guarantor_prefix' => 'GR',
        'security_prefix' => 'SC',
        // lending terms (initial values from the SRS)
        'interest_rate_pct' => '20',
        'interest_method' => 'flat',
        'loan_period_months' => '1',
        'processing_fee_pct' => '3',
        'application_fee' => '10000',
        'inspection_fee_min' => '5000',
        'inspection_fee_max' => '20000',
        'inspection_fee_default' => '10000',
        'fees_deducted' => '1',
        'repayment_frequency' => 'monthly',
        'freq_mode' => 'collection_method',   // collection_method | installments
        'maturity_basis' => 'disbursement',   // disbursement | approval
        'maturity_holiday_roll' => '0',       // 1 = roll maturity forward to next working day
        'offer_validity_days' => '20',
        'assessment_required' => '1',
        'requested_max_loan' => '0',          // 0 = no ceiling
        // penalties: DISABLED until management confirms the rule (SRS 5.5 / 23.4)
        'penalty_enabled' => '0',
        'penalty_rate_pct' => '3.5',
        'penalty_basis' => 'outstanding_principal', // outstanding_principal | original_principal | total_outstanding
        'penalty_compound' => '0',
        'penalty_cap_pct' => '0',             // % of approved principal; 0 = no cap
        'penalty_grace_days' => '0',
        'default_after_days' => '30',
        // payments
        'alloc_order' => 'penalty,fee,interest,principal',
        'phone_duplicate_policy' => 'warn',   // warn | block | allow
        // calendar / EOD
        'working_days' => '1,2,3,4,5,6',      // ISO weekdays 1=Mon .. 7=Sun
        'eod_close_time' => '18:00',
        'require_reconciliation' => '1',
        'variance_tolerance' => '0',
        'expense_approval_limit' => '100000',
        // security
        'session_timeout_min' => '15',
        'password_min_length' => '10',
        'password_max_age_days' => '0',
        'mfa_required_roles' => 'System Administrator,COO,Branch Manager',
        'reversal_independent_approval' => '1',
        'reopen_authority' => 'branch_manager',   // branch_manager | coo
        'restore_requires_authorization' => '1',
        'rto_hours' => '',
        'rpo_hours' => '',
        // backups
        'backup_dir' => 'storage/backups',
        'backup_auto_hours' => '24',
        'backup_keep' => '60',
        // documents / reports
        'receipt_footer' => 'Thank you for your payment. Keep this receipt for your records.',
        'receipt_paper' => 'A4',
        'report_default_days' => '30',
    ];
}

function setting(string $key, ?string $default = null): string
{
    static $cache = null;
    if ($cache === null || isset($GLOBALS['__settings_dirty'])) {
        $cache = [];
        try {
            foreach (rows('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
        } catch (Throwable $e) { /* not installed yet */ }
        unset($GLOBALS['__settings_dirty']);
    }
    if (array_key_exists($key, $cache)) return $cache[$key];
    $d = setting_defaults();
    return $default ?? ($d[$key] ?? '');
}

function setting_int(string $key): int { return (int)setting($key); }

/** Percent string (e.g. "3.5") to integer basis points (350). */
function pct_to_bp($pct): int
{
    return (int)round(((float)str_replace(',', '', (string)$pct)) * 100);
}

function bp_to_pct(int $bp): string
{
    $v = $bp / 100;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
}

function set_setting(string $key, string $value, ?int $userId = null, bool $log = true): void
{
    $old = row('SELECT value FROM settings WHERE key = ?', [$key]);
    $oldVal = $old['value'] ?? (setting_defaults()[$key] ?? null);
    if ($old && $old['value'] === $value) return;
    q('INSERT INTO settings(key, value, updated_at, updated_by) VALUES (?,?,?,?)
       ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at, updated_by=excluded.updated_by',
        [$key, $value, now(), $userId]);
    $GLOBALS['__settings_dirty'] = true;
    if ($log) audit('Setting changed', 'Settings', 'setting', $key, $oldVal, $value);
}

function business_name(): string { return setting('business_name'); }

/** May the System Administrator configure lending policy and approval limits? (Institution choice; always true until a COO exists.) */
function policy_bootstrap_open(): bool
{
    return can('settings.manage') && (setting('admin_manages_policy') === '1' || !(int)val("SELECT COUNT(*) FROM users WHERE role = 'COO' AND status = 'active'"));
}
