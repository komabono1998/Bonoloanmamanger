<?php
$title = 'Branches';
$a = get('a');
$id = (int)get('id');

function branch_input(): array
{
    $days = implode(',', array_filter(array_map('trim', explode(',', post('working_days'))), fn($d) => preg_match('/^[1-7]$/', $d)));
    $time = post('close_time');
    must($time === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1, 'Closing time must look like 18:00.');
    return ['name' => clean(post('name')), 'location' => clean(post('location')) ?: null, 'phone' => clean(post('phone')) ?: null,
        'manager_id' => (int)post('manager_id') ?: null, 'working_days' => $days ?: null, 'close_time' => $time ?: null,
        'collection_target' => post('collection_target') !== '' ? parse_money(post('collection_target'), 'Collection target', true) : null];
}

if ($a === 'create') post_action(function () {
    require_perm('branch.manage');
    $d = branch_input();
    must($d['name'] !== '', 'Branch name is required.');
    must(!val('SELECT COUNT(*) FROM branches WHERE lower(name) = lower(?) OR code = ?', [$d['name'], strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code')))]), 'A branch with that name or code already exists.');
    $nid = create_branch(post('code'), $d['name'], (string)$d['location'], (string)$d['phone']);
    update('branches', ['manager_id' => $d['manager_id'], 'working_days' => $d['working_days'], 'close_time' => $d['close_time'], 'collection_target' => $d['collection_target']], 'id = :id', ['id' => $nid]);
    audit('Branch created', 'Branches', 'branch', $nid, null, $d['name'], '', '', null, null, $nid);
    branches_changed();
    return ['Branch created with its cash accounts. Assign staff to it under Users & Access.', url('branches')];
});
if ($a === 'update') post_action(function () use ($id) {
    require_perm('branch.manage');
    $b = branch_row($id);
    must((bool)$b, 'Branch not found.');
    $d = branch_input();
    must($d['name'] !== '', 'Branch name is required.');
    update('branches', $d, 'id = :id', ['id' => $id]);
    audit('Branch updated', 'Branches', 'branch', $id, ['name' => $b['name'], 'manager' => $b['manager_id'], 'days' => $b['working_days'], 'close' => $b['close_time']], $d, '', '', null, null, $id);
    branches_changed();
    return ['Branch updated.', url('branches')];
});
if ($a === 'status') post_action(function () use ($id) {
    require_perm('branch.manage');
    $b = branch_row($id);
    must((bool)$b, 'Branch not found.');
    $to = $b['status'] === 'active' ? 'inactive' : 'active';
    if ($to === 'inactive') {
        must(!val("SELECT COUNT(*) FROM business_days WHERE branch_id = ? AND status IN ('Open','Reopened')", [$id]), 'Close the branch business day before deactivating the branch.');
        must(!val("SELECT COUNT(*) FROM loans WHERE branch_id = ? AND status IN ('Active','Overdue','Approved','Under Review','Submitted')", [$id]), 'The branch still has open loans or applications.');
    }
    update('branches', ['status' => $to], 'id = :id', ['id' => $id]);
    audit('Branch ' . $to, 'Branches', 'branch', $id, $b['status'], $to, clean(post('reason')), '', null, null, $id);
    branches_changed();
    return ['Branch is now ' . $to . '.', url('branches')];
});

page_head('Branches', $a === '' ? btn('New branch', url('branches', ['a' => 'new']), 'primary') : btn('All branches', url('branches')), 'Each branch has its own clients, loans, cash accounts, business date and EOD. Business approval rights are not granted by managing branches.');
$mgrs = []; foreach (rows('SELECT id, full_name, role FROM users WHERE status = ? ORDER BY full_name', ['active']) as $u) if (in_array('authority.branch', user_permissions((int)$u['id']), true)) $mgrs[$u['id']] = $u['full_name'] . ' (' . $u['role'] . ')';

if ($a === 'new' || $a === 'edit') {
    $b = $a === 'edit' ? branch_row($id) : [];
    must($a === 'new' || (bool)$b, 'Branch not found.');
    echo '<div class="card"><h2>' . ($a === 'new' ? 'New branch' : 'Edit ' . e($b['name'])) . '</h2>';
    form_open(url('branches', ['a' => $a === 'new' ? 'create' : 'update', 'id' => $id ?: '']));
    if ($a === 'new') field('code', 'Branch code', ['required' => true, 'help' => 'e.g. KLA']);
    else echo '<div class="fld"><label>Code</label><div style="padding:.5rem 0;font-weight:600">' . e($b['code']) . '</div></div>';
    field('name', 'Branch name', ['value' => $b['name'] ?? '', 'required' => true, 'span' => 2]);
    field('location', 'Location', ['value' => $b['location'] ?? '', 'span' => 2]);
    field('phone', 'Phone', ['value' => $b['phone'] ?? '']);
    field('manager_id', 'Branch Manager', ['type' => 'select', 'options' => $mgrs, 'blank' => '—', 'value' => $b['manager_id'] ?? '']);
    field('working_days', 'Working days (1=Mon … 7=Sun)', ['value' => $b['working_days'] ?? '', 'help' => 'Leave empty to use the institution default (' . setting('working_days') . ').']);
    field('close_time', 'Normal closing time', ['type' => 'time', 'value' => $b['close_time'] ?? '', 'help' => 'Branches close their day independently.']);
    field('collection_target', 'Monthly collection target (UGX)', ['type' => 'money', 'value' => $b['collection_target'] ?? '', 'help' => 'Used by the Collection Target report.']);
    echo '<div class="form-actions">'; submit_btn('Save branch'); echo '</div>'; form_close(); echo '</div>';
    return;
}

$list = rows('SELECT b.*, u.full_name AS manager,
              (SELECT COUNT(*) FROM user_branches ub WHERE ub.branch_id = b.id) AS staff,
              (SELECT COUNT(*) FROM clients c WHERE c.branch_id = b.id) AS clients,
              (SELECT COUNT(*) FROM loans l WHERE l.branch_id = b.id AND l.status IN (\'Active\',\'Overdue\')) AS open_loans
              FROM branches b LEFT JOIN users u ON u.id = b.manager_id ORDER BY b.id');
foreach ($list as &$r) { $d = current_bd((int)$r['id']); $r['bd'] = $d; }
unset($r);
data_table(['code' => 'Code', 'name' => 'Branch', 'location' => 'Location', 'manager' => 'Manager', 'staff' => ['Staff', 'num'], 'clients' => ['Clients', 'num'], 'open_loans' => ['Open loans', 'num'], 'bd' => ['Business date', 'date'],
    'status' => ['Status', 'badge'],
    'act' => ['', fn($r) => '<a class="btn sm" href="' . e(url('branches', ['a' => 'edit', 'id' => $r['id']])) . '">Edit</a> <form method="post" action="' . e(url('branches', ['a' => 'status', 'id' => $r['id']])) . '" style="display:inline" data-confirm="Change the branch status?">'
        . csrf_field() . '<button class="btn sm ' . ($r['status'] === 'active' ? 'danger' : '') . '">' . ($r['status'] === 'active' ? 'Deactivate' : 'Activate') . '</button></form>']],
    $list, ['class' => 'nofilter']);
