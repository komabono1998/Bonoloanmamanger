<?php
/** First-run installation: schema, roles, permissions, default settings, first branch and the System Administrator. */

function is_installed(): bool
{
    try {
        return (int)val('SELECT COUNT(*) FROM users') > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function seed_reference_data(): void
{
    $i = 0;
    foreach (role_descriptions() as $role => $desc) {
        q('INSERT INTO roles(role, description, sort_order, is_principal) VALUES (?,?,?,?) ON CONFLICT (role) DO NOTHING', [$role, $desc, ++$i, in_array($role, principal_roles(), true) ? 1 : 0]);
    }
    foreach (default_role_permissions() as $role => $perms) {
        foreach ($perms as $p) q('INSERT INTO role_permissions(role, permission) VALUES (?,?) ON CONFLICT (role, permission) DO NOTHING', [$role, $p]);
    }
    foreach (setting_defaults() as $k => $v) {
        q('INSERT INTO settings(key, value, updated_at) VALUES (?,?,?) ON CONFLICT (key) DO NOTHING', [$k, $v, now()]);
    }
    foreach (['Rent', 'Transport', 'Utilities', 'Stationery', 'Salaries', 'Communication', 'Banking Charges', 'Other Approved Expenses'] as $c) {
        q('INSERT INTO expense_categories(name) VALUES (?) ON CONFLICT (name) DO NOTHING', [$c]);
    }
}

function create_branch(string $code, string $name, string $location = '', string $phone = ''): int
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    must($code !== '' && $name !== '', 'Branch code and name are required.');
    $id = insert('branches', ['code' => $code, 'name' => $name, 'location' => $location ?: null, 'phone' => $phone ?: null, 'status' => 'active', 'created_at' => now()]);
    seed_branch_accounts($id);
    branches_changed();
    return $id;
}

function install_system(string $businessName, string $fullName, string $username, string $password, string $branchName = 'Head Office', string $branchCode = 'HQ'): int
{
    must(!is_installed(), 'The system is already installed.');
    password_policy_check($password);
    must(preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username) === 1, 'Username must be 3-30 letters, numbers, dots, dashes or underscores.');
    must($fullName !== '', 'Full name is required.');
    migrate();
    return tx(function () use ($businessName, $fullName, $username, $password, $branchName, $branchCode) {
        seed_reference_data();
        if ($businessName !== '') set_setting('business_name', $businessName, null, false);
        $bid = create_branch($branchCode, $branchName ?: 'Head Office');
        $id = insert('users', [
            'full_name' => $fullName, 'username' => strtolower($username), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'System Administrator', 'status' => 'active', 'created_at' => now(), 'password_changed_at' => now(),
        ]);
        audit('System installed', 'Administration', 'user', $id, null, $username, '', '', $id, $username, $bid);
        return $id;
    });
}
