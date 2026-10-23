<?php
$title = 'Loan approval';
$id = (int)get('id');

if ($id) {
    $l = Loans::get($id);
    $dec = post('decision');
    post_action(function () use ($id, $dec) {
        if ($dec === 'approve') { Loans::approve($id, $_POST); return ['Loan approved. Offer terms are now locked.', url('loan', ['id' => $id])]; }
        if ($dec === 'reject') { Loans::reject($id, clean(post('reason'))); return ['Application rejected.', url('approvals')]; }
        if ($dec === 'escalate') { Loans::escalate($id, clean(post('reason'))); return ['Escalated to the COO.', url('approvals')]; }
        if ($dec === 'return') { Loans::returnForCorrection($id, clean(post('reason')), post('stage')); return ['Returned to the loan officer for correction.', url('approvals')]; }
        throw new AppError('Choose an action.');
    });
    page_head('Loan approval — ' . $l['loan_no'], btn('Loan file', url('loan', ['id' => $id])), $l['client_name'] . ' · requested ' . ugx($l['amount_requested']));
    $assess = Loans::latestAssessment($id);
    $own = (int)$l['officer_id'] === (int)uid();
    $isAdjust = $l['status'] === 'Approved';
    if ($own) echo '<div class="notice bad">You originated this application and cannot approve it. Ask another authorized manager.</div>';
    if (!in_array($l['status'], ['Submitted', 'Under Review', 'Approved'], true)) throw new AppError('This loan is not awaiting approval (status: ' . $l['status'] . ').');
    if ($isAdjust && !can('loan.adjust')) throw new AppError('This loan is already approved. You do not have permission to adjust approved terms.');
    if (!$assess) echo '<div class="notice">No credit assessment has been recorded.' . (setting('assessment_required') === '1' ? ' Approval needs an authorized exception reason.' : '') . '</div>';
    else echo '<div class="notice info">Assessment by <b>' . e($assess['assessor']) . '</b>: <b>' . e($assess['recommendation']) . '</b>, recommended ' . ugx($assess['recommended_amount']) . ', disposable income ' . ugx($assess['disposable_income']) . ', security value ' . ugx($assess['security_value']) . '.</div>';
    $ex = Loans::clientExposure((int)$l['client_id'], $id);
    if ($ex['overdue_count']) echo '<div class="notice bad">This client has ' . $ex['overdue_count'] . ' overdue loan(s) and owes ' . ugx($ex['outstanding']) . '.</div>';
    elseif ($ex['outstanding']) echo '<div class="notice">Existing outstanding balance: ' . ugx($ex['outstanding']) . '.</div>';
    $needAuth = Approvals::required('loan', (int)$l['amount_requested'], (int)$l['branch_id']);
    $haveAuth = Approvals::authority();
    echo '<div class="notice ' . (Approvals::covers($haveAuth, $needAuth) ? 'info' : 'bad') . '">Required approval authority for ' . ugx($l['amount_requested']) . ': <b>' . e($needAuth) . '</b>'
        . (Approvals::limit('loan', (int)$l['branch_id']) === null ? ' (no loan limit configured — Branch Manager level applies)' : ' (Branch Manager limit ' . ugx((int)Approvals::limit('loan', (int)$l['branch_id'])) . ')')
        . '. Your authority: <b>' . e($haveAuth ?? 'none') . '</b>.' . ($l['escalated_at'] ? ' Escalated: ' . e($l['escalation_note']) : '') . '</div>';
    if ($isAdjust) echo '<div class="notice">Adjusting approved terms resets client acceptance and requires a reason. This is audited.</div>';

    if (!$own) {
        $def = Loans::defaultTerms($l);
        $cur = $isAdjust ? ['approved_principal' => $l['approved_principal'], 'rate_bp' => $l['rate_bp'], 'processing_bp' => $l['processing_fee_bp'], 'application_fee' => $l['application_fee'],
            'inspection_fee' => $l['inspection_fee'], 'other_fees' => $l['other_fees'], 'fees_deducted' => (bool)$l['fees_deducted'], 'months' => $l['period_months']] : $def;
        echo '<div class="grid g2"><div class="card"><h2>Approve</h2>';
        form_open(url('approvals', ['id' => $id]), ['attrs' => 'id="approve-form" style="grid-template-columns:1fr 1fr"']);
        field('decision', '', ['type' => 'hidden', 'value' => 'approve']);
        field('approved_principal', 'Approved amount (UGX)', ['type' => 'money', 'value' => $cur['approved_principal'], 'required' => true]);
        field('months', 'Period (months)', ['type' => 'number', 'value' => $cur['months'], 'attrs' => 'min="1" max="60"']);
        field('rate_pct', 'Interest rate (% per month, flat)', ['value' => bp_to_pct((int)$cur['rate_bp'])]);
        field('processing_pct', 'Processing fee (%)', ['value' => bp_to_pct((int)$cur['processing_bp'])]);
        field('application_fee', 'Application fee (UGX)', ['type' => 'money', 'value' => $cur['application_fee']]);
        field('inspection_fee', 'Inspection fee (UGX)', ['type' => 'money', 'value' => $cur['inspection_fee'], 'help' => 'Allowed: ' . num(setting_int('inspection_fee_min')) . ' – ' . num(setting_int('inspection_fee_max'))]);
        field('other_fees', 'Other authorized deductions (UGX)', ['type' => 'money', 'value' => $cur['other_fees']]);
        field('other_fees_note', 'Describe other deductions', []);
        field('fees_deducted', 'Fees are deducted from the principal at disbursement', ['type' => 'checkbox', 'value' => $cur['fees_deducted'] ? '1' : '0', 'span' => 2]);
        field('change_reason', $isAdjust ? 'Reason for adjusting the approved terms' : 'Reason (required if amount/terms differ from the recommendation)', ['span' => 2, 'required' => $isAdjust]);
        field('comments', 'Approval comments', ['type' => 'textarea', 'span' => 2]);
        if (!$assess && setting('assessment_required') === '1') {
            field('exception', 'Approve without assessment (authorized exception)', ['type' => 'checkbox', 'span' => 2]);
            field('exception_reason', 'Exception reason', ['span' => 2]);
        }
        echo '<div class="form-actions">'; submit_btn($isAdjust ? 'Save adjusted terms' : 'Approve loan', 'primary', 'data-confirm="Approve with these terms? They will be locked as the loan snapshot."'); echo '</div>';
        form_close();
        echo '</div><div class="card"><h2>Calculation <span class="muted" style="font-weight:400;font-size:.8rem">(live preview)</span></h2><div class="calc" id="live-calc"></div>';
        if ($assess) echo '<p class="muted" style="margin-top:.6rem">Recommended amount: ' . ugx($assess['recommended_amount']) . '</p>';
        echo '</div></div>';

        if (!$isAdjust && !$l['escalated_at'] && $haveAuth === 'Branch Manager') {
            echo '<div class="card"><h2>Escalate to the COO</h2><p class="muted">Use when the amount is above your limit or you want a second opinion. The loan leaves your queue.</p>';
            form_open(url('approvals', ['id' => $id]), ['attrs' => 'style="grid-template-columns:1fr auto;align-items:end"']);
            field('decision', '', ['type' => 'hidden', 'value' => 'escalate']);
            field('reason', 'Reason for escalation', ['required' => true]);
            echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Escalate', 'default'); echo '</div>'; form_close(); echo '</div>';
        }
        if (!$isAdjust) {
            echo '<div class="grid g2"><div class="card"><h2>Return for correction</h2>';
            form_open(url('approvals', ['id' => $id]), ['attrs' => 'style="grid-template-columns:1fr"']);
            field('decision', '', ['type' => 'hidden', 'value' => 'return']);
            field('stage', 'Return to stage (its records must then be verified again)', ['type' => 'select', 'options' => ['kyc' => '2 · KYC & documents', 'guarantors' => '3 · Guarantors', 'collateral' => '4 · Collateral / security', 'application' => '5 · Loan application', 'assessment' => '6 · Credit assessment'], 'value' => 'application']);
            field('reason', 'What must the officer correct?', ['type' => 'textarea', 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Return to officer', 'default'); echo '</div>'; form_close();
            echo '</div><div class="card"><h2>Reject</h2>';
            form_open(url('approvals', ['id' => $id]), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Reject this application?"']);
            field('decision', '', ['type' => 'hidden', 'value' => 'reject']);
            field('reason', 'Rejection reason (recorded)', ['type' => 'textarea', 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Reject application', 'danger'); echo '</div>'; form_close();
            echo '</div></div>';
        }
    }
    return;
}

page_head('Loan approval', '', 'Applications awaiting a decision, and approved offers awaiting client acceptance.');
echo '<div class="card"><h2>Awaiting decision</h2>';
$list = rows("SELECT l.id, l.loan_no, c.full_name, l.amount_requested, l.status, l.application_date, u.full_name AS officer,
              (SELECT recommendation || ' · ' || recommended_amount FROM credit_assessments a WHERE a.loan_id = l.id ORDER BY a.id DESC LIMIT 1) AS rec
              FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id WHERE l.status IN ('Submitted','Under Review') AND " . branch_scope('l.branch_id') . ' ORDER BY l.id');
if (Approvals::authority() !== 'COO') $list = array_values(array_filter($list, fn($r) => Approvals::required('loan', (int)$r['amount_requested'], (int)val('SELECT branch_id FROM loans WHERE id = ?', [$r['id']])) === 'Branch Manager' && !val('SELECT escalated_at FROM loans WHERE id = ?', [$r['id']])));
data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'application_date' => ['Applied', 'date'], 'amount_requested' => ['Requested', 'money'], 'officer' => 'Officer', 'rec' => 'Assessment', 'status' => ['Status', 'badge']],
    $list, ['link' => fn($r) => url('approvals', ['id' => $r['id']]), 'empty' => 'Nothing is waiting for approval.', 'class' => 'nofilter']);
echo '</div><div class="card"><h2>Approved — awaiting acceptance / disbursement</h2>';
$ap = rows("SELECT l.id, l.loan_no, c.full_name, l.approved_principal, l.net_disbursement, l.acceptance_status, l.offer_expiry, l.approval_date FROM loans l JOIN clients c ON c.id = l.client_id WHERE l.status = 'Approved' AND " . branch_scope('l.branch_id') . " ORDER BY l.id");
data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'approval_date' => ['Approved', 'date'], 'approved_principal' => ['Principal', 'money'], 'net_disbursement' => ['Net', 'money'],
    'acceptance_status' => ['Acceptance', 'badge'], 'offer_expiry' => ['Offer expires', fn($r) => $r['offer_expiry'] < reporting_date() ? '<span class="ico-bad">' . e(fmt_date($r['offer_expiry'])) . ' (expired)</span>' : e(fmt_date($r['offer_expiry']))]],
    $ap, ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'No approved offers pending.', 'class' => 'nofilter']);
echo '</div>';
