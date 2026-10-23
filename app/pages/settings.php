<?php
$title = 'Settings & limits';
$a = get('a');

/** Lending / penalty / payment / EOD policy belongs to the COO. Until a COO exists the System Administrator may do the initial configuration. */
function group_allowed(string $perm): bool
{
    if (str_contains($perm, 'settings.policy') && policy_bootstrap_open()) return true;
    return can_any($perm);
}

/** tab => [label, required permission(s) 'a|b', fields key => [label, type, options|help]] */
$GROUPS = [
    'business' => ['Institution profile', 'settings.manage', [
        'business_name' => ['Institution name', 'text'], 'business_tagline' => ['Tagline (optional)', 'text', 'Shown under the name on documents.'],
        'institution_code' => ['Institution code', 'text', 'Short code used in staff IDs, e.g. EMG-S-0001 (2–6 letters/numbers).'], 'registration_no' => ['Registration number', 'text'], 'tax_id' => ['Tax ID (TIN)', 'text'],
        'business_address' => ['Street / address', 'text'], 'business_city' => ['Town / district', 'text'], 'business_country' => ['Country', 'text'],
        'business_phone' => ['Telephone', 'text'], 'business_phone2' => ['Alternative telephone', 'text'], 'business_email' => ['Email', 'text'], 'business_website' => ['Website', 'text'],
        'brand_color' => ['Brand colour', 'color', 'Colours the whole interface (menu, buttons, charts) and the letterhead line on documents.'],
        'currency' => ['Currency', 'text', 'Display label only (amounts are whole UGX).'], 'receipt_footer' => ['Receipt / document footer', 'text'],
        'receipt_paper' => ['Receipt paper size', 'select', ['A4' => 'A4', '80mm' => '80 mm roll']],
    ]],
    'numbering' => ['Numbering', 'settings.manage', [
        'loan_prefix' => ['Loan number prefix', 'text', 'e.g. BLM → BLM-2026-000001'], 'client_prefix' => ['Client number prefix', 'text'], 'receipt_prefix' => ['Receipt number prefix', 'text'],
        'staff_prefix' => ['Staff ID letter(s)', 'text', 'Staff IDs look like CODE-S-0001.'], 'guarantor_prefix' => ['Guarantor prefix', 'text'], 'security_prefix' => ['Security prefix', 'text'],
    ]],
    'lending' => ['Lending terms', 'settings.policy', [
        'interest_rate_pct' => ['Interest rate (% per month)', 'text', 'Applies to loans approved from now on; approved loans keep their own rate.'], 'interest_method' => ['Interest calculation', 'select', ['flat' => 'Flat (principal × rate × months)']],
        'loan_period_months' => ['Standard loan period (months)', 'number'], 'processing_fee_pct' => ['Processing fee (% of approved principal)', 'text'], 'application_fee' => ['Application fee (UGX)', 'money'],
        'inspection_fee_min' => ['Inspection fee minimum (UGX)', 'money'], 'inspection_fee_max' => ['Inspection fee maximum (UGX)', 'money'], 'inspection_fee_default' => ['Default inspection fee (UGX)', 'money'],
        'fees_deducted' => ['Fees are deducted from principal at disbursement', 'checkbox', 'Untick if fees are normally paid separately by the client.'],
        'repayment_frequency' => ['Default repayment frequency', 'select', ['monthly' => 'Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily']],
        'freq_mode' => ['Weekly / daily frequency means', 'select', ['collection_method' => 'Collection method only (single instalment at maturity)', 'installments' => 'Multiple instalments within the term']],
        'maturity_basis' => ['Maturity date counted from', 'select', ['disbursement' => 'Disbursement date', 'approval' => 'Approval date']],
        'maturity_holiday_roll' => ['Roll maturity forward to next working day', 'checkbox'], 'offer_validity_days' => ['Offer validity (working days)', 'number'],
        'assessment_required' => ['Credit assessment required before approval', 'checkbox'], 'requested_max_loan' => ['Maximum loan amount (UGX, 0 = none)', 'money'],
    ]],
    'penalty' => ['Penalties', 'settings.policy', [
        'penalty_enabled' => ['Penalties ACTIVE for newly approved loans', 'checkbox', 'Leave OFF until management confirms the rule.'], 'penalty_rate_pct' => ['Penalty rate (% per day)', 'text', 'Initial documented value: 3.5% per day — to be confirmed.'],
        'penalty_basis' => ['Rate applies to', 'select', ['outstanding_principal' => 'Outstanding principal', 'original_principal' => 'Original approved principal', 'total_outstanding' => 'Principal + interest + fees outstanding']],
        'penalty_compound' => ['Compound (include accrued penalties in the basis)', 'checkbox'], 'penalty_cap_pct' => ['Cap: max total penalty as % of principal (0 = none)', 'text'],
        'penalty_grace_days' => ['Grace days before penalties start', 'number'], 'default_after_days' => ['Classify as "defaulted" after (days overdue)', 'number'],
    ]],
    'payments' => ['Payments & clients', 'settings.policy', [
        'alloc_order' => ['Payment allocation priority', 'select', ['penalty,fee,interest,principal' => 'Penalty → Fees → Interest → Principal', 'fee,interest,principal,penalty' => 'Fees → Interest → Principal → Penalty',
            'interest,principal,penalty,fee' => 'Interest → Principal → Penalty → Fees', 'principal,interest,penalty,fee' => 'Principal → Interest → Penalty → Fees', 'interest,penalty,fee,principal' => 'Interest → Penalty → Fees → Principal']],
        'reversal_independent_approval' => ['Payment reversals need an independent approver', 'checkbox', 'Recommended ON: the requester can never approve their own reversal.'],
        'phone_duplicate_policy' => ['Duplicate phone numbers', 'select', ['warn' => 'Warn (allow shared phones after confirmation)', 'block' => 'Block', 'allow' => 'Allow silently']],
    ]],
    'eod' => ['Cash & End of Day', 'settings.policy', [
        'working_days' => ['Working days (1=Mon … 7=Sun, comma separated)', 'text', 'Institution default; each branch can override it.'], 'eod_close_time' => ['Normal closing time', 'time', 'Informational. Late items post to the open business date; the date never changes silently.'],
        'require_reconciliation' => ['Every till must be reconciled before EOD', 'checkbox'], 'expense_approval_limit' => ['Routine expense limit (UGX): above this needs approval', 'money'], 'collection_target_monthly' => ['Monthly collection target (UGX, 0 = none)', 'money', 'Shown as a dashed line on the Collections trend chart.'],
        'reopen_authority' => ['Reopening a closed business date is authorized by', 'select', ['branch_manager' => 'Branch Manager', 'coo' => 'COO']],
    ]],
    'email' => ['Email', 'settings.manage', [
        'smtp_host' => ['Mail server (SMTP host)', 'text', 'e.g. smtp.gmail.com or your provider. Leave empty to keep emails in a log file only.'], 'smtp_port' => ['Port', 'number', '587 (STARTTLS) or 465 (SSL).'],
        'smtp_security' => ['Connection security', 'select', ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (not recommended)']],
        'smtp_user' => ['SMTP username', 'text'], 'smtp_pass' => ['SMTP password', 'password', 'Stored encrypted. Leave blank to keep the current one. For Gmail use an app password.'],
        'mail_from_email' => ['Send emails from', 'text', 'Defaults to the institution email.'], 'mail_from_name' => ['Sender name', 'text', 'Defaults to the institution name.'],
    ]],
    'security' => ['Security & recovery', 'settings.manage', [
        'session_timeout_min' => ['Session timeout (minutes of inactivity)', 'number'], 'password_min_length' => ['Minimum password length', 'number'], 'password_max_age_days' => ['Passwords expire after (days, 0 = never)', 'number'],
        'mfa_required_roles' => ['Roles that must use multi-factor authentication', 'roles', 'System Administrator is always included.'],
        'backup_dir' => ['Backup folder', 'text', 'Relative to the app folder, or an absolute path on a separate volume. (BACKUP_DIR in the environment overrides this.)'],
        'backup_auto_hours' => ['Automatic backup if the last one is older than (hours, 0 = off)', 'number'], 'backup_keep' => ['Backups to keep', 'number'],
        'restore_requires_authorization' => ['A restore needs COO authorization first', 'checkbox', 'Recommended ON (controlled recovery).'],
        'admin_manages_policy' => ['Administrator may also configure interest rates, fees, penalties and approval limits', 'checkbox', 'ON: the administrator configures everything here. OFF: once a COO exists, only the COO can change financial policy (strict separation of duties).'],
        'rto_hours' => ['Recovery time objective (hours) — set by the institution', 'number'], 'rpo_hours' => ['Recovery point objective (hours of acceptable data loss)', 'number'],
        'report_default_days' => ['Default report period (days)', 'number'],
    ]],
];
$tabs = [];
foreach ($GROUPS as $k => [$label, $perm]) if (group_allowed($perm)) $tabs[$k] = $label;
if (can('limits.manage') || policy_bootstrap_open()) $tabs['limits'] = 'Approval limits';
if (can_any('settings.manage|settings.policy|branch.settings')) $tabs['calendar'] = 'Business calendar';
if (can('branch.settings')) $tabs['mybranch'] = 'My branch';
must((bool)$tabs, 'You do not have permission to perform this action.');
$tab = get('tab', 'hub');
if ($tab !== 'hub' && !isset($tabs[$tab])) $tab = 'hub';

if ($a === 'save') post_action(function () use ($GROUPS, $tab) {
    $g = $GROUPS[$tab] ?? null;
    must((bool)$g, 'Unknown settings group.');
    must(group_allowed($g[1]), 'You do not have permission to perform this action.');
    tx(function () use ($g) {
        foreach ($g[2] as $key => $def) {
            $type = $def[1];
            if ($key === 'smtp_pass') {
                $pw = (string)($_POST[$key] ?? '');
                if ($pw !== '') set_setting($key, encrypt_secret($pw), uid(), false);
                continue;
            }
            if ($type === 'checkbox') $v = !empty($_POST[$key]) ? '1' : '0';
            elseif ($type === 'roles') {
                $sel = array_values(array_intersect((array)($_POST[$key] ?? []), array_column(rows('SELECT role FROM roles'), 'role')));
                if (!in_array('System Administrator', $sel, true)) $sel[] = 'System Administrator';
                $v = implode(',', $sel);
            } else {
                $v = trim((string)($_POST[$key] ?? ''));
                if ($type === 'money') $v = (string)parse_money($v, $def[0], true);
                elseif ($type === 'number') { must(ctype_digit($v) || ($v === '' && in_array($key, ['rto_hours', 'rpo_hours'], true)), $def[0] . ' must be a whole number.'); }
                elseif (str_ends_with($key, '_pct')) { must(is_numeric($v) && (float)$v >= 0 && (float)$v <= 1000, $def[0] . ' must be a percentage.'); }
                elseif ($type === 'select') { must(array_key_exists($v, $def[2]), $def[0] . ': invalid choice.'); }
                elseif (str_ends_with($key, '_prefix')) { $v = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $v)); must($v !== '', $def[0] . ' is required.'); }
            }
            if ($key === 'inspection_fee_max') must((int)$v >= (int)(($_POST['inspection_fee_min'] ?? '') !== '' ? parse_money($_POST['inspection_fee_min'], 'x', true) : 0), 'Inspection fee maximum must be at least the minimum.');
            if ($key === 'working_days') { $v = implode(',', array_filter(array_map('trim', explode(',', $v)), fn($d) => preg_match('/^[1-7]$/', $d))); must($v !== '', 'Choose at least one working day.'); }
            if ($key === 'institution_code') { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v)); must(strlen($v) >= 2 && strlen($v) <= 6, 'Institution code must be 2-6 letters or numbers.'); }
            if ($key === 'brand_color') must(preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1, 'Brand colour must look like #0B5C4D.');
            if ($key === 'business_email') must($v === '' || filter_var($v, FILTER_VALIDATE_EMAIL), 'Enter a valid email address.');
            if ($key === 'business_website') $v = preg_replace('~^https?://~i', '', $v);
            if ($key === 'business_name') must($v !== '', 'Institution name is required.');
            if ($key === 'session_timeout_min') must((int)$v >= 1 && (int)$v <= 480, 'Session timeout must be between 1 and 480 minutes.');
            if ($key === 'password_min_length') must((int)$v >= 8 && (int)$v <= 64, 'Minimum password length must be between 8 and 64.');
            set_setting($key, $v, uid());
        }
    });
    if ($tab === 'lending') set_setting('setup_lending_confirmed', '1', uid());
    return 'Settings saved. Existing approved loans keep their original terms.';
});
if ($a === 'testmail') post_action(function () {
    require_perm('settings.manage');
    $to = clean(post('to')) ?: (string)(user()['email'] ?? '');
    must($to !== '', 'Enter an address to send the test to.');
    $sent = send_mail($to, 'Test email from ' . business_name(), mail_template('Email is working', '<p>This is a test message sent from the settings page.</p>'));
    return $sent ? 'Test email sent to ' . $to . '.' : 'No mail server is configured, so the message was only written to storage/logs/mail.log.';
});
if ($a === 'limits') post_action(function () {
    must(can('limits.manage') || policy_bootstrap_open(), 'You do not have permission to perform this action.');
    tx(function () {
        foreach (Approvals::CATEGORIES as $cat => $_) {
            foreach ((array)($_POST['limit'][$cat] ?? []) as $bid => $raw) {
                $bid = (int)$bid;
                $raw = trim((string)$raw);
                $cur = val('SELECT max_amount FROM approval_limits WHERE category = ? AND branch_id = ?', [$cat, $bid]);
                $new = $raw === '' ? null : parse_money($raw, 'Limit', true);
                if (($cur === null ? null : (int)$cur) !== $new) {
                    if ($new === null) { q('DELETE FROM approval_limits WHERE category = ? AND branch_id = ?', [$cat, $bid]); audit('Approval limit cleared', 'Settings', 'approval_limit', $cat . '@' . $bid, $cur, 'not set'); }
                    else Approvals::setLimit($cat, $bid, $new);
                }
            }
        }
    });
    return 'Approval limits saved.';
});
if ($a === 'holiday') post_action(function () {
    $bid = (int)post('branch_id');
    must($bid === 0 ? can_any('settings.manage|settings.policy') : (can('branch.settings') && $bid === branch_id()) || can_any('settings.manage|settings.policy'), 'You do not have permission to perform this action.');
    if ($bid) assert_branch($bid);
    $d = must_date(post('date'), 'Date');
    $working = post('is_working') ? 1 : 0;
    q('INSERT INTO business_calendar(branch_id, date, is_working, note) VALUES (?,?,?,?) ON CONFLICT (branch_id, date) DO UPDATE SET is_working = excluded.is_working, note = excluded.note', [$bid, $d, $working, clean(post('note')) ?: null]);
    audit('Business calendar changed', 'Settings', 'calendar', $d . '@' . $bid, null, $working ? 'working day' : 'holiday', clean(post('note')));
    return ['Calendar updated.', url('settings', ['tab' => 'calendar'])];
});
if ($a === 'delholiday') post_action(function () {
    $bid = (int)post('branch_id');
    must($bid === 0 ? can_any('settings.manage|settings.policy') : (can('branch.settings') && $bid === branch_id()) || can_any('settings.manage|settings.policy'), 'You do not have permission to perform this action.');
    q('DELETE FROM business_calendar WHERE branch_id = ? AND date = ?', [$bid, post('date')]);
    audit('Business calendar entry removed', 'Settings', 'calendar', post('date') . '@' . $bid);
    return ['Entry removed.', url('settings', ['tab' => 'calendar'])];
});
if ($a === 'mybranch') post_action(function () {
    require_perm('branch.settings');
    $b = branch_row(branch_id());
    must((bool)$b, 'You are not assigned to a branch.');
    $days = implode(',', array_filter(array_map('trim', explode(',', post('working_days'))), fn($d) => preg_match('/^[1-7]$/', $d)));
    $time = post('close_time');
    must($time === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1, 'Closing time must look like 18:00.');
    update('branches', ['working_days' => $days ?: null, 'close_time' => $time ?: null], 'id = :id', ['id' => $b['id']]);
    audit('Branch business-day settings changed', 'Branches', 'branch', $b['id'], ['days' => $b['working_days'], 'close' => $b['close_time']], ['days' => $days, 'close' => $time]);
    branches_changed();
    return 'Branch settings saved.';
});
if ($a === 'logo') post_action(function () {
    require_perm('settings.manage');
    $att = save_upload('logo', 'business', 1, 'logo');
    must((bool)$att, 'Choose an image to upload.');
    set_setting('business_logo', (string)$att, uid());
    return ['Logo updated.', url('settings', ['tab' => 'business'])];
});

