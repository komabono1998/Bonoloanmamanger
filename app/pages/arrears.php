<?php
$title = 'Arrears & defaulters';
foreach (accessible_branch_ids() as $__b) Loans::refreshStatuses(null, (int)$__b);
$asOf = reporting_date();
$a = get('a');
$tab = get('tab', 'arrears');

if ($a === 'followup') {
    post_action(function () {
        require_perm('arrears.manage');
        $lid = (int)post('loan_id');
        $l = Loans::get($lid);
        $type = post('type');
        $types = ['Contact Attempted', 'Promise to Pay', 'Under Follow-Up', 'Referred for Review', 'Disputed', 'Note'];
        must(in_array($type, $types, true), 'Choose an action type.');
        must(in_array($l['status'], Loans::OPEN_STATUSES, true), 'Follow-up applies to open loans.');
        $promise = must_date(post('promise_date'), 'Promised payment date', false);
        must($type !== 'Promise to Pay' || $promise, 'Enter the promised payment date.');
        tx(function () use ($lid, $type, $promise, $l) {
            insert('collection_actions', ['loan_id' => $lid, 'action_date' => reporting_date(), 'type' => $type, 'promise_date' => $promise,
                'promise_amount' => post('promise_amount') !== '' ? parse_money(post('promise_amount'), 'Promised amount') : null, 'notes' => trim(post('notes')) ?: null, 'user_id' => uid(), 'created_at' => now()]);
            if ($type !== 'Note') update('loans', ['collection_status' => $type, 'updated_at' => now()], 'id = :id', ['id' => $lid]);
            audit('Collection follow-up', 'Arrears', 'loan', $lid, $l['collection_status'], $type, trim(post('notes')));
        });
        return 'Follow-up recorded. (Contact or a promise does not change the overdue/default classification.)';
    });
}
if ($a === 'accrue') {
    post_action(function () use ($asOf) {
        must(can('penalty.adjust') || can('eod.manage'), 'You do not have permission to perform this action.');
        must(current_bd() !== null, 'No business day is open.');
        $n = Penalty::accrue($asOf, branch_id());
        return $n ? "$n daily penalty entr" . ($n === 1 ? 'y' : 'ies') . ' posted.' : 'Nothing to accrue — penalties are up to date (or the rule is not enabled).';
    });
}

$defDays = setting_int('default_after_days');
page_head('Arrears & defaulters', '', 'Overdue loans as at business date ' . fmt_date($asOf) . '. Defaulted = overdue by more than ' . $defDays . ' days (configurable).');
if (setting('penalty_enabled') !== '1') echo '<div class="notice">Penalties are <b>not active</b>. The documented 3.5% daily penalty must be confirmed by management (Settings → Penalties) before it applies to newly approved loans.</div>';
tabs(['arrears' => 'Overdue loans', 'defaulters' => 'Defaulters', 'ageing' => 'Ageing report', 'penalties' => 'Penalties', 'followups' => 'Follow-up log'], $tab);

$baseSql = "SELECT l.id, l.loan_no, c.full_name, c.phone, l.due_date, l.overdue_since, " . sql_daydiff('?', 'l.overdue_since') . " AS days, b.principal_out, b.interest_out, b.penalty_out, b.total_out,
            b.last_payment_date, u.full_name AS officer, l.collection_status, l.status
            FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN users u ON u.id = l.officer_id WHERE l.status = 'Overdue' AND " . branch_scope('l.branch_id');
$cols = ['loan_no' => 'Loan', 'full_name' => 'Client', 'phone' => 'Contact', 'due_date' => ['Due', 'date'], 'days' => ['Days overdue', 'num'], 'principal_out' => ['Principal', 'money'], 'interest_out' => ['Interest', 'money'],
    'penalty_out' => ['Penalty', 'money'], 'total_out' => ['Total owed', 'money'], 'last_payment_date' => ['Last payment', 'date'], 'officer' => 'Officer', 'collection_status' => ['Collection status', 'badge']];

if ($tab === 'arrears' || $tab === 'defaulters') {
    $rows = rows($baseSql . ($tab === 'defaulters' ? ' AND ' . sql_daydiff('?', 'l.overdue_since') . ' > ?' : '') . ' ORDER BY l.overdue_since',
        $tab === 'defaulters' ? [$asOf, $asOf, $defDays] : [$asOf]);
    $tt = array_sum(array_column($rows, 'total_out'));
    echo '<div class="kpis">' . kpi($tab === 'defaulters' ? 'Defaulted loans' : 'Overdue loans', num(count($rows)), '', 'bad') . kpi('Total at risk', ugx($tt), '', 'bad') . kpi('Principal at risk', ugx(array_sum(array_column($rows, 'principal_out')))) . '</div>';
    data_table($cols, $rows, ['link' => fn($r) => url('arrears', ['tab' => 'followups', 'loan_id' => $r['id']]), 'empty' => 'No overdue loans. 🎉', 'totals' => ['principal_out', 'interest_out', 'penalty_out', 'total_out']]);
}

