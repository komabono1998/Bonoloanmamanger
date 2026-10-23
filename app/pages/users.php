<?php
$title = 'Users & permissions';
$a = get('a');
$tab = get('tab', 'users');
$id = (int)get('id');
$roles = array_column(rows('SELECT role FROM roles ORDER BY rowid'), 'role');

function active_admins(): int { return (int)val("SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND status = 'active'"); }

if ($a === 'create') post_action(function () use ($roles) {
    $un = post('username'); $pw = $_POST['password'] ?? '';
    must(preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $un) === 1, 'Username must be 3-30 letters, numbers, dots, dashes or underscores.');
    must(!val('SELECT COUNT(*) FROM users WHERE username = ?', [$un]), 'That username is already taken.');
    must(clean(post('full_name')) !== '', 'Full name is required.');
    must(in_array(post('role'), $roles, true), 'Choose a role.');
    password_policy_check($pw);
    $nid = insert('users', ['full_name' => clean(post('full_name')), 'username' => $un, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => post('role'), 'phone' => clean(post('phone')) ?: null,
        'status' => 'active', 'created_at' => now(), 'created_by' => uid(), 'password_changed_at' => now(), 'must_change_password' => post('must_change') ? 1 : 0]);
    audit('User created', 'Users', 'user', $nid, null, $un . ' (' . post('role') . ')');
    return ['User created.', url('users', ['a' => 'edit', 'id' => $nid])];
});
if ($a === 'update') post_action(function () use ($id, $roles) {
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    $role = post('role'); $status = post('status');
    must(in_array($role, $roles, true) && in_array($status, ['active', 'inactive'], true), 'Invalid role or status.');
    if ($id === uid()) must($status === 'active' && $role === $u['role'], 'You cannot deactivate or change the role of your own account.');
    if ($u['role'] === 'Administrator' && ($role !== 'Administrator' || $status !== 'active')) must(active_admins() > 1, 'At least one active Administrator is required.');
    update('users', ['full_name' => clean(post('full_name')), 'phone' => clean(post('phone')) ?: null, 'role' => $role, 'status' => $status], 'id = :id', ['id' => $id]);
    audit('User updated', 'Users', 'user', $id, ['role' => $u['role'], 'status' => $u['status']], ['role' => $role, 'status' => $status]);
    return 'User updated.';
});
if ($a === 'reset') post_action(function () use ($id) {
    $pw = $_POST['password'] ?? '';
    password_policy_check($pw);
    must((bool)val('SELECT COUNT(*) FROM users WHERE id = ?', [$id]), 'User not found.');
    update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'password_changed_at' => now(), 'must_change_password' => 1, 'failed_attempts' => 0, 'locked_until' => null], 'id = :id', ['id' => $id]);
    audit('Password reset by administrator', 'Users', 'user', $id);
    return 'Password reset. The user must choose a new password at next sign-in.';
});
if ($a === 'perms') post_action(function () use ($id) {
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    $rolePerms = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$u['role']]), 'permission');
    $want = array_keys((array)($_POST['perm'] ?? []));
    tx(function () use ($id, $rolePerms, $want, $u) {
        $before = user_permissions($id);
        q('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
        foreach (all_permissions() as $p) {
            $has = in_array($p, $want, true); $in = in_array($p, $rolePerms, true);
            if ($has !== $in) q('INSERT INTO user_permissions(user_id, permission, granted) VALUES (?,?,?)', [$id, $p, $has ? 1 : 0]);
        }
        audit('User permissions changed', 'Users', 'user', $id, ['granted' => array_values(array_diff($want, $before)), 'revoked' => array_values(array_diff($before, $want))], null, clean(post('reason')));
    });
    unset($GLOBALS['__perms']);
    return 'Individual permissions saved.';
});
if ($a === 'roleperms') post_action(function () {
    $role = post('role');
    must($role !== 'Administrator', 'The Administrator role always holds every permission.');
    must((bool)val('SELECT COUNT(*) FROM roles WHERE role = ?', [$role]), 'Unknown role.');
    $want = array_values(array_intersect(array_keys((array)($_POST['perm'] ?? [])), all_permissions()));
    tx(function () use ($role, $want) {
        $before = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission');
        q('DELETE FROM role_permissions WHERE role = ?', [$role]);
        foreach ($want as $p) q('INSERT INTO role_permissions(role, permission) VALUES (?,?)', [$role, $p]);
        audit('Role permissions changed', 'Users', 'role', $role, ['granted' => array_values(array_diff($want, $before)), 'revoked' => array_values(array_diff($before, $want))]);
    });
    unset($GLOBALS['__perms']);
    return ['Permissions for ' . $role . ' saved.', url('users', ['tab' => 'roles', 'role' => $role])];
});

page_head('Users & permissions', $tab === 'users' && $a !== 'new' && $a !== 'edit' ? btn('New user', url('users', ['a' => 'new']), 'primary') : '');
tabs(['users' => 'Users', 'roles' => 'Role permissions'], $tab);

