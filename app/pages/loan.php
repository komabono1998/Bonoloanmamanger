<?php
/** Central loan file: terms, schedule, statement, payments, guarantors, securities, documents and history. */
$id = (int)get('id');
$a = get('a');
$loan = Loans::get($id);
$title = $loan['loan_no'];
$back = fn(string $tab = 'overview') => url('loan', ['id' => $id, 'tab' => $tab]);

if ($a === 'submit') post_action(function () use ($id) { Loans::submit($id); return 'Application submitted for review.'; });
if ($a === 'cancel') post_action(function () use ($id) { Loans::cancel($id, clean(post('reason'))); return 'Application cancelled.'; });
if ($a === 'accept') post_action(function () use ($id) {
    Loans::recordAcceptance($id, post('decision') === 'accept', post('date') ?: reporting_date(), clean(post('note')));
    return post('decision') === 'accept' ? 'Client acceptance recorded. The loan can now be disbursed.' : 'Client declined the offer.';
});
if ($a === 'extend') post_action(function () use ($id) { Loans::extendOffer($id, clean(post('reason'))); return 'Offer validity extended.'; });
if ($a === 'escalate') post_action(function () use ($id) { Loans::escalate($id, clean(post('reason'))); return 'Loan escalated to the COO.'; });
if ($a === 'close') post_action(function () use ($id) { Loans::manualClose($id, clean(post('note'))); return 'Loan closed.'; });
if ($a === 'waive') post_action(function () use ($id) {
    Loans::waive($id, post('component'), parse_money(post('amount'), 'Waiver amount'), clean(post('reason')));
    return 'Waiver recorded.';
});
if ($a === 'link_guarantor') post_action(function () use ($id, $back) {
    require_perm('guarantor.manage');
    $l = Loans::get($id);
    must(in_array($l['status'], ['Draft', 'Submitted', 'Under Review', 'Returned for Correction', 'Approved'], true), 'Guarantors can no longer be added to this loan.');
    $gid = (int)post('guarantor_id');
    must((bool)val('SELECT COUNT(*) FROM guarantors WHERE id = ? AND branch_id = ?', [$gid, $l['branch_id']]), 'Select a guarantor registered in this loan branch.');
    must(!val('SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = ? AND guarantor_id = ?', [$id, $gid]), 'This guarantor is already linked to the loan.');
    $amt = post('guarantee_amount') !== '' ? parse_money(post('guarantee_amount'), 'Guarantee amount') : null;
    tx(function () use ($id, $gid, $l, $amt) {
        $lgid = insert('loan_guarantors', ['loan_id' => $id, 'guarantor_id' => $gid, 'client_id' => $l['client_id'], 'guarantee_date' => must_date(post('guarantee_date'), 'Guarantee date', false),
            'guarantee_amount' => $amt, 'liability_terms' => clean(post('liability_terms')) ?: null, 'declaration_accepted' => post('declaration_accepted') ? 1 : 0,
            'pledge_status' => clean(post('pledge_status')) ?: null, 'witness_name' => clean(post('witness_name')) ?: null, 'witness_phone' => clean(post('witness_phone')) ?: null,
            'notes' => trim(post('notes')) ?: null, 'created_by' => uid(), 'created_at' => now()]);
        save_upload('signed_doc', 'loan', $id, 'guarantor_form', 'Signed guarantor form');
        audit('Guarantor linked to loan', 'Guarantors', 'loan', $id, null, 'guarantor #' . $gid);
    });
    return ['Guarantor linked.', $back('guarantors')];
});
if ($a === 'release_guarantor') post_action(function () use ($id, $back) {
    require_perm('guarantor.manage');
    $lg = (int)post('lg_id');
    $row = row('SELECT * FROM loan_guarantors WHERE id = ? AND loan_id = ?', [$lg, $id]);
    must((bool)$row, 'Guarantor link not found.');
    must(clean(post('reason')) !== '', 'A reason is required.');
    update('loan_guarantors', ['status' => 'Released'], 'id = :i', ['i' => $lg]);
    audit('Guarantor released from loan', 'Guarantors', 'loan', $id, 'Active', 'Released', clean(post('reason')));
    return ['Guarantor released.', $back('guarantors')];
});
if ($a === 'add_security') post_action(function () use ($id, $back) {
    require_perm('security.manage');
    $l = Loans::get($id);
    $owner = post('owner_type') === 'Guarantor' ? 'Guarantor' : 'Borrower';
    $gid = $owner === 'Guarantor' ? (int)post('guarantor_id') : null;
    must($owner === 'Borrower' || val('SELECT COUNT(*) FROM loan_guarantors WHERE loan_id = ? AND guarantor_id = ?', [$id, $gid]), 'Guarantor-owned security requires a guarantor linked to this loan.');
    $desc = clean(post('description'));
    must($desc !== '' && post('type') !== '', 'Security type and description are required.');
    $sid = tx(function () use ($id, $l, $owner, $gid, $desc) {
        $sid = insert('securities', ['security_no' => make_number('security_prefix', 'security', false), 'loan_id' => $id, 'client_id' => $l['client_id'], 'guarantor_id' => $gid,
            'owner_type' => $owner, 'type' => clean(post('type')), 'description' => $desc, 'serial_no' => clean(post('serial_no')) ?: null,
            'ownership_details' => clean(post('ownership_details')) ?: null, 'est_value' => parse_money(post('est_value') ?: '0', 'Estimated value', true),
            'valuation_date' => must_date(post('valuation_date'), 'Valuation date', false), 'storage_location' => clean(post('storage_location')) ?: null,
            'date_pledged' => must_date(post('date_pledged'), 'Date pledged', false), 'status' => 'Proposed', 'created_by' => uid(), 'created_at' => now()]);
        insert('security_history', ['security_id' => $sid, 'old_status' => null, 'new_status' => 'Proposed', 'changed_by' => uid(), 'changed_at' => now(), 'note' => 'Registered']);
        save_upload('doc', 'security', $sid, 'security_doc', 'Supporting document');
        audit('Security registered', 'Securities', 'security', $sid, null, $desc);
        return $sid;
    });
    return ['Security registered.', $back('securities')];
});
if ($a === 'upload') post_action(function () use ($id, $back) {
    must(can('loan.create') || can('loan.approve') || can('guarantor.manage'), 'You do not have permission to perform this action.');
    $att = save_upload('doc', 'loan', $id, post('kind') ?: 'document', clean(post('note')));
    must((bool)$att, 'Choose a file to upload.');
    return ['Document uploaded.', $back('documents')];
});

