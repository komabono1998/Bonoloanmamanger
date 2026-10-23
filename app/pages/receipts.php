<?php
$title = 'Receipts';
$a = get('a', 'list');

if ($a === 'view') {
    $pid = (int)get('id');
    $r = Payments::receipt($pid);
    $printing = get('print') === '1';
    $isCopy = (int)$r['print_count'] > 0;            // anything after the first print is a COPY
    if ($printing) {
        $isCopy = Payments::markPrinted($pid);        // original on first print, copy afterwards (needs reprint permission)
    }
    page_head('Receipt ' . $r['receipt_no'], btn('Print receipt', url('receipts', ['a' => 'view', 'id' => $pid, 'print' => 1]), 'primary') . btn('All receipts', url('receipts')) . btn('Loan file', url('loan', ['id' => $r['loan_id']])));
    if ($r['receipt_status'] === 'Void') echo '<div class="notice bad">This receipt is VOID — the payment was reversed (' . e($r['void_reason']) . '). The original record is retained.</div>';
    require BASE_PATH . '/app/views/receipt_doc.php';
    if ($printing) echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})</script>';
    return;
}

page_head('Receipts', '', 'Every successful collection has a unique, controlled receipt number. Reprints are marked COPY.');
$q = get('q'); $from = get('from'); $to = get('to');
$w = ['1=1']; $p = [];
if ($q !== '') { $w[] = '(r.receipt_no LIKE ? OR l.loan_no LIKE ? OR c.full_name LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
if (is_date($from)) { $w[] = 'p.business_date >= ?'; $p[] = $from; }
if (is_date($to)) { $w[] = 'p.business_date <= ?'; $p[] = $to; }
$list = rows("SELECT p.id, r.receipt_no, r.status AS rstatus, r.print_count, p.business_date, l.loan_no, c.full_name, p.amount, p.method, u.full_name AS cashier
              FROM receipts r JOIN payments p ON p.id = r.payment_id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id JOIN users u ON u.id = p.cashier_id
              WHERE " . implode(' AND ', $w) . ' ORDER BY r.id DESC LIMIT 500', $p);
filter_bar(['q' => ['label' => 'Search', 'placeholder' => 'Receipt, loan or client'], 'from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date']], ['q' => $q, 'from' => $from, 'to' => $to], 'receipts');
data_table(['receipt_no' => 'Receipt no.', 'business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'amount' => ['Amount', 'money'], 'method' => 'Method', 'cashier' => 'Cashier',
    'print_count' => ['Printed', 'num'], 'rstatus' => ['Status', 'badge']], $list, ['link' => fn($r) => url('receipts', ['a' => 'view', 'id' => $r['id']]), 'empty' => 'No receipts.']);
