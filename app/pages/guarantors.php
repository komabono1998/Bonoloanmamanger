<?php
$title = 'Guarantors';
$a = get('a', 'list');
$id = (int)get('id');

if ($a === 'save') {
    post_action(function () use ($id) {
        require_perm('guarantor.manage');
        $d = [
            'surname' => clean(post('surname')), 'first_name' => clean(post('first_name')), 'gender' => post('gender') ?: null,
            'dob' => must_date(post('dob'), 'Date of birth', false), 'phone' => preg_replace('/[^\d+]/', '', post('phone')),
            'nin' => strtoupper(str_replace(' ', '', post('nin'))) ?: null, 'address' => clean(post('address')) ?: null, 'village' => clean(post('village')) ?: null,
            'parish' => clean(post('parish')) ?: null, 'subcounty' => clean(post('subcounty')) ?: null, 'district' => clean(post('district')) ?: null,
            'housing_status' => post('housing_status') ?: null, 'landlord_contact' => clean(post('landlord_contact')) ?: null, 'marital_status' => post('marital_status') ?: null,
            'spouse_name' => clean(post('spouse_name')) ?: null, 'spouse_phone' => preg_replace('/[^\d+]/', '', post('spouse_phone')) ?: null,
            'nok_name' => clean(post('nok_name')) ?: null, 'nok_phone' => preg_replace('/[^\d+]/', '', post('nok_phone')) ?: null, 'notes' => trim(post('notes')) ?: null,
        ];
        must($d['surname'] !== '' && $d['first_name'] !== '', 'Surname and first name are required.');
        must(strlen($d['phone']) >= 9, 'A valid phone number is required.');
        if ($d['nin']) {
            $dup = row('SELECT guarantor_no, surname FROM guarantors WHERE nin = ? AND id <> ?', [$d['nin'], $id]);
            must(!$dup, 'A guarantor with this NIN already exists (' . ($dup['guarantor_no'] ?? '') . '). Link the existing guarantor to the loan instead.');
        }
        $gid = tx(function () use ($d, $id) {
            if ($id) {
                assert_active_branch((int)val('SELECT branch_id FROM guarantors WHERE id = ?', [$id]));
                $d['updated_at'] = now();
                update('guarantors', $d, 'id = :id', ['id' => $id]);
                audit('Guarantor updated', 'Guarantors', 'guarantor', $id, null, $d['surname'] . ' ' . $d['first_name']);
                $gid = $id;
            } else {
                $d += ['guarantor_no' => make_number('guarantor_prefix', 'guarantor', false), 'created_by' => uid(), 'created_at' => now()];
                assert_active_branch(branch_id());
                $d['branch_id'] = branch_id();
                $gid = insert('guarantors', $d);
                audit('Guarantor registered', 'Guarantors', 'guarantor', $gid, null, $d['guarantor_no']);
            }
            save_upload('photo', 'guarantor', $gid, 'photo');
            return $gid;
        });
        $back = post('return_loan');
        return ['Guarantor saved.', $back ? url('loan', ['id' => (int)$back, 'tab' => 'guarantors']) : url('guarantors', ['a' => 'view', 'id' => $gid])];
    });
}

if ($a === 'new' || $a === 'edit') {
    require_perm('guarantor.manage');
    $g = $a === 'edit' ? row('SELECT * FROM guarantors WHERE id = ?', [$id]) : [];
    must($a === 'new' || (bool)$g, 'Guarantor does not exist.');
    if ($a === 'edit') assert_active_branch((int)$g['branch_id']);
    page_head($a === 'new' ? 'Register guarantor' : 'Edit guarantor ' . $g['guarantor_no'], btn('Cancel', url('guarantors')));
    echo '<div class="card">';
    form_open(url('guarantors', ['a' => 'save', 'id' => $id ?: '']), ['upload' => true]);
    $v = fn($k) => $g[$k] ?? '';
    if (get('return_loan')) field('return_loan', '', ['type' => 'hidden', 'value' => get('return_loan')]);
    echo '<h3 class="full">Personal details</h3>';
    field('surname', 'Surname', ['value' => $v('surname'), 'required' => true]);
    field('first_name', 'First name', ['value' => $v('first_name'), 'required' => true]);
    field('gender', 'Gender', ['type' => 'select', 'options' => ['Female' => 'Female', 'Male' => 'Male'], 'blank' => true, 'value' => $v('gender')]);
    field('dob', 'Date of birth', ['type' => 'date', 'value' => $v('dob')]);
    field('phone', 'Phone number', ['type' => 'tel', 'value' => $v('phone'), 'required' => true]);
    field('nin', 'NIN', ['value' => $v('nin')]);
    photo_field('photo', 'Photograph');
    echo '<h3 class="full">Residence</h3>';
    field('address', 'Residential address', ['value' => $v('address'), 'span' => 2]);
    field('village', 'Village', ['value' => $v('village')]);
    field('parish', 'Parish / Ward', ['value' => $v('parish')]);
    field('subcounty', 'Sub-county / Division', ['value' => $v('subcounty')]);
    field('district', 'District / City', ['value' => $v('district')]);
    field('housing_status', 'Housing status', ['type' => 'select', 'options' => ['Owner' => 'Owner', 'Tenant' => 'Tenant', 'Family home' => 'Family home', 'Other' => 'Other'], 'blank' => true, 'value' => $v('housing_status')]);
    field('landlord_contact', 'Landlord contact (if tenant)', ['value' => $v('landlord_contact'), 'span' => 2]);
    echo '<h3 class="full">Family</h3>';
    field('marital_status', 'Marital status', ['type' => 'select', 'options' => ['Single' => 'Single', 'Married' => 'Married', 'Divorced' => 'Divorced', 'Widowed' => 'Widowed'], 'blank' => true, 'value' => $v('marital_status')]);
    field('spouse_name', 'Spouse name', ['value' => $v('spouse_name')]);
    field('spouse_phone', 'Spouse phone', ['type' => 'tel', 'value' => $v('spouse_phone')]);
    echo '<span></span>';
    field('nok_name', 'Next-of-kin name', ['value' => $v('nok_name')]);
    field('nok_phone', 'Next-of-kin phone', ['type' => 'tel', 'value' => $v('nok_phone')]);
    field('notes', 'Notes', ['type' => 'textarea', 'value' => $v('notes'), 'span' => 4]);
    echo '<div class="form-actions">'; submit_btn('Save guarantor'); echo '</div>';
    form_close();
    echo '</div>';
    return;
}

