<?php
/** Printable documents (A4). Use the browser's Print → Save as PDF to produce a PDF file. */
$layout = 'print';
$doc = get('doc');
$id = (int)get('id');

function doc_head(string $title): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title . ' · ' . business_name()) . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('style.css')) . '"></head><body style="background:#e9edf1">'
        . '<div class="print-bar no-print"><button class="btn primary" data-print>Print / Save as PDF</button><button class="btn" data-back>Back</button></div>';
}
function doc_foot(): void { echo (get('autoprint') === '1' ? '<div data-autoprint></div>' : '') . '<script src="' . e(asset('app.js')) . '"></script></body></html>'; }
function doc_biz(string $heading, string $sub = ''): void
{
    echo '<div class="biz">' . (setting('business_logo') ? '<img src="' . e(attachment_url((int)setting('business_logo'))) . '" style="height:54px" alt="">' : '')
        . '<h1>' . e(business_name()) . '</h1><small>' . e(trim(setting('business_address') . ' · ' . setting('business_phone') . ' · ' . setting('business_email'), ' ·')) . '</small></div>'
        . '<h2 style="text-align:center;font-size:17px;border:0;margin:10px 0 4px">' . e($heading) . '</h2>' . ($sub ? '<p style="text-align:center;margin:0 0 8px">' . e($sub) . '</p>' : '');
}
function kv(array $pairs): string
{
    $o = '<div class="kv2">';
    foreach ($pairs as $k => $v) $o .= '<div><b>' . e($k) . '</b> ' . ($v === '' || $v === null ? '—' : e($v)) . '</div>';
    return $o . '</div>';
}
function sigs(array $labels): string
{
    return '<div class="sig">' . implode('', array_map(fn($l) => '<div>' . e($l) . '</div>', $labels)) . '</div>';
}