page_head('Settings & limits', $tab !== 'hub' ? '<a class="btn" href="' . e(url('settings')) . '">‹ All settings</a>' : '', 'Changes are logged and apply to new loans only — they never rewrite the terms of loans already approved.');
if ($tab === 'hub') {
    $HUB = [
        'business' => ['building', 'Institution profile', 'Name, address, contacts, registration & tax numbers, logo and colour — printed on every receipt, report, form and statement.'],
        'numbering' => ['hash', 'IDs & numbering', 'Prefixes for client, loan, receipt, guarantor and staff IDs.'],
        'lending' => ['percent', 'Interest & fees', 'Interest rate, processing / application / inspection fees, loan period, maturity and offer rules.'],
        'penalty' => ['alert', 'Penalties', 'Overdue penalty rate, basis, grace days and cap.'],
        'payments' => ['card', 'Payments & clients', 'Payment allocation order, reversal approval, duplicate phone policy.'],
        'eod' => ['clock', 'Cash & End of Day', 'Working days, closing rules, expense limit, reopening authority.'],
        'email' => ['mail', 'Email', 'Mail server used for password reset and invitation emails; send a test message.'],
        'security' => ['lock', 'Security & recovery', 'Sessions, passwords, MFA roles, backups, RTO / RPO.'],
        'limits' => ['check', 'Approval limits', 'Who can approve how much; anything above goes to the COO.'],
        'calendar' => ['calendar', 'Business calendar', 'Holidays and special working days.'],
        'mybranch' => ['building', 'My branch', 'Working days and closing time for your branch.'],
    ];
    $unconf = Approvals::unconfigured();
    $pg = profile_gaps();
    $status = [
        'business' => $pg ? 'Missing: ' . implode(', ', $pg) : 'Complete',
        'lending' => setting('interest_rate_pct') . '% per month · processing ' . setting('processing_fee_pct') . '% · application ' . number_format(setting_int('application_fee')),
        'penalty' => setting('penalty_enabled') === '1' ? 'Active · ' . setting('penalty_rate_pct') . '% per day' : 'Off (awaiting management decision)',
        'limits' => $unconf ? count($unconf) . ' of ' . count(Approvals::CATEGORIES) . ' not configured' : 'Configured',
        'security' => 'Timeout ' . setting('session_timeout_min') . ' min',
        'numbering' => 'e.g. ' . setting('loan_prefix') . '-' . date('Y') . '-000001 · ' . institution_code() . '-' . setting('staff_prefix') . '-0001',
    ];
    if (can('settings.manage')) {
        $list = System::setupChecklist();
        $done = count(array_filter($list, fn($s) => $s[1]));
        echo '<div class="card"><div class="card-h"><h2>Set-up checklist</h2><span class="muted">' . $done . ' of ' . count($list) . ' complete</span></div><div class="steps">';
        foreach ($list as [$label, $ok, $hint, $link, $ic]) {
            echo '<a class="step' . ($ok ? ' done' : '') . '" href="' . e(url($link[0], $link[1])) . '"><span class="step-ic">' . icon($ok ? 'check' : $ic, 16) . '</span><span><b>' . e($label) . '</b><small>' . e($hint) . '</small></span></a>';
        }
        echo '</div></div>';
    }
    if (policy_bootstrap_open() && !can('settings.policy')) echo '<div class="notice info">As administrator you can configure interest rates, fees, penalties and approval limits. Every change is recorded in the audit trail. You can restrict this to the COO under Security &amp; recovery.</div>';
    echo '<div class="hub">';
    foreach ($HUB as $k => [$ic, $name, $desc]) {
        if (!isset($tabs[$k])) continue;
        echo '<a class="hub-card" href="' . e(url('settings', ['tab' => $k])) . '"><span class="hub-ic">' . icon($ic, 22) . '</span><span class="hub-body"><b>' . e($name) . '</b><small>' . e($desc) . '</small>'
            . (isset($status[$k]) ? '<em>' . e($status[$k]) . '</em>' : '') . '</span></a>';
    }
    echo '</div>';
    return;
}
tabs($tabs, $tab);

