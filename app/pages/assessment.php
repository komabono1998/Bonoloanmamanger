<?php
$title = 'Credit assessment';
$id = (int)get('id');

if ($id) {
    post_action(function () use ($id) {
        Loans::recordAssessment($id, $_POST);
        return ['Assessment recorded. The application is now with the approving manager.', url('loan', ['id' => $id])];
    });
    $l = Loans::get($id);
    page_head('Credit assessment — ' . $l['loan_no'], btn('Loan file', url('loan', ['id' => $id])), $l['client_name'] . ' · requested ' . ugx($l['amount_requested']));
    $ex = Loans::clientExposure((int)$l['client_id'], $id);
    $client = row('SELECT * FROM clients WHERE id = ?', [$l['client_id']]);
    if ($ex['overdue_count']) echo '<div class="notice bad"><b>Overdue loan(s):</b> ' . $ex['overdue_count'] . ' existing loan(s) are overdue.</div>';
    if ($ex['outstanding']) echo '<div class="notice">Existing outstanding balance across active loans: <b>' . ugx($ex['outstanding']) . '</b></div>';
    echo '<div class="grid g2"><div class="card"><h2>Client position</h2>';
    desc_list(['Monthly income (profile)' => ugx($client['monthly_income']), 'Monthly expenses (profile)' => ugx($client['monthly_expenses']), 'Other debt (profile)' => ugx($client['other_debt']),
        'Business / employer' => e(trim($client['business_name'] . ' ' . $client['employer'])), 'Loans repaid' => num($ex['closed_count']), 'Arrears history' => $ex['late_history'] ? '<span class="ico-bad">' . $ex['late_history'] . ' loan(s) with arrears</span>' : 'None recorded'], 2);
    echo '<h3>Existing loans</h3>';
    data_table(['loan_no' => 'Loan', 'status' => ['Status', 'badge'], 'approved_principal' => ['Principal', 'money'], 'total_out' => ['Outstanding', 'money'], 'due_date' => ['Due', 'date']], $ex['loans'], ['class' => 'nofilter', 'empty' => 'No previous loans.']);
    echo '</div><div class="card"><h2>Guarantors & security</h2>';
    $g = rows("SELECT g.surname || ' ' || g.first_name AS name, g.phone, lg.guarantee_amount, lg.declaration_accepted FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id WHERE lg.loan_id = ? AND lg.status = 'Active'", [$id]);
    data_table(['name' => 'Guarantor', 'phone' => 'Phone', 'guarantee_amount' => ['Limit', 'money'], 'declaration_accepted' => ['Signed', fn($r) => $r['declaration_accepted'] ? badge('Accepted') : badge('Pending')]], $g, ['class' => 'nofilter', 'empty' => 'No guarantors linked.']);
    $s = rows("SELECT type, description, est_value, status FROM securities WHERE loan_id = ? AND status <> 'Released'", [$id]);
    echo '<h3>Security</h3>';
    data_table(['type' => 'Type', 'description' => 'Description', 'est_value' => ['Value', 'money'], 'status' => ['Status', 'badge']], $s, ['class' => 'nofilter', 'empty' => 'No security registered.', 'totals' => ['est_value']]);
    echo '</div></div>';

    if (in_array($l['status'], ['Submitted', 'Under Review'], true) && can('loan.assess')) {
        $prev = Loans::latestAssessment($id);
        echo '<div class="card"><h2>' . ($prev ? 'Update assessment' : 'Record assessment') . '</h2><p class="muted">Assessment supports the decision — it does not guarantee the borrower can repay.</p>';
        form_open(null);
        field('income', 'Monthly income (UGX)', ['type' => 'money', 'value' => $l['monthly_income'] ?: $client['monthly_income']]);
        field('expenses', 'Monthly expenses (UGX)', ['type' => 'money', 'value' => $l['monthly_expenses'] ?: $client['monthly_expenses']]);
        field('existing_obligations', 'Existing obligations (UGX/month)', ['type' => 'money', 'value' => $l['existing_debt'] ?: $client['other_debt']]);
        field('recommended_amount', 'Recommended amount (UGX)', ['type' => 'money', 'value' => $prev['recommended_amount'] ?? $l['amount_requested']]);
        field('business_details', 'Business / employment details', ['type' => 'textarea', 'span' => 2, 'value' => $prev['business_details'] ?? trim(($client['business_name'] ?? '') . ' ' . ($client['occupation'] ?? ''))]);
        field('repayment_history', 'Client repayment history', ['type' => 'textarea', 'span' => 2, 'value' => $prev['repayment_history'] ?? ($ex['closed_count'] . ' previous loan(s) repaid.')]);
        field('guarantor_summary', 'Guarantor summary', ['type' => 'textarea', 'span' => 2, 'value' => $prev['guarantor_summary'] ?? (count($g) . ' guarantor(s) linked.')]);
        field('previous_defaults', 'Previous defaults', ['type' => 'textarea', 'span' => 2, 'value' => $prev['previous_defaults'] ?? ($ex['late_history'] ? 'Arrears recorded on ' . $ex['late_history'] . ' loan(s).' : 'None.')]);
        field('recommendation', 'Recommendation', ['type' => 'select', 'options' => ['Approve' => 'Approve', 'Approve with changes' => 'Approve with changes', 'Reject' => 'Reject'], 'required' => true]);
        field('notes', 'Assessment notes', ['type' => 'textarea', 'span' => 3]);
        echo '<div class="form-actions">'; submit_btn('Save assessment'); echo '</div>'; form_close();
        echo '</div>';
    }
    return;
}

page_head('Credit assessment', '', 'Applications waiting for an assessment, and those already assessed.');
$list = rows("SELECT l.id, l.loan_no, c.full_name, l.amount_requested, l.status, l.application_date, u.full_name AS officer,
              (SELECT recommendation FROM credit_assessments a WHERE a.loan_id = l.id ORDER BY a.id DESC LIMIT 1) AS recommendation
              FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id WHERE l.status IN ('Submitted','Under Review') AND " . branch_scope('l.branch_id') . ' ORDER BY l.id', []);
data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'application_date' => ['Applied', 'date'], 'amount_requested' => ['Requested', 'money'], 'officer' => 'Officer', 'recommendation' => 'Recommendation', 'status' => ['Status', 'badge']],
    $list, ['link' => fn($r) => url('assessment', ['id' => $r['id']]), 'empty' => 'No applications are waiting for assessment.']);
