<?php
$title = 'Users & access';
$a = get('a');
$tab = get('tab', 'users');
$id = (int)get('id');
$roles = array_column(rows('SELECT role FROM roles ORDER BY sort_order'), 'role');

function active_admins(): int { return (int)val("SELECT COUNT(*) FROM users WHERE role = 'System Administrator' AND status = 'active'"); }

function save_user_branches(int $userId, array $branchIds, int $primary): void
{
    $valid = array_keys(all_branch_options(false));
    $ids = array_values(array_intersect(array_map('intval', $branchIds), $valid));
    q('DELETE FROM user_branches WHERE user_id = ?', [$userId]);
    foreach ($ids as $b) q('INSERT INTO user_branches(user_id, branch_id, is_primary) VALUES (?,?,?)', [$userId, $b, $b === $primary ? 1 : 0]);
    if ($ids && !in_array($primary, $ids, true)) q('UPDATE user_branches SET is_primary = 1 WHERE user_id = ? AND branch_id = ?', [$userId, $ids[0]]);
}

if ($a === 'create') post_action(function () use ($roles) {
    require_perm('user.manage');
    $un = strtolower(post('username')); $pw = $_POST['password'] ?? '';
    must(preg_match('/^[a-z0-9_.-]{3,30}$/', $un) === 1, 'Username must be 3-30 lowercase letters, numbers, dots, dashes or underscores.');
    must(!val('SELECT COUNT(*) FROM users WHERE lower(username) = ?', [$un]), 'That username is already taken.');
    must(clean(post('full_name')) !== '', 'Full name is required.');
    must(in_array(post('role'), $roles, true), 'Choose a role.');
    $email = clean(post('email'));
    must($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Enter a valid email address.');
    $invite = $pw === '' && $email !== '';
    must($invite || $pw !== '', 'Give a temporary password, or enter an email address so the user can choose their own through an invitation link.');
    if ($invite) $pw = bin2hex(random_bytes(12)) . 'Aa1';
    password_policy_check($pw);
    $branches = (array)($_POST['branches'] ?? []);
    if (post('role') !== 'System Administrator' && post('role') !== 'COO') must(count($branches) > 0, 'Assign at least one branch to this user.');
    $nid = tx(function () use ($un, $pw, $branches, $invite) {
        $nid = insert('users', ['full_name' => clean(post('full_name')), 'username' => $un, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => post('role'), 'phone' => clean(post('phone')) ?: null,
            'email' => clean(post('email')) ?: null, 'status' => 'active', 'created_at' => now(), 'created_by' => uid(), 'password_changed_at' => now(), 'must_change_password' => ($invite || post('must_change')) ? 1 : 0]);
        save_user_branches($nid, $branches, (int)post('primary_branch'));
        $staffNo = assign_staff_no($nid);
        audit('User created', 'Users', 'user', $nid, null, $staffNo . ' ' . $un . ' (' . post('role') . ')');
        return $nid;
    });
    $note = '';
    if ($invite) {
        try { [$sent, $link] = PasswordReset::send($nid, 'invite', uid()); $note = $sent ? ' An invitation email was sent.' : ' Email is not configured, so no email was sent' . (!is_production() ? ' (the link is in storage/logs/mail.log).' : '.'); }
        catch (AppError $e) { $note = ' The invitation email could not be sent: ' . $e->getMessage(); }
    }
    return ['User created.' . $note, url('users', ['a' => 'edit', 'id' => $nid])];
});
if ($a === 'update') post_action(function () use ($id, $roles) {
    require_perm('user.manage');
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    $role = post('role'); $status = post('status');
    $un = strtolower(trim(post('username'))); $sn = strtoupper(trim(post('staff_no')));
    must(preg_match('/^[a-z0-9_.-]{3,30}$/', $un) === 1, 'Username must be 3-30 lowercase letters, numbers, dots, dashes or underscores.');
    must(!val('SELECT COUNT(*) FROM users WHERE lower(username) = ? AND id <> ?', [$un, $id]), 'That username is already taken.');
    must(preg_match('/^[A-Z0-9-]{3,24}$/', $sn) === 1, 'Staff ID must be 3-24 letters, numbers or dashes.');
    must(!val('SELECT COUNT(*) FROM users WHERE staff_no = ? AND id <> ?', [$sn, $id]), 'That Staff ID belongs to someone else.');
    $em = clean(post('email'));
    must($em === '' || filter_var($em, FILTER_VALIDATE_EMAIL) !== false, 'Enter a valid email address.');
    must(in_array($role, $roles, true) && in_array($status, ['active', 'inactive'], true), 'Invalid role or status.');
    if ($id === uid()) must($status === 'active' && $role === $u['role'], 'You cannot deactivate or change the role of your own account.');
    if ($u['role'] === 'System Administrator' && ($role !== 'System Administrator' || $status !== 'active')) must(active_admins() > 1, 'At least one active System Administrator is required.');
    // the new role plus this user's overrides must stay inside the security boundaries
    $rolePerms = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission');
    $eff = array_flip($rolePerms);
    foreach (rows('SELECT permission, granted FROM user_permissions WHERE user_id = ?', [$id]) as $o) { if ($o['granted']) $eff[$o['permission']] = true; else unset($eff[$o['permission']]); }
    assert_permission_boundaries($role, array_keys($eff));
    $branches = (array)($_POST['branches'] ?? []);
    tx(function () use ($id, $u, $role, $status, $branches, $un, $sn, $em) {
        update('users', ['full_name' => clean(post('full_name')), 'username' => $un, 'staff_no' => $sn, 'phone' => clean(post('phone')) ?: null, 'email' => $em ?: null, 'role' => $role, 'status' => $status], 'id = :id', ['id' => $id]);
        $before = array_column(rows('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id', [$id]), 'branch_id');
        save_user_branches($id, $branches, (int)post('primary_branch'));
        $after = array_column(rows('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id', [$id]), 'branch_id');
        audit('User updated', 'Users', 'user', $id, ['username' => $u['username'], 'staff_no' => $u['staff_no'], 'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status'], 'branches' => $before], ['username' => $un, 'staff_no' => $sn, 'email' => $em, 'role' => $role, 'status' => $status, 'branches' => $after]);
    });
    return 'User updated.';
});
if ($a === 'setstatus') post_action(function () use ($id) {
    require_perm('user.manage');
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    $to = post('to') === 'active' ? 'active' : 'inactive';
    must($id !== uid(), 'You cannot deactivate your own account.');
    if ($to === 'inactive' && $u['role'] === 'System Administrator') must(active_admins() > 1, 'At least one active System Administrator is required.');
    if ($u['status'] !== $to) {
        update('users', ['status' => $to], 'id = :id', ['id' => $id]);
        audit($to === 'active' ? 'User activated' : 'User deactivated', 'Users', 'user', $id, $u['status'], $to, clean(post('reason')));
    }
    return ($u['full_name']) . ($to === 'active' ? ' can sign in again.' : ' has been deactivated and can no longer sign in.');
});
if ($a === 'reset') post_action(function () use ($id) {
    require_perm('user.manage');
    [$sent, $link] = PasswordReset::send($id, 'reset', uid());
    if ($sent) return 'A password reset link was emailed to the user. It works once and expires in 2 hours.';
    return 'Email is not configured (Settings > Email), so nothing was sent.' . (!is_production() ? ' Local testing: the message is in storage/logs/mail.log.' : '');
});
if ($a === 'reports') post_action(function () use ($id) {
    require_perm('user.manage');
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    $allowed = array_keys((array)($_POST['rep'] ?? []));
    $all = array_keys(Reports::catalogue());
    tx(function () use ($id, $allowed, $all) {
        $before = array_column(rows('SELECT report_key FROM user_report_access WHERE user_id = ? AND allowed = 0', [$id]), 'report_key');
        q('DELETE FROM user_report_access WHERE user_id = ?', [$id]);
        $denied = [];
        foreach ($all as $k) if (!in_array($k, $allowed, true)) { q('INSERT INTO user_report_access(user_id, report_key, allowed) VALUES (?,?,0)', [$id, $k]); $denied[] = $k; }
        audit('User report access changed', 'Users', 'user', $id, ['blocked' => $before], ['blocked' => $denied], clean(post('reason')));
    });
    return 'Report access saved.';
});
if ($a === 'resetmfa') post_action(function () use ($id) {
    require_perm('user.manage');
    must($id !== uid(), 'Use your own account page to manage your MFA.');
    must(clean(post('reason')) !== '', 'A reason is required to reset someone\'s multi-factor authentication.');
    update('users', ['mfa_enabled' => 0, 'mfa_secret' => null, 'mfa_recovery' => null], 'id = :id', ['id' => $id]);
    audit('MFA reset by administrator', 'Users', 'user', $id, 'enabled', 'reset', clean(post('reason')));
    return 'MFA reset. The user must enrol again at next sign-in (if required for their role).';
});
if ($a === 'perms') post_action(function () use ($id) {
    require_perm('user.manage');
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    must($id !== uid(), 'You cannot change your own permissions.');
    $rolePerms = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$u['role']]), 'permission');
    $want = array_values(array_intersect(array_keys((array)($_POST['perm'] ?? [])), all_permissions()));
    assert_permission_boundaries($u['role'], $want);
    tx(function () use ($id, $rolePerms, $want) {
        $before = user_permissions($id);
        q('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
        foreach (all_permissions() as $p) {
            $has = in_array($p, $want, true); $in = in_array($p, $rolePerms, true);
            if ($has !== $in) q('INSERT INTO user_permissions(user_id, permission, granted) VALUES (?,?,?)', [$id, $p, $has ? 1 : 0]);
        }
        audit('User permissions changed', 'Users', 'user', $id, ['granted' => array_values(array_diff($want, $before)), 'revoked' => array_values(array_diff($before, $want))], null, clean(post('reason')));
    });
    return 'Individual permissions saved.';
});
if ($a === 'roleperms') post_action(function () {
    require_perm('user.manage');
    $role = post('role');
    must((bool)val('SELECT COUNT(*) FROM roles WHERE role = ?', [$role]), 'Unknown role.');
    $want = array_values(array_intersect(array_keys((array)($_POST['perm'] ?? [])), all_permissions()));
    assert_permission_boundaries($role, $want);
    if ($role === 'System Administrator') must(in_array('user.manage', $want, true) && in_array('backup.manage', $want, true), 'The System Administrator role must keep user management and backup rights.');
    tx(function () use ($role, $want) {
        $before = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission');
        q('DELETE FROM role_permissions WHERE role = ?', [$role]);
        foreach ($want as $p) q('INSERT INTO role_permissions(role, permission) VALUES (?,?)', [$role, $p]);
        audit('Role permissions changed', 'Users', 'role', $role, null, ['granted' => array_values(array_diff($want, $before)), 'revoked' => array_values(array_diff($before, $want))], clean(post('reason')));
    });
    return ['Permissions for ' . $role . ' saved.', url('users', ['tab' => 'roles', 'role' => $role])];
});