if ($tab === 'limits') {
    echo '<div class="notice info">Approval limits are set by the institution. <b>The system does not invent any amount.</b> A blank limit means “not configured”: nothing is escalated automatically for that category. Above the Branch Manager limit, approval goes to the COO.</div>';
    echo '<div class="card"><h2>Branch Manager limits (UGX)</h2>';
    form_open(url('settings', ['a' => 'limits', 'tab' => 'limits']), ['attrs' => 'style="display:block"']);
    echo '<div class="tbl-wrap"><table class="tbl nofilter"><thead><tr><th>Category</th><th class="r">Institution default</th>';
    foreach (all_branch_options(false) as $bid => $bn) echo '<th class="r">' . e($bn) . ' <small class="muted">(override)</small></th>';
    echo '</tr></thead><tbody>';
    foreach (Approvals::CATEGORIES as $cat => $label) {
        echo '<tr><td>' . e($label) . '</td>';
        foreach ([0] + array_keys(all_branch_options(false)) as $bid) {
            $v = val('SELECT max_amount FROM approval_limits WHERE category = ? AND branch_id = ?', [$cat, $bid]);
            echo '<td class="r"><input type="text" class="money" style="width:130px;text-align:right" name="limit[' . e($cat) . '][' . (int)$bid . ']" value="' . ($v === null ? '' : e(number_format((int)$v))) . '" placeholder="not set"></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div><p class="muted">Loan / disbursement limits compare against the principal; expense, waiver and reversal limits against the amount; the End-of-Day variance limit against the total absolute cash variance of the day.</p>';
    echo '<div class="form-actions" style="display:flex;margin-top:.6rem">'; submit_btn('Save limits'); echo '</div>'; form_close(); echo '</div>';
    return;
}

if ($tab === 'calendar') {
    $mine = branch_id();
    $cal = rows('SELECT c.*, b.name AS branch FROM business_calendar c LEFT JOIN branches b ON b.id = c.branch_id ORDER BY c.date DESC LIMIT 150');
    $cal = array_values(array_filter($cal, fn($r) => (int)$r['branch_id'] === 0 || in_array((int)$r['branch_id'], accessible_branch_ids(), true)));
    echo '<div class="grid g2"><div class="card"><h2>Holidays & special working days</h2>';
    data_table(['date' => ['Date', 'date'], 'branch' => ['Applies to', fn($r) => e($r['branch'] ?: 'All branches')], 'is_working' => ['Type', fn($r) => $r['is_working'] ? 'working day' : 'Holiday / closed'], 'note' => 'Note',
        'x' => ['', fn($r) => '<form method="post" action="' . e(url('settings', ['a' => 'delholiday'])) . '" data-confirm="Remove this entry?">' . csrf_field() . '<input type="hidden" name="date" value="' . e($r['date']) . '"><input type="hidden" name="branch_id" value="' . (int)$r['branch_id'] . '"><button class="btn sm">Remove</button></form>']], $cal, ['class' => 'nofilter', 'empty' => 'No holidays defined.']);
    echo '</div><div class="card"><h2>Add / change a date</h2>';
    $opts = can_any('settings.manage|settings.policy') ? [0 => 'All branches (institution)'] + branch_options() : [$mine => branch_name($mine)];
    form_open(url('settings', ['a' => 'holiday']), ['attrs' => 'style="grid-template-columns:1fr"']);
    field('branch_id', 'Applies to', ['type' => 'select', 'options' => $opts]);
    field('date', 'Date', ['type' => 'date', 'required' => true]); field('note', 'Description (e.g. Independence Day)');
    field('is_working', 'This date is a WORKING day (untick for a holiday)', ['type' => 'checkbox']);
    echo '<div class="form-actions">'; submit_btn('Save date'); echo '</div>'; form_close();
    echo '<p class="muted">Used for offer expiry (working days), maturity rolling and the business-day opening check.</p></div></div>';
    return;
}

if ($tab === 'mybranch') {
    $b = branch_row(branch_id());
    echo '<div class="card"><h2>' . e($b['name'] ?? '') . ' — business-day settings</h2><p class="muted">Limited operational settings for your own branch. Financial policy is set by the COO.</p>';
    form_open(url('settings', ['a' => 'mybranch', 'tab' => 'mybranch']));
    field('working_days', 'Working days (1=Mon … 7=Sun)', ['value' => $b['working_days'] ?? '', 'help' => 'Empty = institution default (' . setting('working_days') . ').']);
    field('close_time', 'Normal closing time', ['type' => 'time', 'value' => $b['close_time'] ?? '']);
    echo '<div class="form-actions">'; submit_btn('Save'); echo '</div>'; form_close(); echo '</div>';
    return;
}

$g = $GROUPS[$tab];
echo '<div class="card"><h2>' . e($g[0]) . '</h2>';
if ($tab === 'penalty') echo '<div class="notice">The documented penalty is <b>3.5% per day</b> on outstanding principal. This is a very high rate and <b>must be confirmed by management</b> (and checked against applicable law) before activation. It is OFF by default.</div>';
if ($tab === 'security' && Backup::ageHours() === null) echo '<div class="notice bad">No verified backup exists yet.</div>';
form_open(url('settings', ['a' => 'save', 'tab' => $tab]));
foreach ($g[2] as $key => $def) {
    [$label, $type] = $def;
    $help = is_string($def[2] ?? null) ? $def[2] : '';
    if ($type === 'roles') {
        $cur = array_map('trim', explode(',', setting($key)));
        echo '<div class="fld span4"><label>' . e($label) . '</label><div class="perm-grid">';
        foreach (array_column(rows('SELECT role FROM roles ORDER BY sort_order'), 'role') as $r) echo '<label><input type="checkbox" name="' . e($key) . '[]" value="' . e($r) . '"' . (in_array($r, $cur, true) ? ' checked' : '') . ($r === 'System Administrator' ? ' disabled' : '') . '> ' . e($r) . '</label>';
        echo '</div><small>' . e($help) . '</small></div>';
        continue;
    }
    $opts = ['value' => $key === 'smtp_pass' ? '' : setting($key), 'help' => $help, 'span' => in_array($key, ['business_address', 'receipt_footer', 'backup_dir', 'working_days'], true) ? 2 : 1];
    if ($type === 'select') { $opts['type'] = 'select'; $opts['options'] = $def[2]; }
    elseif ($type === 'checkbox') $opts['type'] = 'checkbox';
    elseif ($type === 'money') $opts['type'] = 'money';
    elseif ($type === 'number') { $opts['type'] = 'number'; $opts['attrs'] = 'min="0"'; }
    elseif ($type === 'time') $opts['type'] = 'time';
    elseif ($type === 'password') { $opts['type'] = 'password'; $opts['attrs'] = 'autocomplete="new-password" placeholder="' . (setting('smtp_pass') !== '' ? 'unchanged' : '') . '"'; }
    elseif ($type === 'color') { $opts['type'] = 'text'; $opts['attrs'] = 'maxlength="7" placeholder="#0B5C4D"'; }
    field($key, $label, $opts);
}
echo '<div class="form-actions">'; submit_btn('Save settings'); echo '</div>'; form_close();
if ($tab === 'email') {
    echo '<h3>Send a test email</h3>';
    form_open(url('settings', ['a' => 'testmail', 'tab' => 'email']), ['attrs' => 'style="grid-template-columns:1fr auto;align-items:end"']);
    field('to', 'Send to', ['value' => user()['email'] ?? '', 'type' => 'email']);
    echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Send test', 'default'); echo '</div>'; form_close();
    echo '<p class="muted">' . (mail_configured() ? 'Mail server configured.' : 'No mail server yet: emails are written to storage/logs/mail.log instead of being sent.') . ' Save your settings first, then test.</p>';
}
if ($tab === 'business') {
    echo '<h3>Logo</h3><div class="logo-row">';
    echo logo_url() ? '<img class="logo-prev" src="' . e(logo_url()) . '" alt="Current logo">' : '<div class="logo-prev ph">No logo yet</div>';
    echo '<div style="flex:1">';
    form_open(url('settings', ['a' => 'logo', 'tab' => 'business']), ['upload' => true]);
    field('logo', 'Upload logo (PNG or JPG; a square or wide logo works best)', ['type' => 'file', 'span' => 2, 'accept' => 'accept="image/*"']);
    echo '<div class="form-actions">'; submit_btn('Upload logo', 'default'); echo '</div>'; form_close();
    echo '</div></div>';
    echo '<h3>Preview — how it appears on documents</h3><div class="doc-prev">' . letterhead('SAMPLE DOCUMENT', 'Loan no. ' . setting('loan_prefix') . '-' . date('Y') . '-000001') . '<p class="muted" style="font-size:12px;margin:.5rem 0 0">' . e(setting('receipt_footer')) . '</p></div>';
    echo '<p class="muted">These details appear automatically on receipts, loan offers and forms, guarantor forms, schedules, statements, vouchers, End-of-Day summaries, reports (screen, print and Excel) and the sign-in page.</p>';
}
echo '</div>';