$tab = get('tab', 'overview');
$bal = Loans::balances($id);
$st = $loan['status'];
$act = '';
if (in_array($st, ['Draft', 'Returned for Correction'], true) && can('loan.create')) $act .= btn('Edit', url('loans', ['a' => 'edit', 'id' => $id]));
if (in_array($st, ['Draft', 'Returned for Correction'], true) && can('loan.create')) $act .= '<form method="post" action="' . e(url('loan', ['id' => $id, 'a' => 'submit'])) . '" style="display:inline">' . csrf_field() . '<button class="btn primary">Submit for review</button></form>';
if ($st === 'Submitted' && can('loan.assess')) $act .= btn('Credit assessment', url('assessment', ['id' => $id]), 'primary');
if (in_array($st, ['Submitted', 'Under Review'], true) && can('loan.approve')) $act .= btn('Review & approve', url('approvals', ['id' => $id]), 'primary');
if ($st === 'Approved' && $loan['acceptance_status'] === 'Accepted' && can('loan.disburse')) $act .= btn('Disburse', url('disbursements', ['id' => $id]), 'primary');
if ($loan['approved_principal']) $act .= btn('Offer letter', url('print', ['doc' => 'offer', 'id' => $id]));
if ($loan['disbursed_at']) $act .= btn('Statement', url('print', ['doc' => 'statement', 'id' => $id]));
if (in_array($st, Loans::OPEN_STATUSES, true) && can('payment.record')) $act .= btn('Receive payment', url('payments', ['loan_id' => $id]), 'primary');
page_head($loan['loan_no'], $act, $loan['client_name'] . ' · ' . $loan['client_no'] . ' · ' . branch_name((int)$loan['branch_id']));
if ($loan['escalated_at'] && in_array($st, ['Submitted', 'Under Review'], true)) echo '<div class="notice info"><b>Escalated to the COO</b> on ' . e(fmt_dt($loan['escalated_at'])) . ': ' . e($loan['escalation_note']) . '</div>';

