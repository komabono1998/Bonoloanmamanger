<?php
/** General ledger: chart of accounts, journals, manual journals and the status of automatic posting. Statements are in the Report Centre (FN-12). */
$title = 'General Ledger';
$a = get('a') ?: 'accounts';
$id = (int)get('id');
Gl::seedChart();

if ($a === 'post') post_action(function () {
    require_perm('gl.post');
    $n = Gl::postPending();
    audit('General ledger posted', 'General Ledger', 'gl', 'manual', null, $n . ' journal(s)');
    return [$n ? $n . ' journal(s) posted from business events.' : 'Nothing to post: the ledger is up to date.', url('ledger')];
});
if ($a === 'save') post_action(function () use ($id) {
    require_perm('gl.manage');
    $nid = Gl::saveAccount($id ?: null, post('code'), post('name'), post('type'), post('description'), post('active') !== '');
    return ['Account saved.', url('ledger', ['a' => 'accounts'])];
});
if ($a === 'create') post_action(function () {
    $lines = [];
    foreach ((array)($_POST['acct'] ?? []) as $i => $acct) {
        $dr = trim((string)($_POST['dr'][$i] ?? '')); $cr = trim((string)($_POST['cr'][$i] ?? ''));
        $lines[] = ['account' => (int)$acct, 'debit' => $dr !== '' ? parse_money($dr, 'Debit', true) : 0, 'credit' => $cr !== '' ? parse_money($cr, 'Credit', true) : 0, 'memo' => clean((string)($_POST['lm'][$i] ?? '')) ?: null];
    }
    $jid = Gl::manual(post('journal_date'), (int)post('branch_id') ?: null, post('memo'), $lines);
    return ['Journal posted.', url('ledger', ['a' => 'journal', 'id' => $jid])];
});
if ($a === 'reverse') post_action(function () use ($id) {
    $jid = Gl::reverse($id, post('reason'));
    return ['Journal reversed by a new journal.', url('ledger', ['a' => 'journal', 'id' => $jid])];
});

$tabs = ['accounts' => 'Chart of accounts', 'journals' => 'Journals'];
$act = '';
if (can('gl.post')) $act .= '<form method="post" action="' . e(url('ledger', ['a' => 'post'])) . '" style="display:inline">' . csrf_field() . '<button class="btn" type="submit">Post pending now</button></form> ' . btn('New manual journal', url('ledger', ['a' => 'new']), 'primary');
if (can('report.view')) $act = btn('Trial balance & statements', url('reports', ['r' => 'FN-12'])) . ' ' . $act;
page_head('General Ledger', $act, 'Double-entry books built from loans, repayments, cash, expenses and fees. Posted automatically at End of Day; journals can never be edited, only reversed.');

echo '<div class="tabs">';
foreach ($tabs as $k => $l) echo '<a class="tab' . ($a === $k ? ' on' : '') . '" href="' . e(url('ledger', ['a' => $k])) . '">' . e($l) . '</a>';
echo '</div>';

$scope = Gl::lineScope();
$statusBar = function () {
    $pend = Gl::pendingCount();
    $last = val('SELECT MAX(created_at) FROM gl_journals');
    echo '<div class="notice ' . ($pend ? 'warn' : 'info') . '">' . ($pend ? '<b>' . $pend . '</b> business event(s) are not yet in the ledger; they are posted automatically when the day is ended' . (can('gl.post') ? ', or use "Post pending now".' : '.')
        : 'The ledger is up to date with all loan, cash and expense activity.') . ($last ? ' Last posting: ' . e(fmt_dt($last)) . '.' : '') . '</div>';
};

// ------------------------------------------------------------------ chart of accounts
if ($a === 'accounts') {
    $statusBar();
    $to = reporting_date();
    $bal = []; foreach (Gl::trialBalance(null, $to, null, false) as $r) $bal[$r['id']] = $r['closing'];
    foreach (Gl::TYPES as $t => $label) {
        $rows = rows('SELECT * FROM gl_accounts WHERE type = ? ORDER BY code', [$t]);
        foreach ($rows as &$r) { $b = $bal[$r['id']] ?? 0; $r['bal'] = in_array($t, ['asset', 'expense'], true) ? $b : -$b; $r['state'] = $r['active'] ? 'Active' : 'Inactive'; }
        unset($r);
        echo '<div class="card"><div class="card-h"><h2>' . e($label) . '</h2></div>';
        data_table(['code' => 'Code', 'name' => 'Account', 'description' => 'Purpose', 'bal' => ['Balance (UGX)', 'money'], 'state' => ['Status', 'badge']], $rows, [
            'link' => fn($r) => url('ledger', ['a' => 'account', 'id' => $r['id']]), 'empty' => 'No accounts.']);
        echo '</div>';
    }
    if (can('gl.manage')) {
        echo '<div class="card"><h2>Add an account</h2>';
        form_open(url('ledger', ['a' => 'save']));
        field('code', 'Code (4-8 digits)', ['required' => true]);
        field('name', 'Account name', ['required' => true, 'span' => 2]);
        field('type', 'Type', ['type' => 'select', 'options' => Gl::TYPES, 'required' => true]);
        field('description', 'Purpose', ['span' => 3]);
        echo '<div class="form-actions">'; submit_btn('Add account'); echo '</div>'; form_close(); echo '</div>';
    }
    return;
}

