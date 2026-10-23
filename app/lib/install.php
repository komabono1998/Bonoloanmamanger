<?php
/** First-run installation: schema, roles, permissions, default settings and reference data. */

function is_installed(): bool
{
    try {
        return (int)val("SELECT COUNT(*) FROM sqlite_master WHERE name = 'users'") > 0
            && (int)val('SELECT COUNT(*) FROM users') > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function seed_reference_data(): void
{
    foreach (role_descriptions() as $role => $desc) {
        q('INSERT OR IGNORE INTO roles(role, description) VALUES (?,?)', [$role, $desc]);
    }
    foreach (default_role_permissions() as $role => $perms) {
        foreach ($perms as $p) q('INSERT OR IGNORE INTO role_permissions(role, permission) VALUES (?,?)', [$role, $p]);
    }
    foreach (setting_defaults() as $k => $v) {
        q('INSERT OR IGNORE INTO settings(key, value, updated_at) VALUES (?,?,?)', [$k, $v, now()]);
    }
    foreach ([['Main Safe', 'safe'], ['Bank Account', 'bank'], ['Mobile Money', 'mobile']] as [$n, $t]) {
        q('INSERT OR IGNORE INTO cash_accounts(name, type) VALUES (?,?)', [$n, $t]);
    }
    foreach (['Rent', 'Transport', 'Utilities', 'Stationery', 'Salaries', 'Communication', 'Banking Charges', 'Other Approved Expenses'] as $c) {
        q('INSERT OR IGNORE INTO expense_categories(name) VALUES (?)', [$c]);
    }
}

function install_system(string $businessName, string $fullName, string $username, string $password): int
{
    must(!is_installed(), 'The system is already installed.');
    password_policy_check($password);
    must(preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username) === 1, 'Username must be 3-30 letters, numbers, dots, dashes or underscores.');
    must($fullName !== '', 'Full name is required.');
    migrate();
    return tx(function () use ($businessName, $fullName, $username, $password) {
        seed_reference_data();
        if ($businessName !== '') set_setting('business_name', $businessName, null, false);
        $id = insert('users', [
            'full_name' => $fullName, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'Administrator', 'status' => 'active', 'created_at' => now(), 'password_changed_at' => now(),
        ]);
        audit('System installed', 'Administration', 'user', $id, null, $username, '', '', $id, $username);
        return $id;
    });
}
