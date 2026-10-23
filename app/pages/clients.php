<?php
$title = 'Clients';
$a = get('a', 'list');
$id = (int)get('id');

/** Validate and normalise client form input. */
function client_input(?int $id): array
{
    $d = [
        'full_name' => clean(post('full_name')), 'gender' => post('gender') ?: null, 'dob' => must_date(post('dob'), 'Date of birth', false),
        'nin' => strtoupper(str_replace(' ', '', post('nin'))), 'phone' => preg_replace('/[^\d+]/', '', post('phone')),
        'phone2' => preg_replace('/[^\d+]/', '', post('phone2')) ?: null, 'address' => clean(post('address')) ?: null,
        'village' => clean(post('village')) ?: null, 'parish' => clean(post('parish')) ?: null, 'subcounty' => clean(post('subcounty')) ?: null,
        'county' => clean(post('county')) ?: null, 'district' => clean(post('district')) ?: null, 'occupation' => clean(post('occupation')) ?: null,
        'employer' => clean(post('employer')) ?: null, 'business_name' => clean(post('business_name')) ?: null, 'business_location' => clean(post('business_location')) ?: null,
        'monthly_income' => parse_money(post('monthly_income') ?: '0', 'Monthly income', true), 'monthly_expenses' => parse_money(post('monthly_expenses') ?: '0', 'Monthly expenses', true),
        'other_debt' => parse_money(post('other_debt') ?: '0', 'Other existing debt', true), 'notes' => trim(post('notes')) ?: null,
    ];
    must($d['full_name'] !== '', 'Full name is required.');
    must($d['phone'] !== '' && strlen($d['phone']) >= 9, 'A valid primary phone number is required.');
    if ($d['dob']) must($d['dob'] < today(), 'Date of birth must be in the past.');
    if ($d['nin'] === '') $d['nin'] = null;
    if ($d['nin']) {
        must(preg_match('/^[A-Z0-9]{8,20}$/', $d['nin']) === 1, 'NIN must be 8-20 letters/numbers.');
        $dup = row('SELECT client_no, full_name FROM clients WHERE nin = ? AND id <> ?', [$d['nin'], (int)$id]);
        must(!$dup, 'This client already has a matching NIN (' . ($dup['full_name'] ?? '') . ', ' . ($dup['client_no'] ?? '') . ').');
    }
    $policy = setting('phone_duplicate_policy');
    if ($policy !== 'allow') {
        $dup = row('SELECT client_no, full_name FROM clients WHERE (phone = ? OR phone2 = ?) AND id <> ?', [$d['phone'], $d['phone'], (int)$id]);
        if ($dup) {
            if ($policy === 'block') throw new AppError('This phone number already belongs to ' . $dup['full_name'] . ' (' . $dup['client_no'] . ').');
            if (empty($_POST['confirm_dup_phone'])) throw new AppError('Warning: this phone number is already registered to ' . $dup['full_name'] . ' (' . $dup['client_no'] . '). If this is a shared household phone, tick "confirm shared phone" and save again.');
        }
    }
    // possible duplicate person: same name and date of birth
    if ($d['dob'] && empty($_POST['confirm_dup_person'])) {
        $dup = row('SELECT client_no FROM clients WHERE lower(full_name) = lower(?) AND dob = ? AND id <> ?', [$d['full_name'], $d['dob'], (int)$id]);
        if ($dup) throw new AppError('Warning: a client with the same name and date of birth already exists (' . $dup['client_no'] . '). Tick "this is a different person" to continue.');
    }
    return $d;
}

