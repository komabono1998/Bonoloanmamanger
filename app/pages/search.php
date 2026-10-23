<?php
/** Global search: clients, loans and receipts, limited to the user's branches and permissions. */
$title = 'Search';
$q = clean(get('q'));
page_head('Search', '', $q === '' ? 'Type a client name, Client ID, phone, loan number or receipt number in the box at the top.' : 'Results for "' . $q . '"');
if ($q === '' || mb_strlen($q) < 2) { echo '<div class="card"><p class="muted">Enter at least two characters.</p></div>'; return; }
$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
$found = 0;

if (can('client.view')) {
    $cs = rows('SELECT id, client_no, full_name, phone, status FROM clients WHERE ' . branch_scope('branch_id') . " AND (full_name LIKE ? ESCAPE '\\' OR client_no LIKE ? ESCAPE '\\' OR phone LIKE ? ESCAPE '\\' OR phone2 LIKE ? ESCAPE '\\') ORDER BY full_name LIMIT 15", [$like, $like, $like, $like]);
    $found += count($cs);
    if ($cs) { echo '<div class="card"><h2>Clients</h2>'; data_table(['client_no' => 'Client ID', 'full_name' => 'Name', 'phone' => 'Phone', 'status' => ['Status', 'badge']], $cs, ['class' => 'nofilter', 'link' => fn($r) => url('clients', ['a' => 'view', 'id' => $r['id']])]); echo '</div>'; }
}
if (can('loan.view')) {
    $ls = rows('SELECT l.id, l.loan_no, c.full_name, l.status, l.application_date FROM loans l JOIN clients c ON c.id = l.client_id WHERE ' . branch_scope('l.branch_id') . " AND (l.loan_no LIKE ? ESCAPE '\\' OR c.full_name LIKE ? ESCAPE '\\') ORDER BY l.id DESC LIMIT 15", [$like, $like]);
    $found += count($ls);
    if ($ls) { echo '<div class="card"><h2>Loans</h2>'; data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'application_date' => ['Applied', 'date'], 'status' => ['Status', 'badge']], $ls, ['class' => 'nofilter', 'link' => fn($r) => url('loan', ['id' => $r['id']])]); echo '</div>'; }
}
if (can('payment.view')) {
    $rs = rows('SELECT r.receipt_no, p.amount, p.business_date, p.loan_id, c.full_name, p.status FROM receipts r JOIN payments p ON p.id = r.payment_id JOIN clients c ON c.id = p.client_id WHERE ' . branch_scope('p.branch_id') . " AND (r.receipt_no LIKE ? ESCAPE '\\' OR p.reference LIKE ? ESCAPE '\\') ORDER BY r.id DESC LIMIT 15", [$like, $like]);
    $found += count($rs);
    if ($rs) { echo '<div class="card"><h2>Receipts</h2>'; data_table(['receipt_no' => 'Receipt', 'full_name' => 'Client', 'amount' => ['Amount', 'money'], 'business_date' => ['Date', 'date'], 'status' => ['Status', 'badge']], $rs, ['class' => 'nofilter', 'link' => fn($r) => url('loan', ['id' => $r['loan_id']])]); echo '</div>'; }
}
if (!$found) echo '<div class="card"><p class="muted">Nothing matched. Try part of a name, a phone number or a loan / receipt number.</p></div>';