echo '<div class="kpis">'
    . kpi('Status', badge($st))
    . kpi('Requested', ugx($loan['amount_requested']))
    . kpi('Approved principal', $loan['approved_principal'] ? ugx($loan['approved_principal']) : '—')
    . ($loan['disbursed_at'] ? kpi('Total outstanding', ugx($bal['total']), 'P ' . num($bal['principal']) . ' · I ' . num($bal['interest']) . ' · Pen ' . num($bal['penalty']), $bal['total'] > 0 ? ($st === 'Overdue' ? 'bad' : 'warn') : '')
        . kpi('Due date', e(fmt_date($loan['due_date'])), $st === 'Overdue' ? Loans::overdueInfo($loan)['days'] . ' days overdue' : '', $st === 'Overdue' ? 'bad' : '') : '')
    . '</div>';
$tabs = ['overview' => 'Overview', 'schedule' => 'Repayment schedule', 'statement' => 'Statement', 'payments' => 'Payments', 'guarantors' => 'Guarantors', 'securities' => 'Securities', 'documents' => 'Documents', 'history' => 'History'];
tabs($tabs, $tab);

// ------------------------------------------------------------------ overview
if ($tab === 'overview') {
    $assess = Loans::latestAssessment($id);
    echo '<div class="grid g2"><div class="card"><h2>Application</h2>';
    desc_list(['Loan number' => e($loan['loan_no']), 'Application date' => e(fmt_date($loan['application_date'])), 'Officer' => e($loan['officer_name']), 'Purpose' => e($loan['purpose']),
        'Period' => e($loan['period_months'] . ' month(s), ' . $loan['frequency']), 'Requested disbursement' => e(fmt_date($loan['requested_disb_date'])),
        'Monthly income' => ugx($loan['monthly_income']), 'Monthly expenses' => ugx($loan['monthly_expenses']), 'Existing debt' => ugx($loan['existing_debt']),
        'Security offered' => e($loan['security_offered']), 'Existing loans' => e($loan['existing_loans_info']), 'Officer comments' => e($loan['officer_comments'])], 2);
    echo '</div><div class="card"><h2>Approved terms <span class="muted" style="font-weight:400;font-size:.8rem">(locked snapshot)</span></h2>';
    if ($loan['approved_principal']) {
        echo '<div class="calc"><table>'
            . '<tr><td>Approved principal</td><td>' . num($loan['approved_principal']) . '</td></tr>'
            . '<tr><td>Interest (' . bp_to_pct((int)$loan['rate_bp']) . '% ' . e($loan['interest_method']) . ' × ' . (int)$loan['period_months'] . ' mo)</td><td>' . num($loan['interest_amount']) . '</td></tr>'
            . '<tr class="tot"><td>Principal + interest</td><td>' . num($loan['approved_principal'] + $loan['interest_amount']) . '</td></tr>'
            . '<tr><td>Processing fee (' . bp_to_pct((int)$loan['processing_fee_bp']) . '%)</td><td>' . num($loan['processing_fee']) . '</td></tr>'
            . '<tr><td>Application fee</td><td>' . num($loan['application_fee']) . '</td></tr>'
            . '<tr><td>Inspection fee</td><td>' . num($loan['inspection_fee']) . '</td></tr>'
            . ($loan['other_fees'] ? '<tr><td>Other authorized deductions' . ($loan['other_fees_note'] ? ' (' . e($loan['other_fees_note']) . ')' : '') . '</td><td>' . num($loan['other_fees']) . '</td></tr>' : '')
            . '<tr class="tot"><td>Total fees (' . ($loan['fees_deducted'] ? 'deducted from principal' : 'payable separately') . ')</td><td>' . num($loan['total_fees']) . '</td></tr>'
            . '<tr class="tot"><td>Net disbursement</td><td>' . num($loan['net_disbursement']) . '</td></tr>'
            . '<tr><td>Total contractual amount due</td><td>' . num($loan['total_contractual']) . '</td></tr></table></div>';
        desc_list(['Approved by' => e(val('SELECT full_name FROM users WHERE id = ?', [$loan['approved_by']])), 'Approval date' => e(fmt_date($loan['approval_date'])), 'Offer expires' => e(fmt_date($loan['offer_expiry'])),
            'Client acceptance' => badge($loan['acceptance_status']) . ' ' . e(fmt_date($loan['acceptance_date']))], 2);
    } else echo '<p class="muted">Terms are fixed when a manager approves the loan.</p>';
    if ($loan['rejection_reason']) echo '<div class="notice bad"><b>Rejected:</b> ' . e($loan['rejection_reason']) . '</div>';
    echo '</div></div>';

    if ($st === 'Approved') {
        $exp = Loans::offerExpired($loan);
        echo '<div class="card"><h2>Offer & client acceptance</h2>';
        if ($exp) echo '<div class="notice bad">This offer expired on ' . e(fmt_date($loan['offer_expiry'])) . '. It cannot be accepted or disbursed until a manager extends it.</div>';
        if ($loan['acceptance_status'] === 'Pending' && !$exp && (can('loan.create') || can('loan.approve') || can('loan.disburse'))) {
            form_open(url('loan', ['id' => $id, 'a' => 'accept']));
            field('decision', 'Client decision', ['type' => 'select', 'options' => ['accept' => 'Client ACCEPTS the offer', 'decline' => 'Client DECLINES the offer']]);
            field('date', 'Date signed', ['type' => 'date', 'value' => reporting_date()]);
            field('note', 'Note (e.g. signed form reference)', ['span' => 2]);
            echo '<div class="form-actions">'; submit_btn('Record decision'); echo btn('Print offer & acceptance form', url('print', ['doc' => 'offer', 'id' => $id])); echo '</div>'; form_close();
        } else {
            echo '<p>Acceptance status: ' . badge($loan['acceptance_status']) . ' ' . e(fmt_date($loan['acceptance_date'])) . ' ' . e($loan['acceptance_note']) . '</p>';
        }
        if ($exp && can('loan.approve')) {
            form_open(url('loan', ['id' => $id, 'a' => 'extend']));
            field('reason', 'Reason for extending the offer', ['span' => 3, 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Extend offer', 'primary'); echo '</div>'; form_close();
        }
        if (can('loan.create') || can('loan.approve')) {
            echo '<details class="card" style="margin-top:1rem"><summary>Cancel this approved loan</summary>';
            form_open(url('loan', ['id' => $id, 'a' => 'cancel']), ['attrs' => 'data-confirm="Cancel this loan? This cannot be undone."']);
            field('reason', 'Reason', ['span' => 3, 'required' => true]); echo '<div class="form-actions">'; submit_btn('Cancel loan', 'danger'); echo '</div>'; form_close();
            echo '</details>';
        }
        echo '</div>';
    } elseif (in_array($st, ['Draft', 'Submitted', 'Under Review', 'Returned for Correction'], true) && (can('loan.create') || can('loan.approve'))) {
        echo '<details class="card"><summary>Cancel application</summary>';
        form_open(url('loan', ['id' => $id, 'a' => 'cancel']), ['attrs' => 'data-confirm="Cancel this application?"']);
        field('reason', 'Reason', ['span' => 3, 'required' => true]); echo '<div class="form-actions">'; submit_btn('Cancel application', 'danger'); echo '</div>'; form_close();
        echo '</details>';
    }

    if ($assess) {
        echo '<div class="card"><h2>Credit assessment</h2>';
        desc_list(['Assessed by' => e($assess['assessor']), 'Date' => e(fmt_dt($assess['assessed_at'])), 'Recommendation' => '<b>' . e($assess['recommendation']) . '</b>', 'Recommended amount' => ugx($assess['recommended_amount']),
            'Income' => ugx($assess['income']), 'Expenses' => ugx($assess['expenses']), 'Obligations' => ugx($assess['existing_obligations']), 'Disposable income' => '<b>' . ugx($assess['disposable_income']) . '</b>',
            'Security value' => ugx($assess['security_value']), 'Repayment history' => e($assess['repayment_history']), 'Previous defaults' => e($assess['previous_defaults']), 'Notes' => e($assess['notes'])], 4);
        echo '</div>';
    }

    if ($loan['disbursed_at']) {
        $pen = Penalty::explain($loan);
        $info = $pen['overdue'];
        echo '<div class="grid g2"><div class="card"><h2>Balances (separate, never combined)</h2><div class="calc"><table>'
            . '<tr><td>Outstanding principal</td><td>' . num($bal['principal']) . '</td></tr><tr><td>Outstanding contractual interest</td><td>' . num($bal['interest']) . '</td></tr>'
            . '<tr><td>Accrued penalties outstanding</td><td>' . num($bal['penalty']) . '</td></tr><tr><td>Fees outstanding</td><td>' . num($bal['fee']) . '</td></tr>'
            . '<tr class="tot"><td>Total outstanding</td><td>' . num($bal['total']) . '</td></tr></table></div>'
            . '<p class="muted" style="margin-top:.6rem">Paid to date: ' . ugx(val('SELECT paid_total FROM v_loan_balances WHERE loan_id = ?', [$id])) . '</p></div>';
        echo '<div class="card"><h2>Arrears & penalty</h2>';
        if ($info['since']) echo '<div class="notice bad">Overdue since ' . e(fmt_date($info['since'])) . ' · <b>' . $info['days'] . ' day(s)</b> · overdue amount ' . ugx($info['amount']) . '</div>';
        else echo '<p>' . badge($st === 'Closed' ? 'Closed' : 'Active') . ' Not overdue.</p>';
        desc_list(['Penalty rule' => $pen['enabled'] ? e(bp_to_pct((int)$pen['terms']['rate_bp']) . '% per day on ' . str_replace('_', ' ', $pen['terms']['basis'])) : '<span class="muted">not enabled at approval</span>',
            'Penalty accrued' => ugx($pen['accrued']), 'Penalty collected' => ugx($pen['collected']), 'Penalty outstanding' => ugx($pen['outstanding']), 'Collection status' => badge($loan['collection_status'])], 2);
        echo '</div></div>';

        if (in_array($st, Loans::OPEN_STATUSES, true) && (can('loan.close') || can('penalty.adjust'))) {
            echo '<details class="card"><summary>Waive an amount / close loan (authorized users)</summary>';
            form_open(url('loan', ['id' => $id, 'a' => 'waive']), ['attrs' => 'data-confirm="Post this waiver? It is permanent and audited."']);
            field('component', 'Component', ['type' => 'select', 'options' => ['penalty' => 'Penalty (' . num($bal['penalty']) . ')', 'fee' => 'Fee (' . num($bal['fee']) . ')', 'interest' => 'Interest (' . num($bal['interest']) . ')', 'principal' => 'Principal (' . num($bal['principal']) . ')']]);
            field('amount', 'Amount to waive (UGX)', ['type' => 'money']);
            field('reason', 'Reason (mandatory)', ['span' => 2, 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Post waiver', 'danger'); echo '</div>'; form_close();
            if (Loans::isSettled($id) && can('loan.close')) {
                form_open(url('loan', ['id' => $id, 'a' => 'close']), ['attrs' => 'style="margin-top:.8rem"']);
                field('note', 'Closure note', ['span' => 3]); echo '<div class="form-actions">'; submit_btn('Close loan', 'primary'); echo '</div>'; form_close();
            }
            echo '</details>';
        }
        if ($st === 'Closed') {
            $cl = row('SELECT c.*, u.full_name FROM loan_closures c JOIN users u ON u.id = c.closed_by WHERE c.loan_id = ? ORDER BY c.id DESC LIMIT 1', [$id]);
            if ($cl) { echo '<div class="card"><h2>Loan closure</h2>'; desc_list(['Closed on' => e(fmt_date($cl['closure_date'])), 'Closed by' => e($cl['full_name']), 'Final payment ref' => e($cl['final_payment_ref']), 'Waivers' => ugx($cl['waivers']), 'Statement' => e($cl['statement'])], 3);
                echo '<p>' . btn('Loan clearance statement', url('print', ['doc' => 'clearance', 'id' => $id])) . '</p></div>'; }
        }
    }
}

// ------------------------------------------------------------------ schedule
if ($tab === 'schedule') {
    if (!$loan['disbursed_at']) echo '<div class="notice info">The repayment schedule is generated at disbursement using the approved terms.</div>';
    else {
        $s = Loans::scheduleWithPaid($loan);
        echo '<div class="card"><div class="card-h"><h2>Repayment schedule</h2>' . btn('Print / PDF', url('print', ['doc' => 'schedule', 'id' => $id]), 'sm') . '</div>';
        data_table(['installment_no' => ['#', 'num'], 'due_date' => ['Due date', 'date'], 'principal_due' => ['Principal', 'money'], 'interest_due' => ['Interest', 'money'], 'total_due' => ['Expected', 'money'],
            'paid' => ['Paid', 'money'], 'remaining' => ['Remaining', 'money'], 'st' => ['Status', fn($r) => $r['remaining'] == 0 ? badge('Paid') : ($r['due_date'] < reporting_date() ? badge('Overdue') : badge('Pending'))]],
            $s, ['class' => 'nofilter', 'totals' => ['principal_due', 'interest_due', 'total_due', 'paid', 'remaining']]);
        echo '<p class="muted" style="margin-top:.6rem">Maturity date ' . e(fmt_date($loan['due_date'])) . ' (' . e(setting('maturity_basis')) . ' date + ' . (int)$loan['period_months'] . ' calendar month(s)). Penalties are tracked separately.</p></div>';
    }
}

// ------------------------------------------------------------------ statement
if ($tab === 'statement') {
    $entries = rows('SELECT * FROM loan_ledger WHERE loan_id = ? ORDER BY entry_date, id', [$id]);
    $run = 0;
    foreach ($entries as &$e) { $run += (int)$e['amount']; $e['balance'] = $run; $e['debit'] = $e['amount'] > 0 ? $e['amount'] : null; $e['credit'] = $e['amount'] < 0 ? -$e['amount'] : null; }
    unset($e);
    echo '<div class="card"><div class="card-h"><h2>Loan statement</h2>' . btn('Print / PDF', url('print', ['doc' => 'statement', 'id' => $id]), 'sm') . '</div>';
    data_table(['entry_date' => ['Date', 'date'], 'description' => 'Description', 'component' => 'Component', 'type' => 'Type', 'debit' => ['Charged', 'money'], 'credit' => ['Paid / waived', 'money'], 'balance' => ['Running balance', 'money']],
        $entries, ['empty' => 'No transactions yet.']);
    echo '</div>';
}

// ------------------------------------------------------------------ payments
if ($tab === 'payments') {
    $ps = rows("SELECT p.id, r.receipt_no, p.business_date, p.amount, p.method, p.reference, u.full_name AS cashier, p.status, p.prev_balance FROM payments p
                LEFT JOIN receipts r ON r.payment_id = p.id JOIN users u ON u.id = p.cashier_id WHERE p.loan_id = ? ORDER BY p.id DESC", [$id]);
    echo '<div class="card"><h2>Payments received</h2>';
    data_table(['receipt_no' => 'Receipt', 'business_date' => ['Business date', 'date'], 'amount' => ['Amount', 'money'], 'method' => 'Method', 'reference' => 'Reference', 'cashier' => 'Cashier', 'status' => ['Status', 'badge']], $ps,
        (can('payment.view') ? ['link' => fn($r) => url('receipts', ['a' => 'view', 'id' => $r['id']])] : []) + ['empty' => 'No payments yet.']);
    echo '</div>';
}

// ------------------------------------------------------------------ guarantors
if ($tab === 'guarantors') {
    $gs = rows("SELECT lg.*, g.guarantor_no, g.surname || ' ' || g.first_name AS name, g.phone FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id WHERE lg.loan_id = ? ORDER BY lg.id", [$id]);
    echo '<div class="card"><div class="card-h"><h2>Guarantors on this loan</h2>' . (count($gs) ? btn('Print guarantor form', url('print', ['doc' => 'guarantor', 'id' => $id]), 'sm') : '') . '</div>';
    data_table(['guarantor_no' => 'ID', 'name' => 'Name', 'phone' => 'Phone', 'guarantee_amount' => ['Guarantee limit', 'money'], 'guarantee_date' => ['Date', 'date'],
        'declaration_accepted' => ['Declaration', fn($r) => $r['declaration_accepted'] ? badge('Accepted') : badge('Pending')], 'pledge_status' => 'Pledge', 'status' => ['Status', 'badge'],
        'act' => ['', fn($r) => $r['status'] === 'Active' && can('guarantor.manage') ? '<form method="post" action="' . e(url('loan', ['id' => $id, 'a' => 'release_guarantor'])) . '" data-confirm="Release this guarantor from the loan?" style="display:flex;gap:.3rem">' . csrf_field() . '<input type="hidden" name="lg_id" value="' . (int)$r['id'] . '"><input type="text" name="reason" placeholder="reason" required style="width:120px"><button class="btn sm">Release</button></form>' : '']],
        $gs, ['link' => fn($r) => url('guarantors', ['a' => 'view', 'id' => $r['guarantor_id']]), 'empty' => 'No guarantors linked yet.', 'class' => 'nofilter']);
    echo '<p class="muted" style="margin-top:.6rem">A guarantor\'s liability follows the signed agreement and applicable law; record the guarantee limit and terms below.</p></div>';
    if (can('guarantor.manage') && in_array($st, ['Draft', 'Submitted', 'Under Review', 'Returned for Correction', 'Approved'], true)) {
        $opts = [];
        foreach (rows('SELECT id, guarantor_no, surname, first_name, phone FROM guarantors WHERE branch_id = ? ORDER BY surname', [$loan['branch_id']]) as $g) $opts[$g['id']] = $g['surname'] . ' ' . $g['first_name'] . ' — ' . $g['guarantor_no'] . ' · ' . $g['phone'];
        echo '<div class="card"><div class="card-h"><h2>Link a guarantor</h2>' . btn('Register a new guarantor', url('guarantors', ['a' => 'new', 'return_loan' => $id]), 'sm') . '</div>';
        form_open(url('loan', ['id' => $id, 'a' => 'link_guarantor']), ['upload' => true]);
        field('guarantor_id', 'Guarantor', ['type' => 'select', 'options' => $opts, 'blank' => 'Select guarantor…', 'span' => 2, 'required' => true]);
        field('guarantee_date', 'Guarantee date', ['type' => 'date', 'value' => reporting_date()]);
        field('guarantee_amount', 'Guarantee limit (UGX, optional)', ['type' => 'money']);
        field('liability_terms', 'Liability terms (as signed)', ['span' => 2]);
        field('pledge_status', 'Pledge status', ['value' => 'Pledged']);
        field('declaration_accepted', 'Guarantor declaration signed & accepted', ['type' => 'checkbox']);
        field('witness_name', 'Witness name'); field('witness_phone', 'Witness phone', ['type' => 'tel']);
        field('signed_doc', 'Signed form (scan, PDF/image)', ['type' => 'file', 'span' => 2]);
        field('notes', 'Notes', ['span' => 4]);
        echo '<div class="form-actions">'; submit_btn('Link guarantor'); echo '</div>'; form_close();
        echo '</div>';
    }
}

// ------------------------------------------------------------------ securities
if ($tab === 'securities') {
    $ss = rows("SELECT s.*, g.surname AS gname FROM securities s LEFT JOIN guarantors g ON g.id = s.guarantor_id WHERE s.loan_id = ? ORDER BY s.id", [$id]);
    echo '<div class="card"><div class="card-h"><h2>Collateral on this loan</h2>' . btn('Manage in register', url('securities', ['loan_id' => $id]), 'sm') . '</div>';
    data_table(['security_no' => 'Security', 'type' => 'Type', 'description' => 'Description', 'serial_no' => 'Serial / Reg.', 'owner' => ['Owner', fn($r) => e($r['owner_type'] . ($r['gname'] ? ' (' . $r['gname'] . ')' : ''))],
        'est_value' => ['Est. value', 'money'], 'storage_location' => 'Stored at', 'status' => ['Status', 'badge']], $ss, ['empty' => 'No security registered yet.', 'class' => 'nofilter', 'totals' => ['est_value']]);
    echo '</div>';
    if (can('security.manage') && !in_array($st, ['Closed', 'Cancelled', 'Rejected'], true)) {
        $gopts = []; foreach (rows("SELECT g.id, g.surname, g.first_name FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id WHERE lg.loan_id = ?", [$id]) as $g) $gopts[$g['id']] = $g['surname'] . ' ' . $g['first_name'];
        echo '<div class="card"><h2>Register security</h2>';
        form_open(url('loan', ['id' => $id, 'a' => 'add_security']), ['upload' => true]);
        field('type', 'Security type', ['type' => 'select', 'options' => array_combine($t = ['Land title', 'Vehicle / logbook', 'Motorcycle', 'Electronics', 'Household items', 'Business stock', 'Livestock', 'Savings / deposit', 'Other'], $t), 'blank' => true, 'required' => true]);
        field('description', 'Description', ['span' => 2, 'required' => true]);
        field('serial_no', 'Serial / registration no.');
        field('owner_type', 'Owned by', ['type' => 'select', 'options' => ['Borrower' => 'Borrower', 'Guarantor' => 'Guarantor']]);
        field('guarantor_id', 'Guarantor (if guarantor-owned)', ['type' => 'select', 'options' => $gopts, 'blank' => '—']);
        field('ownership_details', 'Ownership details');
        field('est_value', 'Estimated value (UGX)', ['type' => 'money']);
        field('valuation_date', 'Valuation date', ['type' => 'date']);
        field('date_pledged', 'Date pledged', ['type' => 'date', 'value' => reporting_date()]);
        field('storage_location', 'Storage location');
        field('doc', 'Supporting document', ['type' => 'file', 'span' => 2]);
        echo '<div class="form-actions">'; submit_btn('Register security'); echo '</div>'; form_close();
        echo '</div>';
    }
}

// ------------------------------------------------------------------ documents
if ($tab === 'documents') {
    $ds = rows("SELECT a.*, u.full_name AS by_name FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE (a.entity_type = 'loan' AND a.entity_id = ?)
                OR (a.entity_type = 'security' AND a.entity_id IN (SELECT id FROM securities WHERE loan_id = ?)) ORDER BY a.id DESC", [$id, $id]);
    echo '<div class="card"><h2>Supporting documents</h2>';
    data_table(['original_name' => ['File', fn($r) => '<a target="_blank" href="' . e(attachment_url((int)$r['id'])) . '">' . e($r['original_name']) . '</a>'], 'kind' => 'Kind', 'note' => 'Note', 'by_name' => 'Uploaded by', 'uploaded_at' => ['When', 'dt']], $ds,
        ['empty' => 'No documents uploaded.', 'class' => 'nofilter']);
    echo '</div>';
    if (can('loan.create') || can('loan.approve') || can('guarantor.manage')) {
        echo '<div class="card"><h2>Upload document</h2>';
        form_open(url('loan', ['id' => $id, 'a' => 'upload']), ['upload' => true]);
        field('kind', 'Kind', ['type' => 'select', 'options' => ['signed_offer' => 'Signed offer / acceptance', 'application_form' => 'Application form', 'id_copy' => 'ID copy', 'guarantor_form' => 'Guarantor form', 'other' => 'Other']]);
        field('doc', 'File (PDF or image, max 5 MB)', ['type' => 'file', 'span' => 2]);
        field('note', 'Note');
        echo '<div class="form-actions">'; submit_btn('Upload'); echo '</div>'; form_close();
        echo '</div>';
    }
}

// ------------------------------------------------------------------ history
if ($tab === 'history') {
    echo '<div class="grid g2"><div class="card"><h2>Status history</h2>';
    data_table(['at' => ['When', 'dt'], 'from_status' => 'From', 'to_status' => ['To', 'badge'], 'who' => 'By', 'note' => 'Note'],
        rows('SELECT h.*, u.full_name AS who FROM loan_status_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.loan_id = ? ORDER BY h.id DESC', [$id]), ['class' => 'nofilter']);
    echo '</div><div class="card"><h2>Approval decisions</h2>';
    data_table(['decided_at' => ['When', 'dt'], 'decision' => ['Decision', 'badge'], 'approved_amount' => ['Amount', 'money'], 'who' => 'By', 'reason' => 'Reason', 'comments' => 'Comments'],
        rows('SELECT a.*, u.full_name AS who FROM loan_approvals a JOIN users u ON u.id = a.approver_id WHERE a.loan_id = ? ORDER BY a.id DESC', [$id]), ['class' => 'nofilter', 'empty' => 'No decisions yet.']);
    echo '</div></div>';
    if (can('audit.view')) {
        echo '<div class="card"><h2>Audit trail for this loan</h2>';
        data_table(['at' => ['When', 'dt'], 'username' => 'User', 'action' => 'Action', 'prev_value' => 'Before', 'new_value' => 'After', 'reason' => 'Reason'],
            rows("SELECT * FROM audit_logs WHERE record_type = 'loan' AND record_id = ? ORDER BY id DESC LIMIT 100", [(string)$id]), ['class' => 'nofilter']);
        echo '</div>';
    }
}
