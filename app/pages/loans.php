<?php
$title = 'Loan applications';
$a = get('a', 'list');
$id = (int)get('id');

if ($a === 'save') {
    post_action(function () use ($id) {
        $lid = Loans::saveApplication($_POST, $id ?: null);
        if (post('submit_now') === '1') { Loans::submit($lid); return ['Application saved and submitted for review.', url('loan', ['id' => $lid])]; }
        return ['Application saved as draft.', url('loan', ['id' => $lid])];
    });
}

if ($a === 'new' || $a === 'edit') {
    require_perm('loan.create');
    $l = $a === 'edit' ? Loans::get($id) : [];
    if ($a === 'edit') must(in_array($l['status'], ['Draft', 'Returned for Correction'], true), 'Only draft or returned applications can be edited.');
    $cid = (int)($l['client_id'] ?? get('client_id'));
    $client = $cid ? row('SELECT * FROM clients WHERE id = ?', [$cid]) : null;
    if ($client) assert_branch((int)$client['branch_id']);
    page_head($a === 'new' ? 'New loan application' : 'Edit application ' . $l['loan_no'], btn('Cancel', $id ? url('loan', ['id' => $id]) : url('loans')));
    if ($client) {
        $ex = Loans::clientExposure($cid, $id ?: null);
        if ($ex['overdue_count']) echo '<div class="notice bad"><b>Warning:</b> this client has ' . $ex['overdue_count'] . ' overdue loan(s). Resolve or record an exception before lending again.</div>';
        elseif ($ex['outstanding']) echo '<div class="notice">This client already owes <b>' . ugx($ex['outstanding']) . '</b> on active loans.</div>';
        if ($client['status'] !== 'Active') echo '<div class="notice bad">This client is ' . e(strtolower($client['status'])) . '; new loans cannot be created.</div>';
    }
    echo '<div class="card">';
    form_open(url('loans', ['a' => 'save', 'id' => $id ?: '']));
    $v = fn($k, $d = '') => $l[$k] ?? $d;
    if ($a === 'edit') {
        echo '<div class="fld span2"><label>Client</label><div style="padding:.5rem 0;font-weight:600">' . e($l['client_name']) . ' · ' . e($l['client_no']) . '</div></div>';
        field('client_id', '', ['type' => 'hidden', 'value' => $cid]);
    } else {
        field('client_id', 'Client', ['type' => 'select', 'options' => client_select_options(), 'blank' => 'Select client…', 'value' => $cid, 'required' => true, 'span' => 2,
            'attrs' => 'data-goto="' . e(url('loans', ['a' => 'new']) . '&client_id={v}') . '"']);
    }
    field('application_date', 'Application date', ['type' => 'date', 'value' => $v('application_date', reporting_date()), 'required' => true]);
    field('requested_disb_date', 'Requested disbursement date', ['type' => 'date', 'value' => $v('requested_disb_date')]);
    field('amount_requested', 'Amount requested (UGX)', ['type' => 'money', 'value' => $v('amount_requested'), 'required' => true]);
    field('period_months', 'Loan period (months)', ['type' => 'number', 'value' => $v('period_months', setting('loan_period_months')), 'attrs' => 'min="1" max="60"', 'required' => true]);
    field('frequency', 'Repayment frequency', ['type' => 'select', 'options' => ['monthly' => 'Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily'], 'value' => $v('frequency', setting('repayment_frequency'))]);
    field('purpose', 'Loan purpose', ['value' => $v('purpose'), 'required' => true, 'span' => 2]);
    echo '<h3 class="full">Financial position</h3>';
    field('monthly_income', 'Monthly income (UGX)', ['type' => 'money', 'value' => $v('monthly_income', $client['monthly_income'] ?? 0)]);
    field('monthly_expenses', 'Monthly expenses (UGX)', ['type' => 'money', 'value' => $v('monthly_expenses', $client['monthly_expenses'] ?? 0)]);
    field('existing_debt', 'Existing debt (UGX)', ['type' => 'money', 'value' => $v('existing_debt', $client['other_debt'] ?? 0)]);
    echo '<span></span>';
    field('existing_loans_info', 'Existing loan information', ['type' => 'textarea', 'value' => $v('existing_loans_info'), 'span' => 2]);
    field('security_offered', 'Security offered', ['type' => 'textarea', 'value' => $v('security_offered'), 'span' => 2, 'help' => 'Formal collateral and guarantors are registered on the loan after saving.']);
    field('officer_comments', 'Officer comments', ['type' => 'textarea', 'value' => $v('officer_comments'), 'span' => 4]);
    echo '<div class="form-actions">'; submit_btn('Save as draft');
    echo '<button type="submit" class="btn" name="submit_now" value="1">Save &amp; submit for review</button>'; echo '</div>';
    form_close();
    echo '</div>';
    return;
}

$q = get('q'); $st = get('status'); $of = (int)get('officer');
$w = [branch_scope('l.branch_id', branch_filter_ids((int)get('branch')))]; $p = [];
if ($q !== '') { $w[] = '(l.loan_no LIKE ? OR c.full_name LIKE ? OR c.client_no LIKE ? OR c.phone LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($st !== '') { $w[] = 'l.status = ?'; $p[] = $st; }
if ($of) { $w[] = 'l.officer_id = ?'; $p[] = $of; }
if (!can('loan.approve') && !can('portfolio.view') && !can('payment.view')) { $w[] = 'l.officer_id = ?'; $p[] = uid(); }
$list = rows("SELECT l.id, l.loan_no, c.full_name, c.client_no, l.application_date, l.amount_requested, l.approved_principal, l.status, u.full_name AS officer
              FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id WHERE " . implode(' AND ', $w) . ' ORDER BY l.id DESC LIMIT 500', $p);
page_head('Loan applications', can('loan.create') ? btn('New application', url('loans', ['a' => 'new']), 'primary') : '', 'Track each request from draft to approval, disbursement and closure.');
$offs = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Branch Manager','COO') ORDER BY full_name") as $u) $offs[$u['id']] = $u['full_name'];
$sts = ['Draft', 'Submitted', 'Under Review', 'Returned for Correction', 'Approved', 'Rejected', 'Cancelled', 'Active', 'Overdue', 'Closed'];
filter_bar((count(accessible_branch_ids()) > 1 ? ['branch' => ['label' => 'Branch', 'type' => 'select', 'all' => 'All my branches', 'options' => branch_options()]] : []) + ['q' => ['label' => 'Search', 'placeholder' => 'Loan no, client name, ID or phone'], 'status' => ['label' => 'Status', 'type' => 'select', 'options' => array_combine($sts, $sts)],
    'officer' => ['label' => 'Loan officer', 'type' => 'select', 'options' => $offs]], ['q' => $q, 'status' => $st, 'officer' => $of ?: '', 'branch' => get('branch')], 'loans');
data_table(['loan_no' => 'Loan no.', 'full_name' => 'Client', 'client_no' => 'Client ID', 'application_date' => ['Applied', 'date'], 'amount_requested' => ['Requested', 'money'],
    'approved_principal' => ['Approved', 'money'], 'status' => ['Status', 'badge'], 'officer' => 'Officer'], $list,
    ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'No applications found.']);
