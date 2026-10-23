<?php
$title = 'Expenses & cashbook';
$a = get('a');
$tab = get('tab', 'expenses');

if ($a === 'add') post_action(function () {
    $id = Cash::addExpense($_POST);
    return 'Expense ' . val('SELECT expense_no FROM expenses WHERE id = ?', [$id]) . ' recorded (' . val('SELECT status FROM expenses WHERE id = ?', [$id]) . ').';
});
if ($a === 'approve') post_action(function () { Cash::approveExpense((int)post('id')); return 'Expense approved. It can now be paid from a till or account.'; });
if ($a === 'reject') post_action(function () { Cash::rejectExpense((int)post('id'), clean(post('reason'))); return 'Expense rejected.'; });
if ($a === 'pay') post_action(function () { Cash::pay((int)post('id')); return 'Expense paid.'; });
if ($a === 'reverse') post_action(function () { Cash::reverseExpense((int)post('id'), clean(post('reason'))); return 'Expense reversed (original retained).'; });
if ($a === 'category') post_action(function () {
    require_perm('settings.manage');
    $n = clean(post('name'));
    must($n !== '', 'Category name is required.');
    if (post('toggle')) { q('UPDATE expense_categories SET active = 1 - active WHERE id = ?', [(int)post('toggle')]); audit('Expense category toggled', 'Settings', 'expense_category', post('toggle')); return 'Category updated.'; }
    q('INSERT INTO expense_categories(name) VALUES (?)', [$n]);
    audit('Expense category added', 'Settings', 'expense_category', $n);
    return 'Category added.';
});

page_head('Expenses & cashbook', '', 'Loan principal is never treated as an expense, and repayments are split into principal, interest, fees and penalties.');
$tabs = ['expenses' => 'Expenses', 'new' => 'Record expense', 'cashbook' => 'Cashbook'];
if (can('settings.manage')) $tabs['categories'] = 'Categories';
tabs($tabs, $tab);

