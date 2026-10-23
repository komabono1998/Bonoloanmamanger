<?php
$title = 'Loan disbursement';
$id = (int)get('id');

if ($id) {
    post_action(function () use ($id) {
        $did = Loans::disburse($id, $_POST);
        return ['Loan disbursed. The repayment schedule has been generated.', url('print', ['doc' => 'voucher', 'id' => $did])];
    });
    $l = Loans::get($id);
    page_head('Disburse ' . $l['loan_no'], btn('Loan file', url('loan', ['id' => $id])), $l['client_name'] . ' · ' . $l['client_no']);
    if ($l['status'] !== 'Approved') {
        echo '<div class="notice bad">This loan cannot be disbursed: ' . ($l['disbursed_at'] ? 'it has already been disbursed.' : 'it has not been approved (status: ' . e($l['status']) . ').') . '</div>';
        return;
    }
    if ($l['acceptance_status'] !== 'Accepted') echo '<div class="notice bad">Loan offer acceptance is required before disbursement. <a href="' . e(url('loan', ['id' => $id])) . '">Record acceptance →</a></div>';
    if (Loans::offerExpired($l)) echo '<div class="notice bad">The offer expired on ' . e(fmt_date($l['offer_expiry'])) . '. A manager must extend it.</div>';
    if (!current_bd()) echo '<div class="notice bad">No business day is open.</div>';
    $mine = Cash::mySession();
    echo '<div class="grid g2"><div class="card"><h2>Amounts</h2><div class="calc"><table>'
        . '<tr><td>Gross approved principal</td><td>' . num($l['approved_principal']) . '</td></tr>'
        . '<tr><td>Processing fee</td><td>' . ($l['fees_deducted'] ? '−' : '') . num($l['processing_fee']) . '</td></tr>'
        . '<tr><td>Application fee</td><td>' . ($l['fees_deducted'] ? '−' : '') . num($l['application_fee']) . '</td></tr>'
        . '<tr><td>Inspection fee</td><td>' . ($l['fees_deducted'] ? '−' : '') . num($l['inspection_fee']) . '</td></tr>'
        . ($l['other_fees'] ? '<tr><td>Other deductions</td><td>' . ($l['fees_deducted'] ? '−' : '') . num($l['other_fees']) . '</td></tr>' : '')
        . '<tr class="tot"><td>Net amount to release</td><td>' . num($l['net_disbursement']) . '</td></tr></table></div>'
        . ($l['fees_deducted'] ? '' : '<p class="muted">Fees are payable separately and appear as a fee balance on the loan.</p>')
        . '<p class="muted" style="margin-top:.6rem">Matures ' . e(fmt_date(Loans::maturityDate($l, reporting_date()))) . ' if disbursed on business date ' . e(fmt_date(reporting_date())) . '.</p>'
        . ($mine ? '<p>Your till (' . e($mine['session_no']) . ') currently holds <b>' . ugx(Cash::expected((int)$mine['id'])) . '</b>.</p>' : '<p class="ico-warn">You have no open till — required for cash disbursements.</p>')
        . '</div><div class="card"><h2>Release funds</h2>';
    form_open(null, ['attrs' => 'style="grid-template-columns:1fr 1fr"']);
    field('method', 'Payment method', ['type' => 'select', 'options' => ['Cash' => 'Cash (from my till)', 'Bank Transfer' => 'Bank transfer', 'Mobile Money' => 'Mobile money', 'Other' => 'Other']]);
    field('reference', 'Transaction reference', ['help' => 'Required for non-cash methods.']);
    field('authorized_by', 'Authorizing manager', ['type' => 'select', 'options' => user_options('loan.approve'), 'value' => can('loan.approve') ? uid() : '', 'blank' => can('loan.approve') ? false : 'Select…', 'span' => 2]);
    field('doc_verified', 'Required documents verified (signed offer, ID, guarantor forms, security)', ['type' => 'checkbox', 'span' => 2]);
    field('client_ack', 'Client acknowledges receipt of the net amount', ['type' => 'checkbox', 'span' => 2]);
    field('notes', 'Notes', ['type' => 'textarea', 'span' => 2]);
    echo '<div class="form-actions">'; submit_btn('Disburse ' . ugx($l['net_disbursement']), 'primary', 'data-confirm="Release ' . e(ugx($l['net_disbursement'])) . ' to the client? This posts the cash movement and cannot be deleted."'); echo '</div>';
    form_close();
    echo '</div></div>';
    return;
}

page_head('Loan disbursement', '', 'Approved loans with a signed acceptance are ready to be disbursed.');
$ready = rows("SELECT l.id, l.loan_no, c.full_name, l.approved_principal, l.net_disbursement, l.acceptance_status, l.offer_expiry FROM loans l JOIN clients c ON c.id = l.client_id WHERE l.status = 'Approved' AND " . branch_scope('l.branch_id') . " ORDER BY l.id");
echo '<div class="card"><h2>Ready / pending</h2>';
data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'approved_principal' => ['Gross principal', 'money'], 'net_disbursement' => ['Net to release', 'money'], 'acceptance_status' => ['Acceptance', 'badge'],
    'offer_expiry' => ['Offer expires', 'date']], $ready, ['link' => fn($r) => url('disbursements', ['id' => $r['id']]), 'empty' => 'No approved loans are waiting.', 'class' => 'nofilter']);
echo '</div><div class="card"><h2>Recent disbursements</h2>';
$done = rows("SELECT d.id, d.disb_no, d.business_date, l.loan_no, c.full_name, d.approved_principal, (d.processing_fee+d.application_fee+d.inspection_fee+d.other_deductions) AS fees, d.net_amount, d.method, u.full_name AS by_name
              FROM disbursements d JOIN loans l ON l.id = d.loan_id JOIN clients c ON c.id = d.client_id JOIN users u ON u.id = d.disbursed_by WHERE " . branch_scope('d.branch_id') . " ORDER BY d.id DESC LIMIT 200");
data_table(['disb_no' => 'Voucher', 'business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'approved_principal' => ['Gross', 'money'], 'fees' => ['Fees deducted', 'money'],
    'net_amount' => ['Net released', 'money'], 'method' => 'Method', 'by_name' => 'By'], $done, ['link' => fn($r) => url('print', ['doc' => 'voucher', 'id' => $r['id']]), 'empty' => 'Nothing disbursed yet.']);
echo '</div>';
