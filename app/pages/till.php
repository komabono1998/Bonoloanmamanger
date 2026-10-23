<?php
$title = 'Cashier & till';
$a = get('a');
$tab = get('tab', can('till.use') ? 'my' : 'sessions');

if ($a === 'open') post_action(function () {
    $sid = Cash::openSession(parse_money(post('opening_float') ?: '0', 'Opening float', true), (bool)post('confirmed'), clean(post('note')));
    return 'Till opened for business date ' . fmt_date(current_bd()) . '.';
});
if ($a === 'count') post_action(function () {
    $sid = (int)post('session_id');
    $d = [];
    foreach ([50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50] as $dn) if ((int)($_POST['dn_' . $dn] ?? 0) > 0) $d[$dn] = (int)$_POST['dn_' . $dn];
    Cash::submitCount($sid, parse_money(post('counted'), 'Counted cash', true), clean(post('reason')), $d);
    return 'Count submitted for reconciliation. The till is now closed to new transactions.';
});
if ($a === 'transfer') post_action(function () {
    Cash::transfer(post('type'), parse_money(post('amount'), 'Transfer amount'), (int)post('account_id') ?: null, (int)post('other_session') ?: null, clean(post('reason')));
    return 'Transfer recorded.';
});
if ($a === 'reconcile') post_action(function () {
    Cash::reconcile((int)post('session_id'), clean(post('notes')));
    return ['Session reconciled.', url('till', ['tab' => 'sessions'])];
});
if ($a === 'investigate') post_action(function () {
    Cash::investigate((int)post('session_id'), clean(post('notes')));
    return ['Session sent for investigation. The cashier can recount, or you can reopen it.', url('till', ['tab' => 'sessions'])];
});
if ($a === 'reopen') post_action(function () {
    Cash::reopenSession((int)post('session_id'), clean(post('notes')));
    return ['Session reopened for corrections. Earlier counts are preserved.', url('till', ['tab' => 'sessions'])];
});

page_head('Cashier & till', '', 'Each cashier works from a separately identifiable till. Closing a till does not close the business day.');
$tabs = [];
if (can('till.use')) $tabs['my'] = 'My till';
if (can('till.reconcile') || can('eod.view')) $tabs['sessions'] = 'All sessions & reconciliation';
tabs($tabs, $tab);
$bd = current_bd();