if ($tab === 'new') {
    if (!can('expense.create')) throw new AppError('You do not have permission to perform this action.');
    $cats = []; foreach (rows('SELECT id, name FROM expense_categories WHERE active = 1 ORDER BY name') as $c) $cats[$c['id']] = $c['name'];
    $lim = setting_int('expense_approval_limit');
    echo '<div class="card"><h2>Record an operating expense</h2><p class="muted">Expenses up to ' . ugx($lim) . ' are paid immediately; larger amounts wait for approval. Cash expenses come out of your open till.</p>';
    form_open(url('cashbook', ['a' => 'add', 'tab' => 'expenses']));
    echo token_field();
    field('category_id', 'Category', ['type' => 'select', 'options' => $cats, 'blank' => true, 'required' => true]);
    field('description', 'Description', ['required' => true, 'span' => 2]);
    field('amount', 'Amount (UGX)', ['type' => 'money', 'required' => true]);
    field('method', 'Paid by', ['type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)]);
    field('reference', 'Supporting reference (invoice / receipt no.)', ['span' => 2]);
    echo '<div class="form-actions">'; submit_btn('Save expense'); echo '</div>'; form_close(); echo '</div>';
}

if ($tab === 'expenses') {
    $from = get('from') ?: date('Y-m-d', strtotime('-30 days', strtotime(reporting_date()))); $to = get('to') ?: reporting_date(); $st = get('status'); $cat = (int)get('cat');
    $w = ['e.business_date BETWEEN ? AND ?']; $p = [$from, $to];
    if ($st !== '') { $w[] = 'e.status = ?'; $p[] = $st; }
    if ($cat) { $w[] = 'e.category_id = ?'; $p[] = $cat; }
    $cats = []; foreach (rows('SELECT id, name FROM expense_categories ORDER BY name') as $c) $cats[$c['id']] = $c['name'];
    $list = rows("SELECT e.*, c.name AS category, u.full_name AS by_name, a.full_name AS approver FROM expenses e JOIN expense_categories c ON c.id = e.category_id JOIN users u ON u.id = e.entered_by
                  LEFT JOIN users a ON a.id = e.approved_by WHERE " . implode(' AND ', $w) . ' ORDER BY e.id DESC LIMIT 500', $p);
    filter_bar(['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'], 'cat' => ['label' => 'Category', 'type' => 'select', 'options' => $cats],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => array_combine($s = ['Pending', 'Approved', 'Paid', 'Rejected', 'Reversed'], $s)]], ['from' => $from, 'to' => $to, 'cat' => $cat ?: '', 'status' => $st], 'cashbook', ['tab' => 'expenses']);
    $paid = array_sum(array_map(fn($r) => $r['status'] === 'Paid' ? $r['amount'] : 0, $list));
    echo '<div class="kpis">' . kpi('Paid in period', ugx($paid), '', 'ok') . kpi('Pending / approved unpaid', ugx(array_sum(array_map(fn($r) => in_array($r['status'], ['Pending', 'Approved'], true) ? $r['amount'] : 0, $list))), '', 'warn') . '</div>';
    data_table(['expense_no' => 'No.', 'business_date' => ['Date', 'date'], 'category' => 'Category', 'description' => 'Description', 'amount' => ['Amount', 'money'], 'method' => 'Method', 'by_name' => 'Entered by', 'approver' => 'Approved by',
        'status' => ['Status', 'badge'],
        'act' => ['', function ($r) {
            $o = '';
            $f = fn($act, $label, $cls = '', $needReason = false, $conf = '') => '<form method="post" action="' . e(url('cashbook', ['a' => $act])) . '" style="display:inline-flex;gap:.2rem" ' . ($conf ? 'data-confirm="' . e($conf) . '"' : '') . '>' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">' . ($needReason ? '<input type="text" name="reason" placeholder="reason" required style="width:100px">' : '') . '<button class="btn sm ' . $cls . '">' . $label . '</button></form> ';
            if ($r['status'] === 'Pending' && can('expense.approve')) $o .= $f('approve', 'Approve') . $f('reject', 'Reject', 'danger', true);
            if ($r['status'] === 'Approved' && (can('expense.create') || can('till.use'))) $o .= $f('pay', 'Pay now', 'primary', false, 'Pay this expense now?');
            if ($r['status'] === 'Approved' && can('expense.approve')) $o .= $f('reject', 'Reject', 'danger', true);
            if ($r['status'] === 'Paid' && can('expense.approve')) $o .= $f('reverse', 'Reverse', 'danger', true, 'Reverse this expense? The original stays on record.');
            return $o;
        }]], $list, ['empty' => 'No expenses in this period.', 'totals' => ['amount']]);
}

if ($tab === 'cashbook') {
    $from = get('from') ?: date('Y-m-d', strtotime('-7 days', strtotime(reporting_date()))); $to = get('to') ?: reporting_date(); $meth = get('method'); $cat = get('category');
    $w = ['m.business_date BETWEEN ? AND ?']; $p = [$from, $to];
    if ($meth !== '') { $w[] = 'm.method = ?'; $p[] = $meth; }
    if ($cat !== '') { $w[] = 'm.category = ?'; $p[] = $cat; }
    $mv = rows("SELECT m.*, u.full_name AS by_name, s.session_no, a.name AS account FROM cash_movements m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN cashier_sessions s ON s.id = m.session_id
                LEFT JOIN cash_accounts a ON a.id = m.account_id WHERE " . implode(' AND ', $w) . ' ORDER BY m.id DESC LIMIT 1000', $p);
    filter_bar(['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'], 'method' => ['label' => 'Method', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)],
        'category' => ['label' => 'Category', 'type' => 'select', 'options' => array_combine($c = ['float', 'collection', 'disbursement', 'expense', 'transfer', 'reversal', 'adjustment'], $c)]], ['from' => $from, 'to' => $to, 'method' => $meth, 'category' => $cat], 'cashbook', ['tab' => 'cashbook']);
    $sum = rows("SELECT category, method, SUM(CASE WHEN direction = 1 THEN amount ELSE 0 END) AS money_in, SUM(CASE WHEN direction = -1 THEN amount ELSE 0 END) AS money_out
                 FROM cash_movements m WHERE " . implode(' AND ', $w) . ' GROUP BY category, method ORDER BY category, method', $p);
    $labels = ['collection' => 'Loan repayments received', 'disbursement' => 'Loan disbursements (net released)', 'expense' => 'Operating expenses', 'float' => 'Opening floats', 'transfer' => 'Transfers', 'reversal' => 'Reversals', 'adjustment' => 'Other (refunds)'];
    foreach ($sum as &$s) $s['label'] = $labels[$s['category']] ?? $s['category'];
    unset($s);
    echo '<div class="grid g2"><div class="card"><h2>Summary by type</h2>';
    data_table(['label' => 'Type', 'method' => 'Method', 'money_in' => ['In', 'money'], 'money_out' => ['Out', 'money']], $sum, ['class' => 'nofilter', 'totals' => ['money_in', 'money_out'], 'empty' => 'No movements.']);
    echo '</div><div class="card"><h2>Account balances (all time)</h2>';
    $bal = rows("SELECT a.name, a.type, COALESCE(SUM(m.direction*m.amount),0) AS balance FROM cash_accounts a LEFT JOIN cash_movements m ON m.account_id = a.id GROUP BY a.id ORDER BY a.id");
    data_table(['name' => 'Account', 'type' => 'Type', 'balance' => ['Net movements', 'money']], $bal, ['class' => 'nofilter']);
    echo '<p class="muted" style="margin-top:.5rem">Till cash is tracked per cashier session (Cashier & Till). Principal, interest, fees and penalties stay separate in the loan ledger and reports.</p></div></div>';
    echo '<div class="card"><h2>Cashbook entries</h2>';
    data_table(['business_date' => ['Business date', 'date'], 'created_at' => ['Time', 'dt'], 'category' => 'Type', 'description' => 'Description', 'method' => 'Method', 'session_no' => 'Till', 'account' => 'Account', 'reference' => 'Reference',
        'in' => ['In', fn($r) => $r['direction'] == 1 ? num($r['amount']) : '', 'r'], 'out' => ['Out', fn($r) => $r['direction'] == -1 ? num($r['amount']) : '', 'r'], 'cash' => ['Cash?', fn($r) => $r['is_cash'] ? 'cash' : 'non-cash'], 'by_name' => 'By'], $mv, ['empty' => 'No movements in this period.']);
    echo '</div>';
}

if ($tab === 'categories' && can('settings.manage')) {
    echo '<div class="grid g2"><div class="card"><h2>Expense categories</h2>';
    $cs = rows('SELECT * FROM expense_categories ORDER BY name');
    data_table(['name' => 'Category', 'active' => ['Status', fn($r) => $r['active'] ? badge('Active') : badge('Archived')],
        'act' => ['', fn($r) => '<form method="post" action="' . e(url('cashbook', ['a' => 'category', 'tab' => 'categories'])) . '">' . csrf_field() . '<input type="hidden" name="name" value="x"><input type="hidden" name="toggle" value="' . (int)$r['id'] . '"><button class="btn sm">' . ($r['active'] ? 'Deactivate' : 'Activate') . '</button></form>']], $cs, ['class' => 'nofilter']);
    echo '</div><div class="card"><h2>Add category</h2>';
    form_open(url('cashbook', ['a' => 'category', 'tab' => 'categories']), ['attrs' => 'style="grid-template-columns:1fr"']);
    field('name', 'Category name', ['required' => true]); echo '<div class="form-actions">'; submit_btn('Add'); echo '</div>'; form_close();
    echo '</div></div>';
}