if ($a === 'view') {
    $g = row('SELECT * FROM guarantors WHERE id = ?', [$id]);
    must((bool)$g, 'Guarantor does not exist.');
    assert_branch((int)$g['branch_id']);
    $photo = val("SELECT id FROM attachments WHERE entity_type = 'guarantor' AND entity_id = ? AND kind='photo' ORDER BY id DESC LIMIT 1", [$id]);
    page_head($g['surname'] . ' ' . $g['first_name'], can('guarantor.manage') ? btn('Edit', url('guarantors', ['a' => 'edit', 'id' => $id])) : '', $g['guarantor_no']);
    echo '<div class="card"><div class="profile">' . photo_img($photo ? (int)$photo : null) . '<div style="flex:1">';
    $sens = can('client.sensitive');
    desc_list(['Phone' => e($g['phone']), 'NIN' => $sens ? e($g['nin']) : '<span class="muted">restricted</span>', 'Gender' => e($g['gender']), 'Date of birth' => e(fmt_date($g['dob'])),
        'Address' => e(implode(', ', array_filter([$g['address'], $g['village'], $g['parish'], $g['subcounty'], $g['district']]))), 'Housing' => e($g['housing_status']),
        'Landlord' => e($g['landlord_contact']), 'Marital status' => e($g['marital_status']), 'Spouse' => e(trim($g['spouse_name'] . ' ' . $g['spouse_phone'])),
        'Next of kin' => e(trim($g['nok_name'] . ' ' . $g['nok_phone'])), 'Notes' => e($g['notes'])], 3);
    echo '</div></div></div><div class="card"><h2>Loans guaranteed</h2>';
    $ls = rows("SELECT l.id, l.loan_no, c.full_name AS borrower, l.status, lg.guarantee_amount, lg.guarantee_date, lg.declaration_accepted, b.total_out
                FROM loan_guarantors lg JOIN loans l ON l.id = lg.loan_id JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id
                WHERE lg.guarantor_id = ? ORDER BY lg.id DESC", [$id]);
    data_table(['loan_no' => 'Loan', 'borrower' => 'Borrower', 'status' => ['Loan status', 'badge'], 'guarantee_amount' => ['Guarantee limit', 'money'], 'total_out' => ['Loan outstanding', 'money'],
        'guarantee_date' => ['Guarantee date', 'date'], 'declaration_accepted' => ['Declaration', fn($r) => $r['declaration_accepted'] ? 'Accepted' : '<span class="muted">pending</span>']], $ls,
        ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'Not linked to any loan yet.', 'class' => 'nofilter']);
    echo '</div>';
    return;
}

page_head('Guarantors', can('guarantor.manage') ? btn('Register guarantor', url('guarantors', ['a' => 'new']), 'primary') : '', 'Guarantors are linked to individual loans from the loan page.');
$q = get('q');
$p = []; $w = branch_scope('g.branch_id');
if ($q !== '') { $w .= ' AND (g.surname LIKE ? OR g.first_name LIKE ? OR g.guarantor_no LIKE ? OR g.phone LIKE ? OR g.nin LIKE ?)'; $p = array_fill(0, 5, "%$q%"); }
$list = rows("SELECT g.id, g.guarantor_no, g.surname || ' ' || g.first_name AS name, g.phone, g.district, g.nin,
              (SELECT COUNT(*) FROM loan_guarantors lg WHERE lg.guarantor_id = g.id) AS loans,
              (SELECT COUNT(*) FROM loan_guarantors lg JOIN loans l ON l.id = lg.loan_id WHERE lg.guarantor_id = g.id AND l.status IN ('Active','Overdue')) AS open_loans
              FROM guarantors g WHERE $w ORDER BY g.id DESC LIMIT 500", $p);
filter_bar(['q' => ['label' => 'Search', 'placeholder' => 'Name, ID, phone or NIN']], ['q' => $q], 'guarantors');
data_table(['guarantor_no' => 'Guarantor ID', 'name' => 'Name', 'phone' => 'Phone', 'district' => 'District', 'loans' => ['Loans', 'num'], 'open_loans' => ['Open', 'num']], $list,
    ['link' => fn($r) => url('guarantors', ['a' => 'view', 'id' => $r['id']]), 'empty' => 'No guarantors registered.']);