page_head('Users & access', $tab === 'users' && $a !== 'new' && $a !== 'edit' ? btn('New user', url('users', ['a' => 'new']), 'primary') : '', 'The System Administrator manages accounts and access, but cannot approve loans, post money or approve End-of-Day.');
tabs(['users' => 'Users', 'roles' => 'Role permissions', 'matrix' => 'Access matrix'], $tab);

function perm_grid(array $checked, array $roleDefault = [], bool $disabled = false): void
{
    foreach (permission_catalogue() as $group => $perms) {
        echo '<h3>' . e($group) . '</h3><div class="perm-grid">';
        foreach ($perms as $k => $label) {
            $isOv = $roleDefault && (in_array($k, $checked, true) !== in_array($k, $roleDefault, true));
            $tech = in_array($k, technical_permissions(), true) ? ' <span class="badge b-info">technical</span>' : (in_array($k, financial_authority_permissions(), true) ? ' <span class="badge b-warn">financial</span>' : '');
            echo '<label><input type="checkbox" name="perm[' . e($k) . ']" value="1"' . (in_array($k, $checked, true) ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> ' . e($label) . $tech . ($isOv ? ' <span class="badge b-warn">override</span>' : '') . '</label>';
        }
        echo '</div>';
    }
}

if ($tab === 'users' && $a === 'new') {
    echo '<div class="card"><h2>New user</h2>';
    form_open(url('users', ['a' => 'create']));
    field('full_name', 'Full name', ['required' => true, 'span' => 2]); field('username', 'Username (lowercase)', ['required' => true]); field('phone', 'Phone', ['type' => 'tel']);
    field('email', 'Email', ['type' => 'email', 'span' => 2]);
    field('role', 'Role', ['type' => 'select', 'options' => array_combine($roles, $roles)]);
    field('password', 'Temporary password (optional)', ['type' => 'password', 'help' => 'Leave blank and enter an email address: the user receives a link to choose their own password.', 'attrs' => 'autocomplete="new-password"']);
    echo '<h3 class="full">Branch access</h3><div class="full" style="grid-column:1/-1"><div class="perm-grid">';
    foreach (all_branch_options() as $bid => $bn) echo '<label><input type="checkbox" name="branches[]" value="' . (int)$bid . '"> ' . e($bn) . '</label>';
    echo '</div><small class="muted">The COO sees all branches automatically; the System Administrator needs none. Everyone else sees only the branches ticked here.</small></div>';
    field('primary_branch', 'Primary branch', ['type' => 'select', 'options' => all_branch_options(), 'blank' => '—']);
    field('must_change', 'Require password change at first sign-in', ['type' => 'checkbox', 'value' => '1', 'span' => 2]);
    echo '<div class="form-actions">'; submit_btn('Create user'); echo '</div>'; form_close(); echo '</div>';
} elseif ($tab === 'users' && $a === 'edit') {
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    must((bool)$u, 'User not found.');
    $mine = array_map('intval', array_column(rows('SELECT branch_id FROM user_branches WHERE user_id = ?', [$id]), 'branch_id'));
    $prim = (int)val('SELECT branch_id FROM user_branches WHERE user_id = ? AND is_primary = 1', [$id]);
    echo '<div class="grid g2"><div class="card"><h2>' . e($u['full_name']) . ' <span class="muted">@' . e($u['username']) . '</span></h2>';
    form_open(url('users', ['a' => 'update', 'id' => $id]), ['attrs' => 'style="grid-template-columns:1fr 1fr"']);
    field('full_name', 'Full name', ['value' => $u['full_name'], 'required' => true]); field('phone', 'Phone', ['value' => $u['phone']]);
    field('username', 'Username', ['value' => $u['username'], 'required' => true]); field('staff_no', 'Staff ID', ['value' => $u['staff_no'], 'required' => true, 'help' => 'Unique. Can be used to sign in.']);
    field('email', 'Email (used for password reset)', ['value' => $u['email'], 'span' => 2]);
    field('role', 'Role', ['type' => 'select', 'options' => array_combine($roles, $roles), 'value' => $u['role']]);
    field('status', 'Account status', ['type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'value' => $u['status']]);
    echo '<h3 class="full">Branch access</h3><div class="full" style="grid-column:1/-1"><div class="perm-grid">';
    foreach (all_branch_options(false) as $bid => $bn) echo '<label><input type="checkbox" name="branches[]" value="' . (int)$bid . '"' . (in_array((int)$bid, $mine, true) ? ' checked' : '') . '> ' . e($bn) . '</label>';
    echo '</div></div>';
    field('primary_branch', 'Primary branch', ['type' => 'select', 'options' => all_branch_options(false), 'blank' => '—', 'value' => $prim ?: '', 'span' => 2]);
    echo '<div class="form-actions">'; submit_btn('Save'); echo btn('Back', url('users')); echo '</div>'; form_close();
    desc_list(['Created' => e(fmt_dt($u['created_at'])), 'Last login' => e(fmt_dt($u['last_login'])), 'Last IP' => e($u['last_ip']), 'Password changed' => e(fmt_dt($u['password_changed_at'])),
        'Failed attempts' => e($u['failed_attempts']), 'MFA' => $u['mfa_enabled'] ? badge('Active') : (mfa_required_for($u) ? badge('Pending') . ' required' : 'off')], 2);
    echo '</div><div class="card"><h2>Password</h2><p class="muted">Password resets are done by email: the user receives a one-time link (valid 2 hours) and chooses their own password. Nobody else ever sees it.</p>';
    if (empty($u['email'])) echo '<div class="notice">Add an email address for this user (and save) to enable password reset.</div>';
    elseif (!mail_configured()) echo '<div class="notice info">Outgoing email is not configured yet (Settings &rarr; Email). Messages are written to a log file until it is.</div>';
    echo '<p><b>' . e($u['email'] ?: 'no email on file') . '</b></p>';
    form_open(url('users', ['a' => 'reset', 'id' => $id]), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Email a password reset link to this user?"']);
    echo '<div class="form-actions">'; submit_btn('Email password reset link', empty($u['email']) ? 'default' : 'primary'); echo '</div>'; form_close();
    if ($u['mfa_enabled'] && $id !== uid()) {
        echo '<h3>Reset multi-factor authentication</h3><p class="muted">For a lost phone. The user enrols again at next sign-in. Audited.</p>';
        form_open(url('users', ['a' => 'resetmfa', 'id' => $id]), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Reset this user\'s MFA?"']);
        field('reason', 'Reason (identity verified how?)', ['required' => true]);
        echo '<div class="form-actions">'; submit_btn('Reset MFA', 'danger'); echo '</div>'; form_close();
    }
    echo '</div></div>';
    if ($id !== uid()) {
        echo '<div class="card"><h2>Individual permissions</h2><p class="muted">Starts from the <b>' . e($u['role']) . '</b> role. Changes must stay inside the security boundaries: administrators never receive financial authority; technical rights stay with the System Administrator role.</p>';
        $rolePerms = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$u['role']]), 'permission');
        form_open(url('users', ['a' => 'perms', 'id' => $id]));
        echo '<div class="full" style="grid-column:1/-1">'; perm_grid(user_permissions($id), $rolePerms); echo '</div>';
        field('reason', 'Reason for the change (audited)', ['span' => 4, 'required' => true]);
        echo '<div class="form-actions">'; submit_btn('Save permissions'); echo '</div>'; form_close(); echo '</div>';
    }
    if ($id !== uid()) {
        $denied = report_denied_keys($id);
        echo '<div class="card"><h2>Reports this user can open</h2>';
        if (!in_array('report.view', user_permissions($id), true)) echo '<div class="notice">This user does not have the View reports permission, so no report is available regardless of the ticks below.</div>';
        echo '<p class="muted">Untick a report to hide it from this person. Reports they may open still show only their own branches.</p>';
        form_open(url('users', ['a' => 'reports', 'id' => $id]));
        $groups = [];
        foreach (Reports::catalogue() as $k => [$g, $t]) $groups[$g][$k] = $t;
        echo '<div class="full" style="grid-column:1/-1">';
        foreach ($groups as $g => $items) { echo '<h3>' . e($g) . '</h3><div class="perm-grid">'; foreach ($items as $k => $t) echo '<label><input type="checkbox" name="rep[' . e($k) . ']" value="1"' . (in_array($k, $denied, true) ? '' : ' checked') . '> ' . e($t) . '</label>'; echo '</div>'; }
        echo '</div>';
        field('reason', 'Reason for the change (audited)', ['span' => 4]);
        echo '<div class="form-actions">'; submit_btn('Save report access'); echo '</div>'; form_close(); echo '</div>';
    }
} elseif ($tab === 'users') {
    $us = rows('SELECT * FROM users ORDER BY status, full_name');
    $ub = [];
    foreach (rows('SELECT ub.user_id, b.name FROM user_branches ub JOIN branches b ON b.id = ub.branch_id ORDER BY b.name') as $r) $ub[$r['user_id']][] = $r['name'];
    foreach ($us as &$r) $r['branches'] = $r['role'] === 'COO' ? 'All (COO)' : implode(', ', $ub[$r['id']] ?? []);
    unset($r);
    data_table(['staff_no' => 'Staff ID', 'full_name' => 'Name', 'username' => 'Username', 'role' => 'Role', 'branches' => 'Branches', 'phone' => 'Phone', 'mfa_enabled' => ['MFA', fn($r) => $r['mfa_enabled'] ? badge('Active') : (mfa_required_for($r) ? badge('Pending') : '<span class="muted">off</span>')],
        'status' => ['Status', 'badge'], 'last_login' => ['Last login', 'dt'],
        'x' => ['Actions', function ($r) {
            $o = '<div class="row-actions"><a class="btn sm" href="' . e(url('users', ['a' => 'edit', 'id' => $r['id']])) . '">' . icon('settings', 13) . ' Edit</a>';
            if ((int)$r['id'] !== uid()) {
                $act = $r['status'] === 'active';
                $o .= '<form method="post" action="' . e(url('users', ['a' => 'setstatus', 'id' => $r['id']])) . '" data-confirm="' . e(($act ? 'Deactivate ' : 'Activate ') . $r['full_name'] . '?') . '">' . csrf_field()
                    . '<input type="hidden" name="to" value="' . ($act ? 'inactive' : 'active') . '"><button class="btn sm ' . ($act ? 'danger' : 'primary') . '">' . ($act ? 'Deactivate' : 'Activate') . '</button></form>';
            }
            return $o . '</div>';
        }]], $us, ['class' => 'users-tbl', 'link' => fn($r) => url('users', ['a' => 'edit', 'id' => $r['id']])]);
}

