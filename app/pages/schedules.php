<?php
$title = 'Repayment schedules';
page_head('Repayment schedules', '', 'Generated at disbursement from the approved terms. Open a loan to view or print its schedule.');
$q = get('q'); $st = get('status', 'open');
$w = ['l.disbursed_at IS NOT NULL', branch_scope('l.branch_id')]; $p = [];
if ($st === 'open') $w[] = "l.status IN ('Active','Overdue')"; elseif ($st === 'Closed') $w[] = "l.status = 'Closed'";
if ($q !== '') { $w[] = '(l.loan_no LIKE ? OR c.full_name LIKE ? OR c.client_no LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
$list = rows("SELECT l.id, l.loan_no, c.full_name, l.approved_principal, l.interest_amount, (l.approved_principal + l.interest_amount) AS total_pi, l.disbursement_date, l.due_date, l.frequency,
              (SELECT COUNT(*) FROM repayment_schedules s WHERE s.loan_id = l.id AND s.status='Current') AS n, l.status, b.total_out
              FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id WHERE " . implode(' AND ', $w) . ' ORDER BY l.due_date LIMIT 500', $p);
filter_bar(['q' => ['label' => 'Search', 'placeholder' => 'Loan, client name or ID'], 'status' => ['label' => 'Show', 'type' => 'select', 'all' => 'All disbursed', 'options' => ['open' => 'Open loans', 'Closed' => 'Closed loans']]], ['q' => $q, 'status' => $st], 'schedules');
data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'approved_principal' => ['Principal', 'money'], 'interest_amount' => ['Interest', 'money'], 'total_pi' => ['Total P+I', 'money'],
    'disbursement_date' => ['Disbursed', 'date'], 'due_date' => ['Maturity', 'date'], 'n' => ['Instalments', 'num'], 'status' => ['Status', 'badge'], 'total_out' => ['Outstanding', 'money'],
    'pr' => ['', fn($r) => '<a class="btn sm" target="_blank" href="' . e(url('print', ['doc' => 'schedule', 'id' => $r['id']])) . '">Print</a>']],
    $list, ['link' => fn($r) => url('loan', ['id' => $r['id'], 'tab' => 'schedule']), 'empty' => 'No disbursed loans.']);
