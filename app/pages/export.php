<?php
/** Download endpoint: CSV (UTF-8 with BOM, opens in Excel) or Excel 2003 XML, with permission checks and an export log. */
$kind = get('kind');
$format = get('format', 'csv') === 'xls' ? 'xls' : 'csv';

$data = null; $name = 'export';
if ($kind === 'report') {
    require_perm('report.export');
    $cat = Reports::catalogue();
    $key = get('r');
    must(isset($cat[$key]), 'Unknown report.');
    must(report_allowed($key), 'You have not been given access to this report.');
    $asOf = reporting_date();
    $days = max(1, setting_int('report_default_days'));
    $f = ['from' => is_date(get('from')) ? get('from') : date('Y-m-d', strtotime("-$days days", strtotime($asOf))), 'to' => is_date(get('to')) ? get('to') : $asOf,
        'officer' => (int)get('officer'), 'cashier' => (int)get('cashier'), 'method' => get('method'), 'client' => (int)get('client'), 'branch' => (int)get('branch')];
    $rep = Reports::run($key, $f);
    $data = Reports::plain($rep);
    $name = $key . '_' . $f['from'] . '_' . $f['to'];
} elseif ($kind === 'audit') {
    require_perm('audit.all'); require_perm('report.export');
    $w = ['substr(at,1,10) BETWEEN ? AND ?']; $p = [is_date(get('from')) ? get('from') : '2000-01-01', is_date(get('to')) ? get('to') : today()];
    if ((int)get('user')) { $w[] = 'user_id = ?'; $p[] = (int)get('user'); }
    if (get('module') !== '') { $w[] = 'module = ?'; $p[] = get('module'); }
    $rs = rows('SELECT at, username, business_date, module, action, record_type, record_id, prev_value, new_value, reason, approval_ref, ip FROM audit_logs WHERE ' . implode(' AND ', $w) . ' ORDER BY id', $p);
    $data = [['When', 'User', 'Business date', 'Module', 'Action', 'Record type', 'Record', 'Before', 'After', 'Reason', 'Approval ref', 'IP'], array_map('array_values', $rs)];
    $name = 'audit_trail';
} else {
    require_perm('export.run');
    $queries = [
        'clients' => ['SELECT client_no, full_name, gender, dob, nin, phone, phone2, address, village, parish, subcounty, county, district, occupation, employer, business_name, monthly_income, monthly_expenses, other_debt, status, registered_at FROM clients WHERE ' . branch_scope('branch_id') . ' ORDER BY id',
            ['Client ID', 'Name', 'Gender', 'DOB', 'NIN', 'Phone', 'Phone 2', 'Address', 'Village', 'Parish', 'Sub-county', 'County', 'District', 'Occupation', 'Employer', 'Business', 'Monthly income', 'Monthly expenses', 'Other debt', 'Status', 'Registered']],
        'loans' => ["SELECT l.loan_no, c.client_no, c.full_name, l.application_date, l.amount_requested, l.approved_principal, l.rate_bp/100.0 AS rate_pct, l.interest_amount, l.total_fees, l.net_disbursement, l.disbursement_date, l.due_date, b.principal_out, b.interest_out, b.penalty_out, b.total_out, l.status
            FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id WHERE " . branch_scope('l.branch_id') . " ORDER BY l.id",
            ['Loan', 'Client ID', 'Client', 'Applied', 'Requested', 'Approved', 'Rate %', 'Interest', 'Total fees', 'Net disbursed', 'Disbursed', 'Due', 'Principal out', 'Interest out', 'Penalty out', 'Total out', 'Status']],
        'payments' => ["SELECT r.receipt_no, p.business_date, l.loan_no, c.full_name, p.amount, p.method, p.reference, u.full_name, p.status, p.reversal_reason FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id JOIN users u ON u.id = p.cashier_id WHERE " . branch_scope('p.branch_id') . " ORDER BY p.id",
            ['Receipt', 'Business date', 'Loan', 'Client', 'Amount', 'Method', 'Reference', 'Cashier', 'Status', 'Reversal reason']],
        'schedules' => ["SELECT l.loan_no, c.full_name, s.installment_no, s.due_date, s.principal_due, s.interest_due, s.total_due FROM repayment_schedules s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = l.client_id WHERE s.status = 'Current' AND " . branch_scope('l.branch_id') . " ORDER BY l.id, s.installment_no",
            ['Loan', 'Client', 'Instalment', 'Due date', 'Principal', 'Interest', 'Total']],
        'receipts' => ["SELECT r.receipt_no, r.issued_at, r.status, r.print_count, p.amount, p.method, l.loan_no, c.full_name FROM receipts r JOIN payments p ON p.id = r.payment_id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id WHERE " . branch_scope('p.branch_id') . " ORDER BY r.id",
            ['Receipt', 'Issued', 'Status', 'Printed', 'Amount', 'Method', 'Loan', 'Client']],
        'cashbook' => ["SELECT business_date, created_at, category, method, CASE WHEN direction=1 THEN amount ELSE 0 END, CASE WHEN direction=-1 THEN amount ELSE 0 END, is_cash, reference, description FROM cash_movements WHERE " . branch_scope('branch_id') . " ORDER BY id",
            ['Business date', 'Time', 'Type', 'Method', 'In', 'Out', 'Cash (1/0)', 'Reference', 'Description']],
        'eod' => ["SELECT business_date, version, status, submitted_at, reviewed_at, review_notes, backup_status FROM eod_records WHERE " . branch_scope('branch_id') . " ORDER BY business_date, version", ['Business date', 'Version', 'Status', 'Submitted', 'Reviewed', 'Notes', 'Backup']],
    ];
    must(isset($queries[$kind]), 'Unknown export.');
    $stmt = q($queries[$kind][0]);
    $data = [$queries[$kind][1], $stmt->fetchAll(PDO::FETCH_NUM)];
    $name = $kind;
}

[$head, $body] = $data;
insert('export_history', ['at' => now(), 'user_id' => uid(), 'kind' => $kind . ($kind === 'report' ? ':' . get('r') : ''), 'format' => $format, 'rows_total' => count($body)]);
audit('Data exported', 'Export', 'export', $kind, null, count($body) . ' rows (' . $format . ')');
while (ob_get_level() > 0) ob_end_clean();
$file = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '_' . date('Ymd_His');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $head);
    foreach ($body as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));   // neutralise spreadsheet formulas
    fclose($out);
} else {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.xml"');
    $cell = function ($v) {
        $t = is_numeric($v) && !preg_match('/^0\d/', (string)$v) ? 'Number' : 'String';
        return '<Cell><Data ss:Type="' . $t . '">' . htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
    };
    echo '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="h"><Font ss:Bold="1"/></Style></Styles>'
        . '<Worksheet ss:Name="' . htmlspecialchars(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $name) ?: 'Data', 0, 30), ENT_XML1) . '"><Table>';
    foreach (letterhead_lines() as $i => $line) echo '<Row' . ($i === 0 ? ' ss:StyleID="h"' : '') . '><Cell><Data ss:Type="String">' . htmlspecialchars($line, ENT_XML1) . '</Data></Cell></Row>';
    echo '<Row><Cell><Data ss:Type="String">' . htmlspecialchars($name . ' · generated ' . now(), ENT_XML1) . '</Data></Cell></Row><Row/><Row ss:StyleID="h">';
    foreach ($head as $h) echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$h, ENT_XML1) . '</Data></Cell>';
    echo '</Row>';
    foreach ($body as $r) { echo '<Row>'; foreach ($r as $v) echo $cell($v); echo '</Row>'; }
    echo '</Table></Worksheet></Workbook>';
}
exit;
