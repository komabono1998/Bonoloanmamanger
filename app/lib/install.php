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

/** Institution code used in staff IDs, e.g. EMG. Set once from the institution name; editable in the profile. */
function institution_code(): string
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', setting('institution_code')));
    return $c !== '' ? $c : institution_code_from(business_name());
}

/** Give a user the next unique staff ID, e.g. EMG-S-0001. IDs are never reused. */
function assign_staff_no(int $userId): string
{
    $no = institution_code() . '-' . setting('staff_prefix') . '-' . str_pad((string)next_seq('staff'), 4, '0', STR_PAD_LEFT);
    q('UPDATE users SET staff_no = ? WHERE id = ? AND staff_no IS NULL', [$no, $userId]);
    return (string)val('SELECT staff_no FROM users WHERE id = ?', [$userId]);
}

function backfill_staff_numbers(): void
{
    foreach (rows('SELECT id FROM users WHERE staff_no IS NULL ORDER BY id') as $u) assign_staff_no((int)$u['id']);
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

function install_system(string $businessName, string $fullName, string $username, string $password, string $branchName = 'Head Office', string $branchCode = 'HQ', array $profile = []): int
{
    must(!is_installed(), 'The system is already installed.');
    password_policy_check($password);
    must(preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username) === 1, 'Username must be 3-30 letters, numbers, dots, dashes or underscores.');
    must($fullName !== '', 'Full name is required.');
    migrate();
    return tx(function () use ($businessName, $fullName, $username, $password, $branchName, $branchCode, $profile) {
        seed_reference_data();
        if ($businessName !== '') set_setting('business_name', $businessName, null, false);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($profile['institution_code'] ?? '')));
        must($code === '' || (strlen($code) >= 2 && strlen($code) <= 6), 'Institution code must be 2-6 letters or numbers.');
        set_setting('institution_code', $code ?: institution_code_from($businessName ?: 'Bono'), null, false);
        foreach (['business_address', 'business_city', 'business_phone', 'business_email', 'registration_no', 'tax_id'] as $k) if (!empty($profile[$k])) set_setting($k, trim((string)$profile[$k]), null, false);
        foreach (['interest_rate_pct', 'processing_fee_pct'] as $k) if (isset($profile[$k]) && $profile[$k] !== '') {
            must(is_numeric($profile[$k]) && (float)$profile[$k] >= 0 && (float)$profile[$k] <= 1000, 'Rates must be percentages.');
            set_setting($k, (string)$profile[$k], null, false);
        }
        if (!empty($profile['interest_rate_pct'])) set_setting('setup_lending_confirmed', '1', null, false);
        $bid = create_branch($branchCode, $branchName ?: 'Head Office');
        $id = insert('users', [
            'full_name' => $fullName, 'username' => strtolower($username), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'System Administrator', 'status' => 'active', 'created_at' => now(), 'password_changed_at' => now(),
        ]);
        assign_staff_no($id);
        audit('System installed', 'Administration', 'user', $id, null, $username, '', '', $id, $username, $bid);
        return $id;
    });
}

/** Make sure every built-in role exists (roles added in later versions are created with their default permissions; existing roles are never altered). */
function ensure_default_roles(): void
{
    $have = array_column(rows('SELECT role FROM roles'), 'role');
    if (!$have) return;     // fresh system: seed_reference_data() does this
    $i = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM roles');
    foreach (role_descriptions() as $role => $desc) {
        if (in_array($role, $have, true)) continue;
        q('INSERT INTO roles(role, description, sort_order, is_principal) VALUES (?,?,?,?) ON CONFLICT (role) DO NOTHING', [$role, $desc, ++$i, in_array($role, principal_roles(), true) ? 1 : 0]);
        foreach (default_role_permissions()[$role] ?? [] as $p) q('INSERT INTO role_permissions(role, permission) VALUES (?,?) ON CONFLICT (role, permission) DO NOTHING', [$role, $p]);
    }
    // role templates that gained access to the Report Centre (applied once to existing systems)
    foreach ([['Cashier', 'report.view'], ['System Administrator', 'report.view'], ['System Administrator', 'eod.run'],
        ['Finance Manager', 'gl.view'], ['Finance Manager', 'gl.post'], ['Finance Manager', 'gl.manage'], ['Finance Officer', 'gl.view'], ['COO', 'gl.view'], ['Auditor', 'gl.view']] as [$r, $perm]) {
        $mk = str_replace('.', '_', $perm) . '_' . strtolower(str_replace(' ', '_', $r));
        if (in_array($r, $have, true) && !val('SELECT COUNT(*) FROM schema_migrations WHERE name = ?', [$mk])) {
            q('INSERT INTO role_permissions(role, permission) VALUES (?, ?) ON CONFLICT (role, permission) DO NOTHING', [$r, $perm]);
            q('INSERT INTO schema_migrations(name, applied_at) VALUES (?, ?) ON CONFLICT (name) DO NOTHING', [$mk, date('Y-m-d H:i:s')]);
        }
    }
}