// ------------------------------------------------------------------ one account: ledger with running balance
if ($a === 'account') {
    $acc = row('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
    must((bool)$acc, 'Account not found.');
    $from = is_date(get('from')) ? get('from') : date('Y-m-01', strtotime(reporting_date()));
    $to = is_date(get('to')) ? get('to') : reporting_date();
    $act = Gl::accountActivity($id, $from, $to);
    echo '<div class="card"><div class="card-h"><h2>' . e($acc['code'] . ' · ' . $acc['name']) . '</h2>' . btn('Chart of accounts', url('ledger')) . '</div>';
    echo '<form method="get" action="' . e(base_url() . '/index.php') . '" class="inline-filters"><input type="hidden" name="p" value="ledger"><input type="hidden" name="a" value="account"><input type="hidden" name="id" value="' . $id . '">'
        . 'From <input type="date" name="from" value="' . e($from) . '"> To <input type="date" name="to" value="' . e($to) . '"> <button class="btn" type="submit">Show</button></form>';
    echo '<p class="muted">Opening balance ' . e(ugx($act['opening'])) . ' (debit positive) · closing balance ' . e(ugx($act['closing'])) . '</p>';
    data_table(['journal_date' => ['Date', 'date'], 'journal_no' => 'Journal', 'branch' => 'Branch', 'memo' => 'Description', 'debit' => ['Debit', 'money'], 'credit' => ['Credit', 'money'], 'balance' => ['Balance', 'money']], $act['rows'], [
        'link' => fn($r) => url('ledger', ['a' => 'journal', 'id' => $r['jid']]), 'empty' => 'No postings in this period.', 'totals' => ['debit', 'credit']]);
    if (can('gl.manage')) {
        echo '<h3>Edit account</h3>';
        form_open(url('ledger', ['a' => 'save', 'id' => $id]));
        field('name', 'Account name', ['value' => $acc['name'], 'required' => true, 'span' => 2]);
        field('description', 'Purpose', ['value' => $acc['description'] ?? '', 'span' => 2]);
        field('active', 'Active (can receive manual journals)', ['type' => 'checkbox', 'value' => $acc['active']]);
        echo '<div class="form-actions">'; submit_btn('Save account'); echo '</div>'; form_close();
    }
    echo '</div>';
    return;
}

// ------------------------------------------------------------------ journal register
if ($a === 'journals') {
    $from = is_date(get('from')) ? get('from') : date('Y-m-d', strtotime('-30 days', strtotime(reporting_date())));
    $to = is_date(get('to')) ? get('to') : reporting_date();
    $src = in_array(get('source'), ['auto', 'manual'], true) ? get('source') : '';
    $qs = trim(get('q'));
    echo '<div class="card"><form method="get" action="' . e(base_url() . '/index.php') . '" class="inline-filters"><input type="hidden" name="p" value="ledger"><input type="hidden" name="a" value="journals">'
        . 'From <input type="date" name="from" value="' . e($from) . '"> To <input type="date" name="to" value="' . e($to) . '"> '
        . '<select name="source"><option value="">All journals</option><option value="auto"' . ($src === 'auto' ? ' selected' : '') . '>Automatic</option><option value="manual"' . ($src === 'manual' ? ' selected' : '') . '>Manual</option></select> '
        . '<input type="search" name="q" value="' . e($qs) . '" placeholder="journal no or description"> <button class="btn" type="submit">Filter</button></form></div>';
    $sc = $scope;
    $w = "j.journal_date BETWEEN ? AND ?" . ($src ? ' AND j.source = ?' : '') . ($qs !== '' ? " AND (j.journal_no LIKE ? ESCAPE '!' OR j.memo LIKE ? ESCAPE '!')" : '');
    $params = [$from, $to]; if ($src) $params[] = $src;
    if ($qs !== '') { $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $qs) . '%'; $params[] = $like; $params[] = $like; }
    $rows = rows("SELECT j.id, j.journal_no, j.journal_date, j.source, j.memo, COALESCE(b.name, 'Head office') AS branch, u.full_name AS who,
                  (SELECT COALESCE(SUM(l.debit),0) FROM gl_journal_lines l WHERE l.journal_id = j.id) AS amount
                  FROM gl_journals j LEFT JOIN branches b ON b.id = j.branch_id LEFT JOIN users u ON u.id = j.posted_by
                  WHERE $w AND EXISTS (SELECT 1 FROM gl_journal_lines l WHERE l.journal_id = j.id AND $sc) ORDER BY j.journal_date DESC, j.id DESC LIMIT 500", $params);
    foreach ($rows as &$r) $r['kind'] = $r['source'] === 'manual' ? 'Manual' : 'Automatic';
    unset($r);
    echo '<div class="card">';
    data_table(['journal_date' => ['Date', 'date'], 'journal_no' => 'Journal', 'branch' => 'Branch', 'memo' => 'Description', 'kind' => 'Type', 'amount' => ['Amount', 'money'], 'who' => 'Posted by'], $rows, [
        'link' => fn($r) => url('ledger', ['a' => 'journal', 'id' => $r['id']]), 'empty' => 'No journals match.']);
    echo count($rows) >= 500 ? '<p class="muted">Showing the latest 500. Narrow the dates to see more.</p>' : '';
    echo '</div>';
    return;
}

// ------------------------------------------------------------------ one journal
if ($a === 'journal') {
    $j = row("SELECT j.*, COALESCE(b.name, 'Head office') AS branch, u.full_name AS who FROM gl_journals j LEFT JOIN branches b ON b.id = j.branch_id LEFT JOIN users u ON u.id = j.posted_by WHERE j.id = ?", [$id]);
    must((bool)$j, 'Journal not found.');
    if ($j['branch_id']) assert_branch((int)$j['branch_id']); else must(can('branch.all'), 'You do not have access to head-office journals.');
    $lines = rows('SELECT l.*, a.code, a.name FROM gl_journal_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.journal_id = ? ORDER BY l.id', [$id]);
    $rev = row('SELECT id, journal_no FROM gl_journals WHERE reverses_id = ?', [$id]);
    echo '<div class="card"><div class="card-h"><h2>' . e($j['journal_no']) . ' · ' . e(fmt_date($j['journal_date'])) . '</h2>' . btn('All journals', url('ledger', ['a' => 'journals'])) . '</div>';
    echo '<p>' . e($j['memo'] ?? '') . '</p><p class="muted">' . e($j['branch']) . ' · ' . ($j['source'] === 'manual' ? 'Manual journal' : 'Automatic posting (' . e((string)$j['src_key']) . ')') . ' · posted ' . e(fmt_dt($j['created_at'])) . ($j['who'] ? ' by ' . e($j['who']) : '') . '</p>';
    if ($j['reverses_id']) echo '<div class="notice info">This journal reverses ' . '<a href="' . e(url('ledger', ['a' => 'journal', 'id' => $j['reverses_id']])) . '">journal ' . (int)$j['reverses_id'] . '</a>.</div>';
    if ($rev) echo '<div class="notice warn">Reversed by <a href="' . e(url('ledger', ['a' => 'journal', 'id' => $rev['id']])) . '">' . e($rev['journal_no']) . '</a>.</div>';
    data_table(['code' => 'Account', 'name' => 'Name', 'description' => 'Narration', 'debit' => ['Debit', 'money'], 'credit' => ['Credit', 'money']], $lines, ['totals' => ['debit', 'credit']]);
    if (can('gl.post') && $j['source'] === 'manual' && !$j['reverses_id'] && !$rev) {
        echo '<h3>Reverse this journal</h3>';
        form_open(url('ledger', ['a' => 'reverse', 'id' => $id]));
        field('reason', 'Reason', ['required' => true, 'span' => 3]);
        echo '<div class="form-actions">'; submit_btn('Reverse journal', 'danger', 'data-confirm="Post a reversing journal?"'); echo '</div>'; form_close();
    }
    echo '</div>';
    return;
}

// ------------------------------------------------------------------ new manual journal
if ($a === 'new') {
    require_perm('gl.post');
    $accts = []; foreach (rows('SELECT id, code, name FROM gl_accounts WHERE active = 1 ORDER BY code') as $r) $accts[$r['id']] = $r['code'] . ' · ' . $r['name'];
    echo '<div class="card"><h2>New manual journal</h2><p class="muted">Use for opening balances, capital, bank charges and other entries that do not come from loans or the till. Debits must equal credits.</p>';
    form_open(url('ledger', ['a' => 'create']));
    field('journal_date', 'Date', ['type' => 'date', 'value' => reporting_date(), 'required' => true]);
    $brOpts = branch_options();
    field('branch_id', 'Branch', ['type' => 'select', 'options' => $brOpts, 'blank' => can('branch.all') ? 'Head office (no branch)' : null, 'value' => branch_id()]);
    field('memo', 'Description', ['required' => true, 'span' => 2]);
    echo '<div class="fld span4"><table class="tbl"><thead><tr><th>Account</th><th class="r">Debit</th><th class="r">Credit</th><th>Narration</th></tr></thead><tbody>';
    for ($i = 0; $i < 6; $i++) {
        echo '<tr><td><select name="acct[' . $i . ']"><option value="">—</option>';
        foreach ($accts as $k => $v) echo '<option value="' . $k . '">' . e($v) . '</option>';
        echo '</select></td><td><input type="text" inputmode="numeric" class="money" name="dr[' . $i . ']"></td><td><input type="text" inputmode="numeric" class="money" name="cr[' . $i . ']"></td><td><input type="text" name="lm[' . $i . ']"></td></tr>';
    }
    echo '</tbody></table></div><div class="form-actions">'; submit_btn('Post journal'); echo '</div>'; form_close(); echo '</div>';
    return;
}
throw new AppError('Unknown ledger view.');
