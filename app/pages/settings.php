<?php
$title = 'Settings';
$a = get('a');
$tab = get('tab', 'business');

/** key => [label, type, options|help] grouped by tab */
$GROUPS = [
    'business' => ['Business', [
        'business_name' => ['Business name', 'text'], 'business_address' => ['Address', 'text'], 'business_phone' => ['Telephone', 'text'], 'business_email' => ['Email', 'text'],
        'currency' => ['Currency', 'text', 'Display label only (amounts are whole UGX).'], 'receipt_footer' => ['Receipt footer', 'text'],
        'receipt_paper' => ['Receipt paper size', 'select', ['A4' => 'A4', '80mm' => '80 mm roll']],
    ]],
    'numbering' => ['Numbering', [
        'loan_prefix' => ['Loan number prefix', 'text', 'e.g. BLM → BLM-2026-000001'], 'client_prefix' => ['Client number prefix', 'text'], 'receipt_prefix' => ['Receipt number prefix', 'text'],
        'guarantor_prefix' => ['Guarantor prefix', 'text'], 'security_prefix' => ['Security prefix', 'text'],
    ]],
    'lending' => ['Lending terms', [
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
    'penalty' => ['Penalties', [
        'penalty_enabled' => ['Penalties ACTIVE for newly approved loans', 'checkbox', 'Leave OFF until management confirms the rule (SRS 5.5 / 23.4).'], 'penalty_rate_pct' => ['Penalty rate (% per day)', 'text', 'Initial documented value: 3.5% per day — to be confirmed.'],
        'penalty_basis' => ['Rate applies to', 'select', ['outstanding_principal' => 'Outstanding principal', 'original_principal' => 'Original approved principal', 'total_outstanding' => 'Principal + interest + fees outstanding']],
        'penalty_compound' => ['Compound (include accrued penalties in the basis)', 'checkbox'], 'penalty_cap_pct' => ['Cap: max total penalty as % of principal (0 = none)', 'text'],
        'penalty_grace_days' => ['Grace days before penalties start', 'number'], 'default_after_days' => ['Classify as "defaulted" after (days overdue)', 'number'],
    ]],
    'payments' => ['Payments & clients', [
        'alloc_order' => ['Payment allocation priority', 'select', ['penalty,fee,interest,principal' => 'Penalty → Fees → Interest → Principal', 'fee,interest,principal,penalty' => 'Fees → Interest → Principal → Penalty',
            'interest,principal,penalty,fee' => 'Interest → Principal → Penalty → Fees', 'principal,interest,penalty,fee' => 'Principal → Interest → Penalty → Fees', 'interest,penalty,fee,principal' => 'Interest → Penalty → Fees → Principal']],
        'phone_duplicate_policy' => ['Duplicate phone numbers', 'select', ['warn' => 'Warn (allow shared phones after confirmation)', 'block' => 'Block', 'allow' => 'Allow silently']],
    ]],
    'eod' => ['Cash & End of Day', [
        'working_days' => ['Working days (1=Mon … 7=Sun, comma separated)', 'text', 'Holidays are added in the calendar below.'], 'eod_close_time' => ['Normal closing time', 'time', 'Informational. A late transaction is posted to the open business date; the date never changes silently.'],
        'require_reconciliation' => ['Every till must be reconciled before EOD', 'checkbox'], 'expense_approval_limit' => ['Expenses above this need approval (UGX)', 'money'],
    ]],
    'security' => ['Security & backup', [
        'session_timeout_min' => ['Session timeout (minutes of inactivity)', 'number'], 'backup_dir' => ['Backup folder', 'text', 'Relative to the app folder, or an absolute path (e.g. D:/Backups/Bono). Prefer a different drive.'],
        'backup_auto_hours' => ['Automatic backup if last backup is older than (hours, 0 = off)', 'number'], 'backup_keep' => ['Backups to keep', 'number'], 'report_default_days' => ['Default report period (days)', 'number'],
    ]],
];

if ($a === 'save') post_action(function () use ($GROUPS, $tab) {
    require_perm('settings.manage');
    $g = $GROUPS[$tab] ?? null;
    must((bool)$g, 'Unknown settings group.');
    tx(function () use ($g) {
        foreach ($g[1] as $key => $def) {
            $type = $def[1];
            if ($type === 'checkbox') $v = !empty($_POST[$key]) ? '1' : '0';
            else {
                $v = trim((string)($_POST[$key] ?? ''));
                if ($type === 'money') $v = (string)parse_money($v, $def[0], true);
                elseif ($type === 'number') { must(ctype_digit($v) && $v !== '', $def[0] . ' must be a whole number.'); }
                elseif (str_ends_with($key, '_pct')) { must(is_numeric($v) && (float)$v >= 0 && (float)$v <= 1000, $def[0] . ' must be a percentage.'); }
                elseif ($type === 'select') { must(array_key_exists($v, $def[2]), $def[0] . ': invalid choice.'); }
                elseif (str_ends_with($key, '_prefix')) { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v)); must($v !== '', $def[0] . ' is required.'); }
            }
            if ($key === 'inspection_fee_max') must((int)$v >= (int)($_POST['inspection_fee_min'] ? parse_money($_POST['inspection_fee_min'], 'x', true) : 0), 'Inspection fee maximum must be at least the minimum.');
            if ($key === 'working_days') { $v = implode(',', array_filter(array_map('trim', explode(',', $v)), fn($d) => preg_match('/^[1-7]$/', $d))); must($v !== '', 'Choose at least one working day.'); }
            set_setting($key, $v, uid());
        }
    });
    return 'Settings saved. Existing approved loans keep their original terms.';
});
if ($a === 'holiday') post_action(function () {
    require_perm('settings.manage');
    $d = must_date(post('date'), 'Date');
    $working = post('is_working') ? 1 : 0;
    q('INSERT INTO business_calendar(date, is_working, note) VALUES (?,?,?) ON CONFLICT(date) DO UPDATE SET is_working = excluded.is_working, note = excluded.note', [$d, $working, clean(post('note')) ?: null]);
    audit('Business calendar changed', 'Settings', 'calendar', $d, null, $working ? 'working day' : 'holiday', clean(post('note')));
    return ['Calendar updated.', url('settings', ['tab' => 'calendar'])];
});
if ($a === 'delholiday') post_action(function () {
    require_perm('settings.manage');
    q('DELETE FROM business_calendar WHERE date = ?', [post('date')]);
    audit('Business calendar entry removed', 'Settings', 'calendar', post('date'));
    return ['Entry removed.', url('settings', ['tab' => 'calendar'])];
});
if ($a === 'logo') post_action(function () {
    require_perm('settings.manage');
    $att = save_upload('logo', 'business', 1, 'logo');
    must((bool)$att, 'Choose an image to upload.');
    set_setting('business_logo', (string)$att, uid());
    return ['Logo updated.', url('settings', ['tab' => 'business'])];
});