if ($tab === 'my' && can('till.use')) {
    if (!$bd) { echo '<div class="notice bad">No business day is open. A manager must start the day before tills can be opened.</div>'; return; }
    $s = Cash::mySession();
    $latest = row("SELECT * FROM cashier_sessions WHERE cashier_id = ? AND business_date = ? ORDER BY id DESC LIMIT 1", [uid(), $bd]);
    if (!$s && (!$latest || $latest['status'] === 'Reconciled')) {
        $last = Cash::lastClosingCash(uid());
        echo '<div class="card"><h2>Open your till</h2><p class="muted">Count the opening float in front of a witness and confirm it. ' . ($last !== null ? 'Your last reconciled closing cash was <b>' . ugx($last) . '</b>.' : '') . '</p>';
        form_open(url('till', ['a' => 'open']));
        field('opening_float', 'Opening float counted (UGX)', ['type' => 'money', 'value' => $last ?? 0, 'required' => true]);
        field('note', 'Explanation (needed if different from last closing cash)', ['span' => 2]);
        field('confirmed', 'I confirm the opening cash has been counted', ['type' => 'checkbox', 'span' => 1]);
        echo '<div class="form-actions">'; submit_btn('Open till'); echo '</div>'; form_close(); echo '</div>';
        return;
    }
    $sess = $s ?: $latest;
    $id = (int)$sess['id'];
    $m = fn(string $cat, int $dir) => (int)val('SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE session_id = ? AND is_cash = 1 AND category = ? AND direction = ?', [$id, $cat, $dir]);
    echo '<div class="notice ' . ($sess['status'] === 'Open' ? 'ok' : '') . '">Session <b>' . e($sess['session_no']) . '</b> · ' . badge($sess['status']) . ' · opened ' . e(fmt_dt($sess['opened_at'])) . '</div>';
    echo '<div class="kpis">' . kpi('Opening float', ugx($sess['opening_float'])) . kpi('Cash collections', ugx($m('collection', 1)), '', 'ok') . kpi('Cash disbursed', ugx($m('disbursement', -1)))
        . kpi('Cash expenses', ugx($m('expense', -1))) . kpi('Transfers in', ugx($m('transfer', 1))) . kpi('Transfers out', ugx($m('transfer', -1)))
        . kpi('Expected cash in till', ugx(Cash::expected($id)), 'float + in − out; non-cash excluded', 'info') . '</div>';
    if ($sess['status'] === 'Submitted') echo '<div class="notice">Count submitted: counted ' . ugx($sess['counted_cash']) . ' vs expected ' . ugx($sess['expected_cash']) . ' → variance <b>' . ugx($sess['variance']) . '</b>. Waiting for reconciliation by a manager/accountant.</div>';

    if (in_array($sess['status'], ['Open', 'Investigation'], true)) {
        echo '<div class="grid g2">';
        if ($sess['status'] === 'Open') {
            echo '<div class="card"><h2>Transfer cash</h2>';
            $accs = []; foreach (rows('SELECT id, name FROM cash_accounts WHERE active = 1') as $x) $accs[$x['id']] = $x['name'];
            $others = []; foreach (rows("SELECT s.id, s.session_no, u.full_name FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.status = 'Open' AND s.business_date = ? AND s.id <> ?", [$bd, $id]) as $x) $others[$x['id']] = $x['session_no'] . ' — ' . $x['full_name'];
            form_open(url('till', ['a' => 'transfer']), ['attrs' => 'style="grid-template-columns:1fr 1fr"']);
            field('type', 'Direction', ['type' => 'select', 'options' => ['to_account' => 'Till → safe / bank (cash out)', 'from_account' => 'Safe / bank → till (cash in)', 'to_session' => 'Till → another cashier\'s till'], 'span' => 2]);
            field('account_id', 'Account', ['type' => 'select', 'options' => $accs, 'blank' => '—']);
            field('other_session', 'Other till', ['type' => 'select', 'options' => $others, 'blank' => '—']);
            field('amount', 'Amount (UGX)', ['type' => 'money', 'required' => true]);
            field('reason', 'Reason', ['required' => true]);
            echo '<div class="form-actions">'; submit_btn('Record transfer', 'default'); echo '</div>'; form_close(); echo '</div>';
        }
        echo '<div class="card"><h2>' . ($sess['status'] === 'Investigation' ? 'Recount and resubmit' : 'Count & close till') . '</h2>';
        if ($sess['status'] === 'Investigation') echo '<div class="notice bad">Sent for investigation: ' . e($sess['reconcile_notes']) . '</div>';
        form_open(url('till', ['a' => 'count']), ['attrs' => 'id="count-form" style="grid-template-columns:1fr 1fr 1fr"']);
        field('session_id', '', ['type' => 'hidden', 'value' => $id]);
        foreach ([50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50] as $dn) {
            echo '<div class="fld"><label>' . number_format($dn) . ' ×</label><input type="number" min="0" name="dn_' . $dn . '" data-dn="' . $dn . '" value="" placeholder="0"></div>';
        }
        field('counted', 'Total counted cash (UGX)', ['type' => 'money', 'required' => true, 'span' => 2, 'help' => 'Fills automatically from the notes/coins above, or type the total.']);
        field('reason', 'Variance explanation (required if counted ≠ expected)', ['type' => 'textarea', 'span' => 3]);
        echo '<div class="form-actions">'; submit_btn('Submit count', 'primary', 'data-confirm="Submit the count and close this till? No more transactions can be posted to it."'); echo '</div>'; form_close();
        echo '<p class="muted">Count what is physically in the drawer. A difference from the expected cash must be explained and is reviewed by a manager.</p>';
        echo '<script>(function(){var f=document.getElementById("count-form");if(!f)return;f.addEventListener("input",function(e){if(!e.target.dataset.dn)return;var t=0;f.querySelectorAll("[data-dn]").forEach(function(i){t+=(parseInt(i.value)||0)*parseInt(i.dataset.dn)});var c=document.getElementById("f_counted");c.value=t?t.toLocaleString("en-US"):""})})();</script>';
        echo '</div></div>';
    }
    echo '<div class="card"><h2>Till transactions</h2>';
    $mv = rows('SELECT * FROM cash_movements WHERE session_id = ? ORDER BY id DESC', [$id]);
    data_table(['created_at' => ['Time', 'dt'], 'category' => 'Category', 'description' => 'Description', 'method' => 'Method', 'reference' => 'Reference',
        'in' => ['Cash in', fn($r) => $r['direction'] == 1 && $r['is_cash'] ? num($r['amount']) : '', 'r'], 'out' => ['Cash out', fn($r) => $r['direction'] == -1 && $r['is_cash'] ? num($r['amount']) : '', 'r'],
        'nc' => ['Non-cash', fn($r) => !$r['is_cash'] ? num($r['amount']) : '', 'r']], $mv, ['empty' => 'No transactions yet.']);
    echo '</div>';
    return;
}