if ($tab === 'ageing') {
    $rows = rows($baseSql . ' ORDER BY l.overdue_since', [$asOf]);
    $bk = ['1–7 days' => [1, 7], '8–30 days' => [8, 30], '31–60 days' => [31, 60], '61–90 days' => [61, 90], 'Over 90 days' => [91, PHP_INT_MAX]];
    $sum = [];
    foreach ($bk as $k => [$lo, $hi]) {
        $in = array_filter($rows, fn($r) => $r['days'] >= $lo && $r['days'] <= $hi);
        $sum[] = ['bucket' => $k, 'n' => count($in), 'principal' => array_sum(array_column($in, 'principal_out')), 'interest' => array_sum(array_column($in, 'interest_out')), 'penalty' => array_sum(array_column($in, 'penalty_out')), 'total' => array_sum(array_column($in, 'total_out'))];
    }
    echo '<div class="card"><h2>Overdue ageing</h2>';
    data_table(['bucket' => 'Days overdue', 'n' => ['Loans', 'num'], 'principal' => ['Principal', 'money'], 'interest' => ['Interest', 'money'], 'penalty' => ['Penalty', 'money'], 'total' => ['Total', 'money']], $sum, ['class' => 'nofilter', 'totals' => ['n', 'principal', 'interest', 'penalty', 'total']]);
    echo '</div>';
}

if ($tab === 'penalties') {
    if (can('penalty.adjust') || can('eod.manage')) {
        echo '<div class="card"><div class="card-h"><h2>Penalty accrual</h2>';
        echo '<form method="post" action="' . e(url('arrears', ['a' => 'accrue', 'tab' => 'penalties'])) . '" data-confirm="Post missing daily penalties up to the business date?">' . csrf_field() . '<button class="btn primary sm">Run accrual now</button></form></div>';
        echo '<p class="muted">Accrual also runs automatically when the End-of-Day is submitted. It is idempotent: a loan can only be charged once per accrual date. Penalties are stored apart from principal and interest, using the rate snapshotted when each loan was approved.</p></div>';
    }
    $pr = rows("SELECT p.*, l.loan_no, c.full_name FROM penalties p JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = l.client_id WHERE " . branch_scope('l.branch_id') . " ORDER BY p.id DESC LIMIT 300");
    data_table(['business_date' => ['Accrual date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'days_overdue' => ['Days overdue', 'num'], 'rate_bp' => ['Rate', fn($r) => e(bp_to_pct((int)$r['rate_bp']) . '%')],
        'basis' => 'Basis', 'applicable_balance' => ['Applicable balance', 'money'], 'amount' => ['Penalty', 'money'], 'method' => 'Method'], $pr, ['link' => fn($r) => url('loan', ['id' => $r['loan_id']]), 'empty' => 'No penalties accrued.', 'totals' => ['amount']]);
}

if ($tab === 'followups') {
    $lid = (int)get('loan_id');
    if ($lid) {
        $l = Loans::get($lid);
        $info = Loans::overdueInfo($l);
        echo '<div class="card"><div class="card-h"><h2>' . e($l['loan_no']) . ' · ' . e($l['client_name']) . ' <span class="muted">' . e($l['client_phone']) . '</span></h2>' . btn('Loan file', url('loan', ['id' => $lid]), 'sm') . '</div>';
        $b = Loans::balances($lid);
        desc_list(['Status' => badge($l['status']), 'Overdue' => $info['days'] ? $info['days'] . ' day(s) since ' . fmt_date($info['since']) : 'Not overdue', 'Principal' => ugx($b['principal']), 'Interest' => ugx($b['interest']), 'Penalty' => ugx($b['penalty']), 'Total owed' => '<b>' . ugx($b['total']) . '</b>'], 3);
        echo '</div>';
        if (can('arrears.manage')) {
            echo '<div class="card"><h2>Record follow-up</h2>';
            form_open(url('arrears', ['a' => 'followup', 'tab' => 'followups', 'loan_id' => $lid]));
            field('loan_id', '', ['type' => 'hidden', 'value' => $lid]);
            field('type', 'Action', ['type' => 'select', 'options' => array_combine($t = ['Contact Attempted', 'Promise to Pay', 'Under Follow-Up', 'Referred for Review', 'Disputed', 'Note'], $t)]);
            field('promise_date', 'Promised payment date', ['type' => 'date']);
            field('promise_amount', 'Promised amount (UGX)', ['type' => 'money']);
            field('notes', 'Notes', ['span' => 4, 'type' => 'textarea']);
            echo '<div class="form-actions">'; submit_btn('Save follow-up'); echo '</div>'; form_close(); echo '</div>';
        }
    }
    $log = rows("SELECT a.*, l.loan_no, c.full_name, u.full_name AS by_name FROM collection_actions a JOIN loans l ON l.id = a.loan_id JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = a.user_id WHERE " . branch_scope('l.branch_id')
        . ($lid ? ' AND a.loan_id = ' . $lid : '') . ' ORDER BY a.id DESC LIMIT 200');
    echo '<div class="card"><h2>Follow-up log</h2>';
    data_table(['action_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => ['Action', 'badge'], 'promise_date' => ['Promised date', 'date'], 'promise_amount' => ['Promised', 'money'], 'notes' => 'Notes', 'by_name' => 'By'], $log,
        ['link' => fn($r) => url('arrears', ['tab' => 'followups', 'loan_id' => $r['loan_id']]), 'empty' => 'No follow-ups recorded.']);
    echo '</div>';
}