if ($tab === 'roles') {
    $role = get('role', 'Branch Manager');
    echo '<div class="card"><div class="card-h"><h2>Role permissions</h2><div class="actions">';
    foreach ($roles as $r) echo '<a class="btn sm ' . ($r === $role ? 'primary' : '') . '" href="' . e(url('users', ['tab' => 'roles', 'role' => $r])) . '">' . e($r) . '</a>';
    echo '</div></div><p class="muted">' . e(role_descriptions()[$role] ?? '') . '</p>';
    $cur = array_column(rows('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission');
    form_open(url('users', ['a' => 'roleperms', 'tab' => 'roles']));
    field('role', '', ['type' => 'hidden', 'value' => $role]);
    echo '<div class="full" style="grid-column:1/-1">'; perm_grid($cur, default_role_permissions()[$role] ?? []); echo '</div>';
    field('reason', 'Reason for the change (audited)', ['span' => 4, 'required' => true]);
    echo '<div class="form-actions">'; submit_btn('Save role permissions'); echo '</div>';
    form_close(); echo '</div>';
}

if ($tab === 'matrix') {
    $perRole = [];
    foreach (rows('SELECT role, permission FROM role_permissions') as $r) $perRole[$r['role']][$r['permission']] = true;
    $pr = principal_roles();
    echo '<div class="card"><h2>Who can do what</h2><p class="muted">Current permissions per role. Roles never see records outside their assigned branches (the COO sees all); separation-of-duties checks apply on top of this matrix.</p><div class="tbl-wrap"><table class="tbl matrix nofilter"><thead><tr><th>Permission</th>';
    foreach (array_merge($pr, ['Cashier']) as $r) echo '<th>' . e($r) . '</th>';
    echo '</tr></thead><tbody>';
    foreach (permission_catalogue() as $group => $perms) {
        echo '<tr><td colspan="' . (count($pr) + 2) . '" style="background:var(--bg);font-weight:700">' . e($group) . '</td></tr>';
        foreach ($perms as $k => $label) {
            echo '<tr><td>' . e($label) . '</td>';
            foreach (array_merge($pr, ['Cashier']) as $r) echo '<td>' . (!empty($perRole[$r][$k]) ? '<span class="ico-ok">●</span>' : '<span class="muted">–</span>') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div>';
}
