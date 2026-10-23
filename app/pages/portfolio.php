<?php
$title = 'Loan portfolio';
Loans::refreshStatuses();
$asOf = reporting_date();
$q = get('q'); $st = get('status', 'open'); $of = (int)get('officer'); $soon = get('soon');
$w = ['l.disbursed_at IS NOT NULL']; $p = [];
if ($st === 'open') $w[] = "l.status IN ('Active','Overdue')"; elseif ($st !== '' && $st !== 'all') { $w[] = 'l.status = ?'; $p[] = $st; }
if ($q !== '') { $w[] = '(l.loan_no LIKE ? OR c.full_name LIKE ? OR c.client_no LIKE ? OR c.phone LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($of) { $w[] = 'l.officer_id = ?'; $p[] = $of; }
if ($soon === '1') { $w[] = "l.status = 'Active' AND l.due_date BETWEEN ? AND ?"; array_push($p, $asOf, Calc::addDays($asOf, 7)); }
$list = rows("SELECT l.id, l.loan_no, c.client_no, c.full_name, l.approved_principal, l.disbursement_date, l.due_date, l.interest_amount, l.total_contractual, b.principal_out, b.interest_out, b.penalty_out, b.fee_out, b.total_out,
              b.paid_total, l.overdue_since, l.status, u.full_name AS officer, l.collection_status,
              CASE WHEN l.overdue_since IS NULL THEN 0 ELSE CAST(julianday(?) - julianday(l.overdue_since) AS INTEGER) END AS days_overdue
              FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN users u ON u.id = l.officer_id
              WHERE " . implode(' AND ', $w) . ' ORDER BY l.due_date, l.id LIMIT 1000', array_merge([$asOf], $p));
page_head('Loan portfolio', '', 'Register of all disbursed loans with balances derived from the transaction ledger.');
$offs = []; foreach (rows('SELECT id, full_name FROM users ORDER BY full_name') as $u) $offs[$u['id']] = $u['full_name'];
filter_bar(['q' => ['label' => 'Search', 'placeholder' => 'Loan, client, phone'],
    'status' => ['label' => 'Show', 'type' => 'select', 'all' => 'All disbursed', 'options' => ['open' => 'Open (Active + Overdue)', 'Active' => 'Active', 'Overdue' => 'Overdue', 'Closed' => 'Closed']],
    'soon' => ['label' => 'Maturing', 'type' => 'select', 'all' => 'Any time', 'options' => ['1' => 'Within 7 days']],
    'officer' => ['label' => 'Officer', 'type' => 'select', 'options' => $offs]], ['q' => $q, 'status' => $st === 'all' ? '' : $st, 'soon' => $soon, 'officer' => $of ?: ''], 'portfolio');
$tot = ['p' => 0, 'i' => 0, 'pen' => 0, 't' => 0];
foreach ($list as $r) { $tot['p'] += $r['principal_out']; $tot['i'] += $r['interest_out']; $tot['pen'] += $r['penalty_out']; $tot['t'] += $r['total_out']; }
echo '<div class="kpis">' . kpi('Loans listed', num(count($list))) . kpi('Outstanding principal', ugx($tot['p'])) . kpi('Interest outstanding', ugx($tot['i'])) . kpi('Penalties outstanding', ugx($tot['pen'])) . kpi('Total outstanding', ugx($tot['t']), '', 'warn') . '</div>';
data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'approved_principal' => ['Principal', 'money'], 'disbursement_date' => ['Disbursed', 'date'], 'due_date' => ['Due', 'date'],
    'total_contractual' => ['Contractual', 'money'], 'paid_total' => ['Paid', 'money'], 'principal_out' => ['Princ. out', 'money'], 'interest_out' => ['Int. out', 'money'], 'penalty_out' => ['Penalty out', 'money'],
    'total_out' => ['Total out', 'money'], 'days_overdue' => ['Days overdue', fn($r) => $r['days_overdue'] > 0 ? '<span class="ico-bad">' . (int)$r['days_overdue'] . '</span>' : '0', 'r'], 'status' => ['Status', 'badge'], 'officer' => 'Officer'],
    $list, ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'No loans match.']);