// -------------------------------------------------------------- sessions overview (reconciliation)
$date = get('date') ?: ($bd ?: val('SELECT MAX(business_date) FROM cashier_sessions') ?: today());
$sid = (int)get('session');
if ($sid) {
    $s = Cash::session($sid);
    echo '<div class="card"><div class="card-h"><h2>' . e($s['session_no']) . ' · ' . e($s['cashier_name']) . ' ' . badge($s['status']) . '</h2>' . btn('Back', url('till', ['tab' => 'sessions', 'date' => $s['business_date']]), 'sm') . '</div>';
    desc_list(['Business date' => e(fmt_date($s['business_date'])), 'Opened' => e(fmt_dt($s['opened_at'])), 'Opening float' => ugx($s['opening_float']), 'Expected cash' => ugx($s['status'] === 'Open' ? Cash::expected($sid) : $s['expected_cash']),
        'Counted cash' => $s['counted_cash'] === null ? '—' : ugx($s['counted_cash']), 'Variance' => $s['variance'] === null ? '—' : '<b class="' . ($s['variance'] != 0 ? 'ico-bad' : 'ico-ok') . '">' . ugx($s['variance']) . '</b>',
        'Variance explanation' => e($s['variance_reason']), 'Reconciled by' => e(val('SELECT full_name FROM users WHERE id = ?', [$s['reconciled_by']])), 'Manager notes' => e($s['reconcile_notes'])], 3);
    echo '</div><div class="card"><h2>Count history (originals are never overwritten)</h2>';
    data_table(['counted_at' => ['When', 'dt'], 'who' => 'Counted by', 'expected' => ['Expected', 'money'], 'counted' => ['Counted', 'money'], 'variance' => ['Variance', 'money'], 'reason' => 'Reason'],
        rows('SELECT c.*, u.full_name AS who FROM cash_counts c JOIN users u ON u.id = c.counted_by WHERE c.session_id = ? ORDER BY c.id', [$sid]), ['class' => 'nofilter', 'empty' => 'Not counted yet.']);
    echo '</div><div class="card"><h2>Movements</h2>';
    data_table(['created_at' => ['Time', 'dt'], 'category' => 'Category', 'description' => 'Description', 'method' => 'Method', 'in' => ['In', fn($r) => $r['direction'] == 1 && $r['is_cash'] ? num($r['amount']) : '', 'r'],
        'out' => ['Out', fn($r) => $r['direction'] == -1 && $r['is_cash'] ? num($r['amount']) : '', 'r'], 'nc' => ['Non-cash', fn($r) => !$r['is_cash'] ? num($r['amount']) : '', 'r']],
        rows('SELECT * FROM cash_movements WHERE session_id = ? ORDER BY id', [$sid]), ['class' => 'nofilter']);
    echo '</div>';
    if (can('till.reconcile')) {
        if ($s['status'] === 'Submitted') {
            echo '<div class="grid g2"><div class="card"><h2>Reconcile</h2>';
            if ((int)$s['variance'] !== 0) echo '<div class="notice bad">Variance of ' . ugx($s['variance']) . ' — record your investigation outcome. The variance stays on record; no adjustment is created automatically.</div>';
            form_open(url('till', ['a' => 'reconcile']), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Approve this cashier count?"']);
            field('session_id', '', ['type' => 'hidden', 'value' => $sid]); field('notes', 'Reconciliation notes', ['type' => 'textarea', 'required' => (int)$s['variance'] !== 0]);
            echo '<div class="form-actions">'; submit_btn('Approve reconciliation'); echo '</div>'; form_close();
            echo '</div><div class="card"><h2>Send back</h2>';
            form_open(url('till', ['a' => 'investigate']), ['attrs' => 'style="grid-template-columns:1fr"']);
            field('session_id', '', ['type' => 'hidden', 'value' => $sid]); field('notes', 'What must be investigated?', ['type' => 'textarea', 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Send for investigation', 'danger'); echo '</div>'; form_close(); echo '</div></div>';
        }
        if (in_array($s['status'], ['Submitted', 'Investigation'], true) && current_bd() === $s['business_date']) {
            echo '<details class="card"><summary>Reopen the till to post a missed transaction</summary>';
            form_open(url('till', ['a' => 'reopen']), ['attrs' => 'data-confirm="Reopen this till?"']);
            field('session_id', '', ['type' => 'hidden', 'value' => $sid]); field('notes', 'Reason', ['span' => 3, 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Reopen till', 'default'); echo '</div>'; form_close(); echo '</details>';
        }
    }
    return;
}
filter_bar(['date' => ['label' => 'Business date', 'type' => 'date']], ['date' => $date], 'till', ['tab' => 'sessions']);
$ss = rows('SELECT s.*, u.full_name AS cashier FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.business_date = ? ORDER BY s.id', [$date]);
foreach ($ss as &$r) if ($r['status'] === 'Open') $r['expected_cash'] = Cash::expected((int)$r['id']);
unset($r);
data_table(['session_no' => 'Session', 'cashier' => 'Cashier', 'opened_at' => ['Opened', 'dt'], 'opening_float' => ['Float', 'money'], 'expected_cash' => ['Expected', 'money'], 'counted_cash' => ['Counted', 'money'],
    'variance' => ['Variance', fn($r) => $r['variance'] === null ? '' : ($r['variance'] != 0 ? '<b class="ico-bad">' . num($r['variance']) . '</b>' : '0'), 'r'], 'status' => ['Status', 'badge']],
    $ss, ['link' => fn($r) => url('till', ['tab' => 'sessions', 'session' => $r['id']]), 'empty' => 'No cashier sessions on this business date.', 'class' => 'nofilter']);