if ($a === 'save') {
    post_action(function () use ($id) {
        require_perm('client.manage');
        $d = client_input($id ?: null);
        if ($id) {
            $old = row('SELECT * FROM clients WHERE id = ?', [$id]);
            must((bool)$old, 'Client ID does not exist.');
            assert_active_branch((int)$old['branch_id']);
            tx(function () use ($id, $d, $old) {
                $d['updated_at'] = now(); $d['updated_by'] = uid();
                update('clients', $d, 'id = :id', ['id' => $id]);
                $diff = [];
                foreach ($d as $k => $v) if (array_key_exists($k, $old) && (string)$old[$k] !== (string)$v && !in_array($k, ['updated_at', 'updated_by'], true)) $diff[$k] = [$old[$k], $v];
                audit('Client updated', 'Clients', 'client', $id, array_map(fn($x) => $x[0], $diff), array_map(fn($x) => $x[1], $diff), trim(post('change_reason')));
                save_upload('photo', 'client', $id, 'photo');
            });
            return ['Client updated.', url('clients', ['a' => 'view', 'id' => $id])];
        }
        assert_active_branch(branch_id());
        $nid = tx(function () use ($d) {
            $d += ['client_no' => make_number('client_prefix', 'client', false), 'branch_id' => branch_id(), 'status' => 'Active', 'registered_by' => uid(), 'registered_at' => now()];
            $nid = insert('clients', $d);
            audit('Client registered', 'Clients', 'client', $nid, null, $d['client_no'] . ' ' . $d['full_name']);
            save_upload('photo', 'client', $nid, 'photo');
            return $nid;
        });
        return ['Client ' . val('SELECT client_no FROM clients WHERE id = ?', [$nid]) . ' registered.', url('clients', ['a' => 'view', 'id' => $nid])];
    });
}
if ($a === 'status') {
    post_action(function () use ($id) {
        require_perm('client.manage');
        $st = post('status');
        must(in_array($st, ['Active', 'Archived', 'Blacklisted'], true), 'Invalid status.');
        $reason = clean(post('reason'));
        must($reason !== '', 'A reason is required.');
        $c = row('SELECT * FROM clients WHERE id = ?', [$id]);
        must((bool)$c, 'Client ID does not exist.');
        assert_active_branch((int)$c['branch_id']);
        if ($st !== 'Active') must(!val("SELECT COUNT(*) FROM loans WHERE client_id = ? AND status IN ('Active','Overdue','Approved','Submitted','Under Review')", [$id]), 'This client has open loans and cannot be archived or blacklisted.');
        update('clients', ['status' => $st, 'updated_at' => now(), 'updated_by' => uid()], 'id = :id', ['id' => $id]);
        audit('Client status changed', 'Clients', 'client', $id, $c['status'], $st, $reason);
        return 'Client status updated to ' . $st . '.';
    });
}
if ($a === 'photo') {
    post_action(function () use ($id) {
        require_perm('client.manage');
        must(in_array((int)val('SELECT branch_id FROM clients WHERE id = ?', [$id]), accessible_branch_ids(), true), 'You do not have access to records of that branch.');
        $att = save_upload('photo', 'client', $id, 'photo');
        must((bool)$att, 'Choose a photograph to upload.');
        return 'Photograph uploaded.';
    });
}

// ----------------------------------------------------------------- views
if ($a === 'new' || $a === 'edit') {
    require_perm('client.manage');
    $c = $a === 'edit' ? row('SELECT * FROM clients WHERE id = ?', [$id]) : [];
    must($a === 'new' || (bool)$c, 'Client ID does not exist.');
    if ($a === 'edit') { assert_active_branch((int)$c['branch_id']); assert_portfolio_client($c); }
    page_head($a === 'new' ? 'Register client' : 'Edit client ' . $c['client_no'], btn('Cancel', $id ? url('clients', ['a' => 'view', 'id' => $id]) : url('clients')));
    echo '<div class="card">';
    form_open(url('clients', ['a' => 'save', 'id' => $id ?: '']), ['upload' => true]);
    $v = fn($k, $d = '') => $c[$k] ?? $d;
    echo '<h3 class="full">Identification</h3>';
    field('full_name', 'Full name', ['value' => $v('full_name'), 'required' => true, 'span' => 2]);
    field('gender', 'Gender', ['type' => 'select', 'options' => ['Female' => 'Female', 'Male' => 'Male'], 'blank' => true, 'value' => $v('gender')]);
    field('dob', 'Date of birth', ['type' => 'date', 'value' => $v('dob')]);
    field('nin', 'NIN (National ID)', ['value' => $v('nin'), 'span' => 2, 'help' => 'Duplicate NINs are blocked.']);
    photo_field('photo', 'Photograph', ['current' => $a === 'edit' ? (int)val("SELECT id FROM attachments WHERE entity_type = 'client' AND entity_id = ? AND kind = 'photo' ORDER BY id DESC LIMIT 1", [$id]) : null]);
    echo '<h3 class="full">Contact</h3>';
    field('phone', 'Primary phone', ['type' => 'tel', 'value' => $v('phone'), 'required' => true]);
    field('phone2', 'Alternative phone', ['type' => 'tel', 'value' => $v('phone2')]);
    field('address', 'Residential address', ['value' => $v('address'), 'span' => 2]);
    field('village', 'Village / Cell', ['value' => $v('village')]);
    field('parish', 'Parish / Ward', ['value' => $v('parish')]);
    field('subcounty', 'Sub-county', ['value' => $v('subcounty')]);
    field('county', 'County', ['value' => $v('county')]);
    field('district', 'District / City', ['value' => $v('district')]);
    echo '<h3 class="full">Economic information</h3>';
    field('occupation', 'Occupation', ['value' => $v('occupation')]);
    field('employer', 'Employer', ['value' => $v('employer')]);
    field('business_name', 'Business name', ['value' => $v('business_name')]);
    field('business_location', 'Business location', ['value' => $v('business_location')]);
    field('monthly_income', 'Monthly income (UGX)', ['type' => 'money', 'value' => $v('monthly_income', 0)]);
    field('monthly_expenses', 'Monthly expenses (UGX)', ['type' => 'money', 'value' => $v('monthly_expenses', 0)]);
    field('other_debt', 'Other existing debt (UGX)', ['type' => 'money', 'value' => $v('other_debt', 0)]);
    echo '<div class="fld"><label>Estimated disposable income</label><div style="padding:.5rem 0;font-weight:650">' . ugx(((int)$v('monthly_income', 0)) - (int)$v('monthly_expenses', 0) - (int)$v('other_debt', 0)) . '</div><small>Income − expenses − debt (updates on save)</small></div>';
    field('notes', 'Notes', ['type' => 'textarea', 'value' => $v('notes'), 'span' => 4]);
    if ($a === 'edit') field('change_reason', 'Reason for correction (audited)', ['span' => 4]);
    field('confirm_dup_phone', 'Confirm shared phone number (household phone)', ['type' => 'checkbox', 'span' => 2]);
    field('confirm_dup_person', 'This is a different person with the same name and birth date', ['type' => 'checkbox', 'span' => 2]);
    echo '<div class="form-actions">'; submit_btn($a === 'new' ? 'Register client' : 'Save changes'); echo '</div>';
    form_close();
    echo '</div>';
    return;
}