function perm_grid(array $checked, array $roleDefault = [], bool $disabled = false): void
{
    foreach (permission_catalogue() as $group => $perms) {
        echo '<h3>' . e($group) . '</h3><div class="perm-grid">';
        foreach ($perms as $k => $label) {
            $isOv = $roleDefault && (in_array($k, $checked, true) !== in_array($k, $roleDefault, true));
            echo '<label><input type="checkbox" name="perm[' . e($k) . ']" value="1"' . (in_array($k, $checked, true) ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> ' . e($label) . ($isOv ? ' <span class="badge b-warn">override</span>' : '') . '</label>';
        }
        echo '</div>';
    }
}

if ($tab === 'users' && ($a === 'new')) {
    echo '<div class="card"><h2>New user</h2>';
    form_open(url('users', ['a' => 'create']));
    field('full_name', 'Full name', ['required' => true, 'span' => 2]); field('username', 'Username', ['required' => true]); field('phone', 'Phone', ['type' => 'tel']);
    field('role', 'Role', ['type' => 'select', 'options' => array_combine($roles, $roles), 'span' => 1]);
    field('password', 'Temporary password', ['type' => 'password', 'required' => true, 'help' => '8+ characters, letters and numbers.', 'attrs' => 'autocomplete="new-password"']);
    field('must_change', 'Require password change at first sign-in', ['type' => 'checkbox', 'value' => '1', 'span' => 2]);
    echo '<div class="form-actions">'; submit_btn('Create user'); echo '</div>'; form_close(); echo '</div>';
} elseif ($tab === 'users' && $a === 'edit') {
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    echo '<div class="grid g2"><div class="card"><h2>' . e($u['full_name']) . ' <span class="muted">@' . e($u['username']) . '</span></h2>';
    form_open(url('users', ['a' => 'update', 'id' => $id]), ['attrs' => 'style="grid-template-columns:1fr 1fr"']);
    field('full_name', 'Full name', ['value' => $u['full_name'], 'required' => true]); field('phone', 'Phone', ['value' => $u['phone']]);
    field('role', 'Role', ['type' => 'select', 'options' => array_combine($roles, $roles), 'value' => $u['role']]);
    field('status', 'Account status', ['type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'value' => $u['status']]);
    echo '<div class="form-actions">'; submit_btn('Save'); echo btn('Back', url('users')); echo '</div>'; form_close();
    desc_list(['Created' => e(fmt_dt($u['created_at'])), 'Last login' => e(fmt_dt($u['last_login'])), 'Password changed' => e(fmt_dt($u['password_changed_at'])), 'Failed attempts' => e($u['failed_attempts'])], 2);
    echo '</div><div class="card"><h2>Reset password</h2>';
    form_open(url('users', ['a' => 'reset', 'id' => $id]), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Reset this user\'s password?"']);
    field('password', 'New temporary password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']);
    echo '<div class="form-actions">'; submit_btn('Reset password', 'danger'); echo '</div>'; form_close();
    echo '</div></div><div class="card"><h2>Individual permissions</h2><p class="muted">Starts from the <b>' . e($u['role']) . '</b> role. Tick or untick to give or remove a permission for this person only.</p>';
    $rolePerms = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$u['role']]), 'permission');
    form_open(url('users', ['a' => 'perms', 'id' => $id]));
    echo '<div class="full" style="grid-column:1/-1">'; perm_grid(user_permissions($id), $rolePerms); echo '</div>';
    field('reason', 'Reason for the change (audited)', ['span' => 4]);
    echo '<div class="form-actions">'; submit_btn('Save permissions'); echo '</div>'; form_close(); echo '</div>';
} elseif ($tab === 'users') {
    $us = rows('SELECT * FROM users ORDER BY status, full_name');
    data_table(['full_name' => 'Name', 'username' => 'Username', 'role' => 'Role', 'phone' => 'Phone', 'status' => ['Status', 'badge'], 'last_login' => ['Last login', 'dt'], 'password_changed_at' => ['Password changed', 'dt']], $us,
        ['link' => fn($r) => url('users', ['a' => 'edit', 'id' => $r['id']])]);
}

if ($tab === 'roles') {
    $role = get('role', 'Manager');
    echo '<div class="card"><div class="card-h"><h2>Role permissions</h2><div class="actions">';
    foreach ($roles as $r) echo '<a class="btn sm ' . ($r === $role ? 'primary' : '') . '" href="' . e(url('users', ['tab' => 'roles', 'role' => $r])) . '">' . e($r) . '</a>';
    echo '</div></div><p class="muted">' . e(role_descriptions()[$role] ?? '') . ($role === 'Administrator' ? ' The Administrator role always holds every permission.' : '') . '</p>';
    $cur = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission');
    form_open(url('users', ['a' => 'roleperms', 'tab' => 'roles']));
    field('role', '', ['type' => 'hidden', 'value' => $role]);
    echo '<div class="full" style="grid-column:1/-1">'; perm_grid($role === 'Administrator' ? all_permissions() : $cur, [], $role === 'Administrator'); echo '</div>';
    if ($role !== 'Administrator') { echo '<div class="form-actions">'; submit_btn('Save role permissions'); echo '</div>'; }
    form_close(); echo '</div>';
}