switch ($doc) {
    case 'offer': {
        require_perm('loan.view');
        $l = Loans::get($id);
        must((bool)$l['approved_principal'], 'This loan has not been approved yet.');
        doc_head('Loan offer ' . $l['loan_no']);
        echo '<div class="doc">'; doc_biz('LOAN APPLICATION AND OFFER ACCEPTANCE FORM', 'Loan no. ' . $l['loan_no']);
        $c = row('SELECT * FROM clients WHERE id = ?', [$l['client_id']]);
        echo '<h2>Borrower</h2>' . kv(['Name' => $c['full_name'], 'Client ID' => $c['client_no'], 'Phone' => $c['phone'], 'NIN' => $c['nin'], 'Address' => trim($c['address'] . ' ' . $c['village'] . ' ' . $c['district']), 'Occupation / business' => trim($c['occupation'] . ' ' . $c['business_name'])]);
        echo '<h2>Application</h2>' . kv(['Application date' => fmt_date($l['application_date']), 'Purpose' => $l['purpose'], 'Amount requested' => ugx($l['amount_requested']), 'Loan officer' => $l['officer_name']]);
        echo '<h2>Offer — approved terms</h2><table><tr><th>Item</th><th class="r">Amount (UGX)</th></tr>'
            . '<tr><td>Approved principal</td><td class="r">' . num($l['approved_principal']) . '</td></tr>'
            . '<tr><td>Interest (' . e(bp_to_pct((int)$l['rate_bp'])) . '% per month, flat, ' . (int)$l['period_months'] . ' month(s))</td><td class="r">' . num($l['interest_amount']) . '</td></tr>'
            . '<tr><th>Total principal plus interest</th><th class="r">' . num($l['approved_principal'] + $l['interest_amount']) . '</th></tr>'
            . '<tr><td>Processing fee (' . e(bp_to_pct((int)$l['processing_fee_bp'])) . '%)</td><td class="r">' . num($l['processing_fee']) . '</td></tr>'
            . '<tr><td>Application fee</td><td class="r">' . num($l['application_fee']) . '</td></tr><tr><td>Inspection fee</td><td class="r">' . num($l['inspection_fee']) . '</td></tr>'
            . ($l['other_fees'] ? '<tr><td>Other authorized deductions</td><td class="r">' . num($l['other_fees']) . '</td></tr>' : '')
            . '<tr><th>Total fees (' . ($l['fees_deducted'] ? 'deducted from the loan' : 'payable separately') . ')</th><th class="r">' . num($l['total_fees']) . '</th></tr>'
            . '<tr><th>Net amount to be released</th><th class="r">' . num($l['net_disbursement']) . '</th></tr></table>';
        $pen = Penalty::termsFor($l);
        echo '<p>The loan is repayable in full by <b>' . ($l['due_date'] ? fmt_date($l['due_date']) : '1 calendar month after disbursement') . '</b>. '
            . (!empty($pen['enabled']) ? 'Overdue amounts attract a penalty of ' . e(bp_to_pct((int)$pen['rate_bp'])) . '% per day on the ' . e(str_replace('_', ' ', $pen['basis'])) . '. ' : '')
            . 'This offer is valid until <b>' . e(fmt_date($l['offer_expiry'])) . '</b> (' . setting_int('offer_validity_days') . ' working days).</p>';
        $gs = rows("SELECT g.surname || ' ' || g.first_name AS n, g.phone FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id WHERE lg.loan_id = ? AND lg.status = 'Active'", [$id]);
        $ss = rows("SELECT type, description, est_value FROM securities WHERE loan_id = ?", [$id]);
        echo '<h2>Guarantors and security</h2><p>Guarantors: ' . ($gs ? e(implode('; ', array_map(fn($g) => $g['n'] . ' (' . $g['phone'] . ')', $gs))) : 'none') . '</p><p>Security: '
            . ($ss ? e(implode('; ', array_map(fn($s) => $s['type'] . ' — ' . $s['description'] . ' (' . ugx($s['est_value']) . ')', $ss))) : 'none') . '</p>';
        echo '<h2>Borrower\'s response</h2><p>☐ I <b>ACCEPT</b> this offer on the terms above &nbsp;&nbsp;&nbsp; ☐ I <b>REJECT</b> this offer</p>' . ($l['acceptance_status'] !== 'Pending' ? '<p>Recorded in the system: ' . e($l['acceptance_status']) . ' on ' . e(fmt_date($l['acceptance_date'])) . '</p>' : '')
            . sigs(['Borrower signature & date', 'Loan officer (' . $l['officer_name'] . ')', 'Approving manager']) . '</div>';
        doc_foot();
        break;
    }
    case 'guarantor': {
        require_perm('loan.view');
        $l = Loans::get($id);
        $gs = rows("SELECT g.*, lg.guarantee_amount, lg.guarantee_date, lg.liability_terms, lg.witness_name, lg.witness_phone FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id WHERE lg.loan_id = ? AND lg.status = 'Active'", [$id]);
        must((bool)$gs, 'No guarantors are linked to this loan.');
        doc_head('Guarantor form ' . $l['loan_no']);
        foreach ($gs as $g) {
            echo '<div class="doc" style="page-break-after:always">'; doc_biz("GUARANTOR'S FORM", 'Loan ' . $l['loan_no'] . ' · Borrower: ' . $l['client_name'] . ' (' . $l['client_no'] . ')');
            echo '<h2>Guarantor</h2>' . kv(['Surname' => $g['surname'], 'First name' => $g['first_name'], 'Guarantor ID' => $g['guarantor_no'], 'Gender' => $g['gender'], 'Date of birth' => fmt_date($g['dob']), 'Phone' => $g['phone'], 'NIN' => $g['nin'],
                'Residence' => trim($g['address'] . ' ' . $g['village']), 'Parish / ward' => $g['parish'], 'Sub-county' => $g['subcounty'], 'District / city' => $g['district'], 'Housing status' => $g['housing_status'], 'Landlord contact' => $g['landlord_contact'],
                'Marital status' => $g['marital_status'], 'Spouse' => trim($g['spouse_name'] . ' ' . $g['spouse_phone']), 'Next of kin' => trim($g['nok_name'] . ' ' . $g['nok_phone'])]);
            echo '<h2>Guarantee</h2>' . kv(['Loan amount' => ugx($l['approved_principal'] ?: $l['amount_requested']), 'Guarantee limit' => $g['guarantee_amount'] ? ugx($g['guarantee_amount']) : 'As per signed terms', 'Date' => fmt_date($g['guarantee_date']), 'Terms' => $g['liability_terms']]);
            echo '<p style="margin-top:12px">I, the undersigned, confirm that the details above are correct and that I have read and understood the terms of the guarantee. My liability is as stated in this signed agreement and the law.</p>';
            echo sigs(['Guarantor signature & date', 'Witness: ' . ($g['witness_name'] ?: '__________') . ' ' . $g['witness_phone'], 'Loan officer']) . '</div>';
        }
        doc_foot();
        break;
    }
    case 'schedule': {
        require_perm('loan.view');
        $l = Loans::get($id);
        must((bool)$l['disbursed_at'], 'The schedule is generated at disbursement.');
        $s = Loans::scheduleWithPaid($l);
        doc_head('Repayment schedule ' . $l['loan_no']);
        echo '<div class="doc">'; doc_biz('LOAN REPAYMENT SCHEDULE', 'Loan ' . $l['loan_no']);
        echo kv(['Client' => $l['client_name'] . ' (' . $l['client_no'] . ')', 'Principal' => ugx($l['approved_principal']), 'Interest rate' => bp_to_pct((int)$l['rate_bp']) . '% per month flat', 'Interest amount' => ugx($l['interest_amount']),
            'Total principal + interest' => ugx($l['approved_principal'] + $l['interest_amount']), 'Loan period' => $l['period_months'] . ' month(s)', 'Disbursement date' => fmt_date($l['disbursement_date']), 'Due date' => fmt_date($l['due_date'])]);
        echo '<table><tr><th>#</th><th>Due date</th><th class="r">Principal</th><th class="r">Interest</th><th class="r">Expected payment</th><th class="r">Amount paid</th><th class="r">Remaining</th></tr>';
        foreach ($s as $r) echo '<tr><td>' . (int)$r['installment_no'] . '</td><td>' . e(fmt_date($r['due_date'])) . '</td><td class="r">' . num($r['principal_due']) . '</td><td class="r">' . num($r['interest_due']) . '</td><td class="r">' . num($r['total_due']) . '</td><td class="r">' . num($r['paid']) . '</td><td class="r">' . num($r['remaining']) . '</td></tr>';
        echo '</table>';
        $pen = Penalty::termsFor($l);
        echo '<p>' . (!empty($pen['enabled']) ? 'Default penalty: ' . e(bp_to_pct((int)$pen['rate_bp'])) . '% per day on ' . e(str_replace('_', ' ', $pen['basis'])) . ' after the due date. Penalties are recorded separately from principal and interest.' : 'No default penalty applies to this loan.') . '</p>';
        $pays = rows("SELECT r.receipt_no, p.business_date, p.amount, u.full_name FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN users u ON u.id = p.cashier_id WHERE p.loan_id = ? AND p.status = 'Posted' ORDER BY p.id", [$id]);
        if ($pays) { echo '<h2>Payments received</h2><table><tr><th>Date</th><th>Receipt</th><th class="r">Amount</th><th>Cashier</th></tr>'; foreach ($pays as $p) echo '<tr><td>' . e(fmt_date($p['business_date'])) . '</td><td>' . e($p['receipt_no']) . '</td><td class="r">' . num($p['amount']) . '</td><td>' . e($p['full_name']) . '</td></tr>'; echo '</table>'; }
        echo sigs(['Borrower', 'Officer']) . '</div>';
        doc_foot();
        break;
    }
    case 'statement':
    case 'clearance': {
        require_perm('loan.view');
        $l = Loans::get($id);
        must((bool)$l['disbursed_at'], 'The loan has not been disbursed.');
        $b = Loans::balances($id);
        $entries = rows('SELECT * FROM loan_ledger WHERE loan_id = ? ORDER BY entry_date, id', [$id]);
        doc_head(($doc === 'clearance' ? 'Clearance statement ' : 'Statement ') . $l['loan_no']);
        echo '<div class="doc">'; doc_biz($doc === 'clearance' ? 'LOAN CLEARANCE STATEMENT' : 'CUSTOMER LOAN STATEMENT', 'Loan ' . $l['loan_no'] . ' · as at business date ' . fmt_date(reporting_date()));
        if ($doc === 'clearance') must($l['status'] === 'Closed', 'The loan is not closed.');
        echo kv(['Client' => $l['client_name'] . ' (' . $l['client_no'] . ')', 'Approved principal' => ugx($l['approved_principal']), 'Disbursement date' => fmt_date($l['disbursement_date']), 'Net disbursement' => ugx($l['net_disbursement']),
            'Contractual interest' => ugx($l['interest_amount']), 'Fees' => ugx($l['total_fees']) . ($l['fees_deducted'] ? ' (deducted)' : ' (payable)'), 'Due date' => fmt_date($l['due_date']), 'Loan status' => $l['status']]);
        echo '<table><tr><th>Date</th><th>Description</th><th>Component</th><th class="r">Charged</th><th class="r">Paid / waived</th><th class="r">Balance</th></tr>';
        $run = 0;
        foreach ($entries as $e) { $run += (int)$e['amount']; echo '<tr><td>' . e(fmt_date($e['entry_date'])) . '</td><td>' . e($e['description'] ?: $e['type']) . '</td><td>' . e($e['component']) . '</td><td class="r">' . ($e['amount'] > 0 ? num($e['amount']) : '') . '</td><td class="r">' . ($e['amount'] < 0 ? num(-$e['amount']) : '') . '</td><td class="r">' . num($run) . '</td></tr>'; }
        echo '</table><table style="width:60%;margin-left:auto"><tr><td>Outstanding principal</td><td class="r">' . num($b['principal']) . '</td></tr><tr><td>Outstanding interest</td><td class="r">' . num($b['interest']) . '</td></tr><tr><td>Outstanding penalty</td><td class="r">' . num($b['penalty']) . '</td></tr><tr><td>Outstanding fees</td><td class="r">' . num($b['fee']) . '</td></tr><tr><th>Total outstanding</th><th class="r">' . num($b['total']) . '</th></tr></table>';
        if ($doc === 'clearance') echo '<p style="margin-top:12px"><b>This is to certify that the above loan has been fully settled and closed on ' . e(fmt_date($l['closed_date'])) . '. The borrower owes no further amount under this loan.</b></p>' . sigs(['Authorized officer', 'Manager']);
        echo '</div>';
        doc_foot();
        break;
    }
    case 'voucher': {
        must(can('payment.view') || can('loan.disburse'), 'You do not have permission to perform this action.');
        $d = row('SELECT d.*, l.loan_no, c.full_name, c.client_no, u.full_name AS by_name, a.full_name AS auth_name FROM disbursements d JOIN loans l ON l.id = d.loan_id JOIN clients c ON c.id = d.client_id JOIN users u ON u.id = d.disbursed_by LEFT JOIN users a ON a.id = d.authorized_by WHERE d.id = ?', [$id]);
        must((bool)$d, 'Disbursement not found.');
        assert_branch((int)$d['branch_id']);
        doc_head('Disbursement voucher ' . $d['disb_no']);
        echo '<div class="doc">'; doc_biz('LOAN DISBURSEMENT VOUCHER', $d['disb_no']);
        echo kv(['Client' => $d['full_name'] . ' (' . $d['client_no'] . ')', 'Loan' => $d['loan_no'], 'Business date' => fmt_date($d['business_date']), 'Method' => $d['method'], 'Reference' => $d['reference'], 'Disbursed by' => $d['by_name'], 'Authorized by' => $d['auth_name']]);
        echo '<table><tr><th>Item</th><th class="r">UGX</th></tr><tr><td>Gross approved principal</td><td class="r">' . num($d['approved_principal']) . '</td></tr><tr><td>Less processing fee</td><td class="r">' . num($d['processing_fee']) . '</td></tr><tr><td>Less application fee</td><td class="r">' . num($d['application_fee']) . '</td></tr>'
            . '<tr><td>Less inspection fee</td><td class="r">' . num($d['inspection_fee']) . '</td></tr>' . ($d['other_deductions'] ? '<tr><td>Less other authorized deductions</td><td class="r">' . num($d['other_deductions']) . '</td></tr>' : '')
            . '<tr><th>NET AMOUNT RELEASED</th><th class="r">' . num($d['net_amount']) . '</th></tr></table><p><i>' . e(amount_in_words((int)$d['net_amount'])) . '</i></p>'
            . '<p>I acknowledge receipt of the net amount above and accept the loan terms.</p>' . sigs(['Client signature & date', 'Cashier / disbursing officer', 'Authorizing manager']) . '</div>';
        doc_foot();
        break;
    }
    case 'eod': {
        require_perm('eod.view');
        $date = get('date');
        must(is_date($date), 'Invalid date.');
        $rec = Eod::latestRecord($date);
        must((bool)$rec, 'There is no EOD record for that date.');
        $sum = json_decode($rec['summary_json'], true);
        $printMode = true;
        doc_head('EOD ' . $date);
        echo '<div class="doc">'; doc_biz($rec['status'] === 'Closed' ? 'BUSINESS DATE CLOSURE CERTIFICATE & END-OF-DAY SUMMARY' : 'END-OF-DAY SUMMARY (' . strtoupper($rec['status']) . ')', 'Business date ' . fmt_date($date) . ' · version ' . $rec['version']);
        echo kv(['Status' => $rec['status'], 'Submitted by' => val('SELECT full_name FROM users WHERE id = ?', [$rec['submitted_by']]) . ' ' . fmt_dt($rec['submitted_at']), 'Approved by' => $rec['reviewed_by'] ? val('SELECT full_name FROM users WHERE id = ?', [$rec['reviewed_by']]) . ' ' . fmt_dt($rec['reviewed_at']) : 'pending', 'Backup' => $rec['backup_status'], 'Review notes' => $rec['review_notes']]);
        require BASE_PATH . '/app/views/eod_summary.php';
        $ss = rows("SELECT s.*, u.full_name AS cashier FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.branch_id = ? AND s.business_date = ? ORDER BY s.id", [branch_id(), $date]);
        echo '<h2>Cashier reconciliation</h2><table><tr><th>Session</th><th>Cashier</th><th class="r">Opening</th><th class="r">Expected</th><th class="r">Counted</th><th class="r">Variance</th><th>Explanation</th><th>Status</th></tr>';
        foreach ($ss as $s) echo '<tr><td>' . e($s['session_no']) . '</td><td>' . e($s['cashier']) . '</td><td class="r">' . num($s['opening_float']) . '</td><td class="r">' . num($s['expected_cash']) . '</td><td class="r">' . num($s['counted_cash']) . '</td><td class="r">' . num($s['variance']) . '</td><td>' . e($s['variance_reason'] . ' ' . $s['reconcile_notes']) . '</td><td>' . e($s['status']) . '</td></tr>';
        echo '</table>';
        $exc = Eod::exceptions($date);
        if ($exc) { echo '<h2>Exceptions</h2><table><tr><th>Type</th><th>Reference</th><th>Detail</th></tr>'; foreach ($exc as $x) echo '<tr><td>' . e($x['type']) . '</td><td>' . e($x['ref']) . '</td><td>' . e($x['detail']) . '</td></tr>'; echo '</table>'; }
        echo sigs(['Prepared by (Cashier / Officer)', 'Approved by (Manager)', 'Date']) . '</div>';
        doc_foot();
        break;
    }
    case 'securities': {
        require_perm('loan.view');
        $ss = rows("SELECT s.*, l.loan_no, c.full_name FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id WHERE " . branch_scope('l.branch_id') . " ORDER BY s.security_no");
        doc_head('Security register');
        echo '<div class="doc" style="max-width:1000px">'; doc_biz('SECURITY & COLLATERAL REGISTER', 'As at ' . fmt_date(reporting_date()));
        echo '<table><tr><th>ID</th><th>Loan</th><th>Client</th><th>Type</th><th>Description</th><th>Serial</th><th>Owner</th><th class="r">Est. value</th><th>Stored at</th><th>Status</th></tr>';
        foreach ($ss as $s) echo '<tr><td>' . e($s['security_no']) . '</td><td>' . e($s['loan_no']) . '</td><td>' . e($s['full_name']) . '</td><td>' . e($s['type']) . '</td><td>' . e($s['description']) . '</td><td>' . e($s['serial_no']) . '</td><td>' . e($s['owner_type']) . '</td><td class="r">' . num($s['est_value']) . '</td><td>' . e($s['storage_location']) . '</td><td>' . e($s['status']) . '</td></tr>';
        echo '</table></div>';
        doc_foot();
        break;
    }
    default:
        throw new AppError('Unknown document.');
}
