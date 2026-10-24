<?php
$title = 'Loan approval';
$id = (int)get('id');

if ($id) {
    $l = Loans::get($id);
    $dec = post('decision');
    $wf = get('wf');
    if ($wf !== '') post_action(function () use ($id, $wf, $l) {   // verify / reject a document, guarantor or the client KYC while reviewing
        $ok = post('decision') === 'verify'; $note = clean(post('note'));
        if ($wf === 'doc') Workflow::verifyDocument((int)post('att'), $ok, $note);
        elseif ($wf === 'guarantor') Workflow::verifyGuarantor((int)post('lg_id'), $ok, $note);
        elseif ($wf === 'client') Workflow::verifyClient((int)$l['client_id'], $ok, $note);
        else throw new AppError('Choose an action.');
        return [$ok ? 'Verified.' : 'Rejected.', url('approvals', ['id' => $id, 'rtab' => get('rtab', 'documents')])];
    });
    post_action(function () use ($id, $dec) {
        if ($dec === 'approve') { Loans::approve($id, $_POST); return ['Loan approved. Offer terms are now locked and the repayment schedule has been created (download it from the loan page).', url('loan', ['id' => $id])]; }
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

    // ---- review tabs: check what is attached before deciding
    $docs = rows("SELECT a.*, u.full_name AS up_by, v.full_name AS ver_by,
                  CASE a.entity_type WHEN 'client' THEN 'Client (KYC)' WHEN 'loan' THEN 'Application' WHEN 'security' THEN 'Security' ELSE a.entity_type END AS source
                  FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by LEFT JOIN users v ON v.id = a.verified_by
                  WHERE (a.entity_type = 'client' AND a.entity_id = ?) OR (a.entity_type = 'loan' AND a.entity_id = ?) OR (a.entity_type = 'security' AND a.entity_id IN (SELECT id FROM securities WHERE loan_id = ?))
                  ORDER BY a.id DESC", [$l['client_id'], $id, $id]);
    $gs = rows("SELECT lg.*, g.guarantor_no, g.surname || ' ' || g.first_name AS name, g.phone, g.nin, v.full_name AS ver_by FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id LEFT JOIN users v ON v.id = lg.verified_by WHERE lg.loan_id = ? AND lg.status = 'Active' ORDER BY lg.id", [$id]);
    $secs = rows("SELECT s.*, g.surname || ' ' || g.first_name AS guarantor_name FROM securities s LEFT JOIN guarantors g ON g.id = s.guarantor_id WHERE s.loan_id = ? ORDER BY s.id", [$id]);
    $rtab = in_array(get('rtab'), ['documents', 'guarantors', 'security'], true) ? get('rtab') : 'review';
    tabs(['review' => 'Decision', 'documents' => 'Documents attached (' . count($docs) . ')', 'guarantors' => 'Guarantors (' . count($gs) . ')', 'security' => 'Security (' . count($secs) . ')'], $rtab, 'rtab');
    if ($rtab !== 'review') {
        $stg = Workflow::prerequisites($l);
        $issues = []; foreach ([2, 3, 4] as $n) foreach ($stg[$n]['issues'] as $m) $issues[$n][] = $m;
        $stageIssues = ['documents' => $issues[2] ?? [], 'guarantors' => $issues[3] ?? [], 'security' => $issues[4] ?? []][$rtab];
        $note = ['documents' => $stg[2]['note'], 'guarantors' => $stg[3]['note'], 'security' => $stg[4]['note']][$rtab];
        if ($stageIssues) echo '<div class="notice bad"><b>Still to be resolved before approval:</b><ul style="margin:.3rem 0 0 1.1rem">' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $stageIssues)) . '</ul></div>';
        else echo '<div class="notice ok">This part of the file is complete' . ($note ? ' (' . e($note) . ')' : '') . '.</div>';
        $back = fn(string $wfk, array $extra = []) => url('approvals', ['id' => $id, 'rtab' => $rtab, 'wf' => $wfk]);
        if ($rtab === 'documents') {
            echo '<div class="card"><div class="card-h"><h2>Documents attached to this application</h2><span>' . badge($l['client_id'] ? (string)val('SELECT kyc_status FROM clients WHERE id = ?', [$l['client_id']]) : 'Pending') . ' client KYC</span></div>';
            data_table(['original_name' => ['File', fn($r) => '<a target="_blank" href="' . e(attachment_url((int)$r['id'])) . '">' . e($r['original_name']) . '</a>'], 'source' => 'From', 'kind' => ['Kind', fn($r) => e(Workflow::KYC_KINDS[$r['kind']] ?? str_replace('_', ' ', $r['kind']))],
                'uploaded_at' => ['Uploaded', 'dt'], 'up_by' => 'By', 'verify_status' => ['Verification', fn($r) => badge($r['verify_status']) . (can('kyc.verify') && $r['verify_status'] === 'Pending' ? decision_form($back('doc'), ['att' => $r['id']]) : '')]],
                $docs, ['empty' => 'No documents have been attached.', 'class' => 'nofilter']);
            echo '</div>';
        }
        if ($rtab === 'guarantors') {
            echo '<div class="card"><h2>Guarantors</h2>';
            data_table(['guarantor_no' => 'ID', 'name' => 'Name', 'phone' => 'Phone', 'nin' => 'NIN', 'guarantee_amount' => ['Guarantee limit', 'money'], 'declaration_accepted' => ['Consent', fn($r) => $r['declaration_accepted'] ? badge('Accepted') : badge('Pending')],
                'verify_status' => ['Verification', fn($r) => badge($r['verify_status']) . (can('kyc.verify') && $r['verify_status'] !== 'Verified' ? decision_form($back('guarantor'), ['lg_id' => $r['id']]) : '')]],
                $gs, ['empty' => 'No guarantors are linked to this application.', 'class' => 'nofilter', 'link' => fn($r) => url('guarantors', ['a' => 'view', 'id' => $r['guarantor_id']])]);
            echo '</div>';
        }
        if ($rtab === 'security') {
            echo '<div class="card"><h2>Security / collateral</h2>';
            data_table(['security_no' => 'ID', 'type' => 'Type', 'description' => 'Description', 'owner_type' => 'Owner', 'est_value' => ['Estimated value', 'money'], 'valuation_date' => ['Valued', 'date'], 'status' => ['Status', 'badge']],
                $secs, ['empty' => 'No security has been registered.', 'class' => 'nofilter', 'totals' => ['est_value']]);
            echo '<p class="muted" style="margin-top:.6rem">' . btn('Open the security register', url('loan', ['id' => $id, 'tab' => 'securities']), 'sm') . '</p></div>';
        }
        return;
    }

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