page_head('Settings', '', 'Changes are logged and apply to new loans only — they never rewrite the terms of loans already approved.');
$tabs = [];
foreach ($GROUPS as $k => $g) $tabs[$k] = $g[0];
$tabs['calendar'] = 'Business calendar';
tabs($tabs, $tab);

if ($tab === 'calendar') {
    echo '<div class="grid g2"><div class="card"><h2>Holidays & special working days</h2>';
    $cal = rows('SELECT * FROM business_calendar ORDER BY date DESC LIMIT 100');
    data_table(['date' => ['Date', 'date'], 'is_working' => ['Type', fn($r) => $r['is_working'] ? badge('Active') . ' working day' : 'Holiday / closed'], 'note' => 'Note',
        'x' => ['', fn($r) => '<form method="post" action="' . e(url('settings', ['a' => 'delholiday'])) . '" data-confirm="Remove this entry?">' . csrf_field() . '<input type="hidden" name="date" value="' . e($r['date']) . '"><button class="btn sm">Remove</button></form>']], $cal, ['class' => 'nofilter', 'empty' => 'No holidays defined.']);
    echo '</div><div class="card"><h2>Add / change a date</h2>';
    form_open(url('settings', ['a' => 'holiday']), ['attrs' => 'style="grid-template-columns:1fr"']);
    field('date', 'Date', ['type' => 'date', 'required' => true]); field('note', 'Description (e.g. Independence Day)');
    field('is_working', 'This date is a WORKING day (untick for a holiday)', ['type' => 'checkbox']);
    echo '<div class="form-actions">'; submit_btn('Save date'); echo '</div>'; form_close();
    echo '<p class="muted">Used for offer expiry (working days), maturity rolling and the business-day opening check.</p></div></div>';
    return;
}

$g = $GROUPS[$tab] ?? reset($GROUPS);
echo '<div class="card"><h2>' . e($g[0]) . '</h2>';
if ($tab === 'penalty') echo '<div class="notice">The documented penalty is <b>3.5% per day</b> on outstanding principal. This is a very high rate and <b>must be confirmed by management</b> (and checked against applicable law) before activation. It is OFF by default.</div>';
if ($tab === 'eod') echo '<div class="notice info">Decisions pending management: official closing time, late-transaction policy, variance authorization limits and whether several cashiers run separate tills.</div>';
form_open(url('settings', ['a' => 'save', 'tab' => $tab]));
foreach ($g[1] as $key => $def) {
    [$label, $type] = $def;
    $help = is_string($def[2] ?? null) ? $def[2] : '';
    $opts = ['value' => setting($key), 'help' => $help, 'span' => in_array($type, ['text'], true) && in_array($key, ['business_address', 'receipt_footer', 'backup_dir', 'working_days'], true) ? 2 : 1];
    if ($type === 'select') { $opts['type'] = 'select'; $opts['options'] = $def[2]; }
    elseif ($type === 'checkbox') $opts['type'] = 'checkbox';
    elseif ($type === 'money') $opts['type'] = 'money';
    elseif ($type === 'number') { $opts['type'] = 'number'; $opts['attrs'] = 'min="0"'; }
    elseif ($type === 'time') $opts['type'] = 'time';
    field($key, $label, $opts);
}
echo '<div class="form-actions">'; submit_btn('Save settings'); echo '</div>'; form_close();
if ($tab === 'business') {
    echo '<h3>Logo</h3>';
    form_open(url('settings', ['a' => 'logo', 'tab' => 'business']), ['upload' => true]);
    field('logo', 'Business logo (PNG / JPG)', ['type' => 'file', 'span' => 2, 'accept' => 'accept="image/*"']);
    echo '<div class="form-actions">'; submit_btn('Upload logo', 'default'); echo '</div>'; form_close();
}
echo '</div>';