if ($a === 'view') {
    $c = row('SELECT c.*, u.full_name AS reg_by FROM clients c LEFT JOIN users u ON u.id = c.registered_by WHERE c.id = ?', [$id]);
    must((bool)$c, 'Client ID does not exist.');
    assert_branch((int)$c['branch_id']);
    assert_portfolio_client($c);
    if (can('client.sensitive')) audit('Sensitive client record viewed', 'Clients', 'client', $id);
    $photo = val("SELECT id FROM attachments WHERE entity_type = 'client' AND entity_id = ? AND kind = 'photo' ORDER BY id DESC LIMIT 1", [$id]);
    $tab = get('tab', 'overview');
    $act = '';
    if (can('client.manage')) $act .= btn('Edit', url('clients', ['a' => 'edit', 'id' => $id]));
    if (can('loan.create') && $c['status'] === 'Active') $act .= btn('New loan application', url('loans', ['a' => 'new', 'client_id' => $id]), 'primary');
    page_head($c['full_name'], $act, $c['client_no'] . ' · registered ' . fmt_date($c['registered_at']));
    $sens = can('client.sensitive');
    echo '<div class="card"><div class="profile">' . photo_img($photo ? (int)$photo : null) . '<div style="flex:1">';
    desc_list(['Client ID' => e($c['client_no']), 'Status' => badge($c['status']), 'Phone' => e($c['phone'] . ($c['phone2'] ? ' / ' . $c['phone2'] : '')),
        'NIN' => $sens ? e($c['nin']) : '<span class="muted">restricted</span>', 'Gender' => e($c['gender']), 'Date of birth' => e(fmt_date($c['dob'])),
        'Address' => e(implode(', ', array_filter([$c['address'], $c['village'], $c['parish'], $c['subcounty'], $c['county'], $c['district']]))),
        'Occupation' => e(trim(($c['occupation'] ?? '') . ($c['employer'] ? ' · ' . $c['employer'] : ''))),
        'Business' => e(trim(($c['business_name'] ?? '') . ($c['business_location'] ? ' · ' . $c['business_location'] : '')))], 3);
    echo '</div></div></div>';
    tabs(['overview' => 'Overview', 'loans' => 'Loans', 'payments' => 'Payments & receipts', 'guarantors' => 'Guarantors', 'securities' => 'Securities', 'credits' => 'Credits & statements', 'admin' => 'Status & photo'], $tab);

    if ($tab === 'overview') {
        $ex = Loans::clientExposure($id);
        echo '<div class="kpis">' . kpi('Loans taken', num(val("SELECT COUNT(*) FROM loans WHERE client_id = ? AND disbursed_at IS NOT NULL", [$id])))
            . kpi('Outstanding balance', ugx($ex['outstanding']), '', $ex['outstanding'] ? 'warn' : '') . kpi('Overdue loans', num($ex['overdue_count']), '', $ex['overdue_count'] ? 'bad' : '')
            . kpi('Settled loans', num($ex['closed_count']), '', 'muted') . kpi('Open credit', ugx((int)val("SELECT COALESCE(SUM(remaining),0) FROM customer_credits WHERE client_id = ? AND status='Open'", [$id]))) . '</div>';
        if ($sens) {
            echo '<div class="card"><h2>Economic position</h2>';
            desc_list(['Monthly income' => ugx($c['monthly_income']), 'Monthly expenses' => ugx($c['monthly_expenses']), 'Other existing debt' => ugx($c['other_debt']),
                'Estimated disposable income' => '<b>' . ugx($c['monthly_income'] - $c['monthly_expenses'] - $c['other_debt']) . '</b>', 'Registered by' => e($c['reg_by']), 'Notes' => e($c['notes'])], 3);
            echo '</div>';
        }
        $prev = rows("SELECT loan_no, status, closed_date, overdue_since FROM loans WHERE client_id = ? AND (overdue_since IS NOT NULL OR collection_status = 'Referred for Review')", [$id]);
        if ($prev) echo '<div class="notice bad">Previous or current arrears recorded on: ' . e(implode(', ', array_column($prev, 'loan_no'))) . '</div>';
    }
    if ($tab === 'loans') {
        $ls = rows("SELECT l.*, b.total_out FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.client_id = ? ORDER BY l.id DESC", [$id]);
        data_table(['loan_no' => 'Loan', 'application_date' => ['Applied', 'date'], 'amount_requested' => ['Requested', 'money'], 'approved_principal' => ['Approved', 'money'],
            'disbursement_date' => ['Disbursed', 'date'], 'due_date' => ['Due', 'date'], 'total_out' => ['Outstanding', 'money'], 'status' => ['Status', 'badge']], $ls,
            ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'No loan applications yet.']);
    }
    if ($tab === 'payments') {
        $ps = rows("SELECT p.id, r.receipt_no, p.business_date, l.loan_no, p.amount, p.method, p.reference, p.status FROM payments p JOIN loans l ON l.id = p.loan_id
                    LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.client_id = ? ORDER BY p.id DESC", [$id]);
        data_table(['receipt_no' => 'Receipt', 'business_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'amount' => ['Amount', 'money'], 'method' => 'Method', 'reference' => 'Reference', 'status' => ['Status', 'badge']], $ps,
            ['link' => fn($r) => url('receipts', ['a' => 'view', 'id' => $r['id']]), 'empty' => 'No payments yet.']);
    }
    if ($tab === 'guarantors') {
        $gs = rows("SELECT g.id, g.guarantor_no, g.surname || ' ' || g.first_name AS name, g.phone, l.loan_no, lg.guarantee_amount, lg.status FROM loan_guarantors lg
                    JOIN guarantors g ON g.id = lg.guarantor_id JOIN loans l ON l.id = lg.loan_id WHERE lg.client_id = ? ORDER BY lg.id DESC", [$id]);
        data_table(['guarantor_no' => 'Guarantor', 'name' => 'Name', 'phone' => 'Phone', 'loan_no' => 'Loan', 'guarantee_amount' => ['Guaranteed', 'money'], 'status' => ['Status', 'badge']], $gs,
            ['link' => fn($r) => url('guarantors', ['a' => 'view', 'id' => $r['id']]), 'empty' => 'No guarantors linked.']);
    }
    if ($tab === 'securities') {
        $ss = rows("SELECT s.id, s.security_no, s.type, s.description, s.est_value, s.owner_type, s.status, l.loan_no FROM securities s JOIN loans l ON l.id = s.loan_id WHERE s.client_id = ? ORDER BY s.id DESC", [$id]);
        data_table(['security_no' => 'Security', 'type' => 'Type', 'description' => 'Description', 'owner_type' => 'Owner', 'est_value' => ['Est. value', 'money'], 'loan_no' => 'Loan', 'status' => ['Status', 'badge']], $ss,
            ['empty' => 'No securities registered.']);
    }
    if ($tab === 'credits') {
        $cr = rows("SELECT * FROM customer_credits WHERE client_id = ? ORDER BY id DESC", [$id]);
        echo '<div class="card"><h2>Customer credits (overpayments)</h2>';
        data_table(['id' => '#', 'created_at' => ['Created', 'dt'], 'amount' => ['Amount', 'money'], 'remaining' => ['Remaining', 'money'], 'status' => ['Status', 'badge']], $cr, ['empty' => 'No credits.', 'class' => 'nofilter']);
        echo '</div><div class="card"><h2>Loan statements</h2>';
        $ls = rows("SELECT id, loan_no, status FROM loans WHERE client_id = ? AND disbursed_at IS NOT NULL ORDER BY id DESC", [$id]);
        data_table(['loan_no' => 'Loan', 'status' => ['Status', 'badge'], 'id' => ['Statement', fn($r) => '<a class="btn sm" target="_blank" href="' . e(url('print', ['doc' => 'statement', 'id' => $r['id']])) . '">Open statement</a>']], $ls, ['empty' => 'No disbursed loans.', 'class' => 'nofilter']);
        echo '</div>';
    }
    if ($tab === 'admin' && can('client.manage')) {
        echo '<div class="grid g2"><div class="card"><h2>Photograph</h2>';
        form_open(url('clients', ['a' => 'photo', 'id' => $id]), ['upload' => true, 'attrs' => 'style="grid-template-columns:1fr"']);
        photo_field('photo', 'Photograph', ['span' => 1, 'current' => $photo ? (int)$photo : null]);
        echo '<div class="form-actions">'; submit_btn('Upload'); echo '</div>'; form_close();
        echo '</div><div class="card"><h2>Client status</h2><p class="muted">Clients are never deleted. Archive inactive clients; blacklist to block new loans.</p>';
        form_open(url('clients', ['a' => 'status', 'id' => $id]), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Change this client\'s status?"']);
        field('status', 'New status', ['type' => 'select', 'options' => ['Active' => 'Active', 'Archived' => 'Archived', 'Blacklisted' => 'Blacklisted'], 'value' => $c['status']]);
        field('reason', 'Reason', ['required' => true]);
        echo '<div class="form-actions">'; submit_btn('Update status', 'danger'); echo '</div>'; form_close();
        echo '</div></div>';
    }
    return;
}

// ----------------------------------------------------------------- list
$q = get('q'); $st = get('status', 'Active');
$where = [branch_scope('c.branch_id', branch_filter_ids((int)get('branch'))) . client_scope('c')]; $p = [];
if ($q !== '') { $where[] = '(c.full_name LIKE ? OR c.client_no LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ? OR c.nin LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($st !== '' && $st !== 'all') { $where[] = 'c.status = ?'; $p[] = $st; }
$list = rows("SELECT c.*, (SELECT COUNT(*) FROM loans l WHERE l.client_id = c.id AND l.status IN ('Active','Overdue')) AS open_loans,
              COALESCE((SELECT SUM(b.total_out) FROM v_loan_balances b JOIN loans l ON l.id=b.loan_id WHERE l.client_id = c.id AND l.status IN ('Active','Overdue')),0) AS owed
              FROM clients c WHERE " . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT 500', $p);
page_head('Clients', can('client.manage') ? btn('Register client', url('clients', ['a' => 'new']), 'primary') : '', 'One authoritative record per borrower.');
filter_bar((count(accessible_branch_ids()) > 1 ? ['branch' => ['label' => 'Branch', 'type' => 'select', 'all' => 'All my branches', 'options' => branch_options()]] : []) + ['q' => ['label' => 'Search', 'placeholder' => 'Name, Client ID, phone or NIN'], 'status' => ['label' => 'Status', 'type' => 'select', 'all' => 'All', 'options' => ['Active' => 'Active', 'Archived' => 'Archived', 'Blacklisted' => 'Blacklisted']]], ['q' => $q, 'status' => $st === 'all' ? '' : $st, 'branch' => get('branch')], 'clients');
$brn = array_column(rows('SELECT id, name FROM branches'), 'name', 'id');
foreach ($list as &$r) $r['branch'] = $brn[$r['branch_id']] ?? '';
unset($r);
data_table((count(accessible_branch_ids()) > 1 ? ['branch' => 'Branch'] : []) + ['client_no' => 'Client ID', 'full_name' => 'Name', 'phone' => 'Phone', 'district' => 'District', 'open_loans' => ['Open loans', 'num'], 'owed' => ['Owed', 'money'], 'status' => ['Status', 'badge'], 'registered_at' => ['Registered', 'date']], $list,
    ['link' => fn($r) => url('clients', ['a' => 'view', 'id' => $r['id']]), 'empty' => 'No clients match.']);
