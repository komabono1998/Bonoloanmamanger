<?php
/**
 * Organisations (tenants). Every organisation has its OWN database, file storage and backups, so no query can ever reach another
 * organisation's data. The database configured in the environment is the "primary" one: it holds the original organisation and the
 * registry of the others (table `tenants`). A request is bound to one organisation by its code (cookie / form field) before any
 * data is read, and a signed-in session is only valid for the organisation it was created in.
 */

/** Current organisation row, or null for the primary one. */
function tenant(): ?array { return $GLOBALS['__tenant'] ?? null; }

function tenant_slug(): string { return tenant()['slug'] ?? ''; }

/** Switch the data layer to an organisation (null = the primary one). */
function tenant_use(?array $t): void
{
    $GLOBALS['__tenant'] = $t;
    $GLOBALS['__settings_dirty'] = true;
    if (function_exists('branches_changed')) branches_changed();
    if (function_exists('reset_user_caches')) reset_user_caches();
    $GLOBALS['__user'] = null;
}

/** Run something against the primary database (registry, shared sessions) without disturbing the current organisation. */
function in_primary(callable $fn)
{
    $keep = $GLOBALS['__tenant'] ?? null;
    $GLOBALS['__tenant'] = null;
    try { return $fn(); } finally { $GLOBALS['__tenant'] = $keep; }
}

function tenant_valid_slug(string $s): bool { return preg_match('/^[a-z0-9][a-z0-9-]{2,23}$/', $s) === 1; }

/** Subfolder for per-organisation files (uploads, backups). */
function tenant_dir_suffix(): string { return tenant() ? '/t_' . tenant()['slug'] : ''; }

final class Tenancy
{
    private const RESERVED = ['default', 'admin', 'api', 'www', 'app', 'login', 'register', 'setup', 'support', 'system', 'bono', 'root', 'test'];

    public static function enabled(): bool
    {
        $v = env('SELF_REGISTRATION');
        return $v === null || $v === '' ? !is_production() : $v === '1';
    }

    private static function inPrimary(callable $fn) { return in_primary($fn); }

    public static function find(string $slug): ?array
    {
        if (!tenant_valid_slug($slug)) return null;
        return self::inPrimary(function () use ($slug) {
            try { return row("SELECT * FROM tenants WHERE slug = ? AND status = 'active'", [$slug]) ?: null; } catch (Throwable $e) { return null; }
        });
    }

    public static function all(): array
    {
        return self::inPrimary(function () { try { return rows('SELECT * FROM tenants ORDER BY id'); } catch (Throwable $e) { return []; } });
    }

    /** Is the primary organisation installed (so that new organisations are separate workspaces)? */
    public static function primaryInstalled(): bool
    {
        return self::inPrimary(fn() => is_installed());
    }

    public static function slugFromName(string $name): string
    {
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return substr($s, 0, 24);
    }

    /** Create a new organisation: its own database, its first branch and its System Administrator. Returns the slug. */
    public static function create(string $name, string $slug, string $adminName, string $username, string $email, string $password, string $branchName = 'Head Office', string $branchCode = 'HQ', array $profile = []): string
    {
        must(self::primaryInstalled(), 'Set up the first organisation before adding others.');
        $name = clean($name); $slug = strtolower(trim($slug));
        must($name !== '', 'Organisation name is required.');
        must(tenant_valid_slug($slug), 'The organisation code must be 3-24 lowercase letters, numbers or dashes, starting with a letter or number.');
        must(!in_array($slug, self::RESERVED, true), 'That organisation code is reserved. Choose another.');
        must($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Enter a valid email address.');
        password_policy_check($password);
        $ip = client_ip();
        self::inPrimary(function () use ($slug, $ip) {
            must(!val('SELECT COUNT(*) FROM tenants WHERE slug = ?', [$slug]), 'That organisation code is already taken.');
            must((int)val('SELECT COUNT(*) FROM tenants WHERE created_ip = ? AND created_at > ?', [$ip, date('Y-m-d H:i:s', time() - 3600)]) < 3, 'Too many organisations were created from this address recently. Try again later.');
        });
        $row = ['slug' => $slug, 'name' => $name, 'driver' => db_driver(), 'status' => 'active', 'created_at' => now(), 'created_ip' => $ip];
        if (db_is_pg()) {
            $row['db_name'] = 'bono_t_' . str_replace('-', '_', $slug);
            self::createPgDatabase($row['db_name']);
        } else {
            $dir = dirname(primary_db_path()) . '/tenants';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $row['db_name'] = 'tenants/' . $slug . '.sqlite';
            must(!file_exists(dirname(primary_db_path()) . '/' . $row['db_name']), 'That organisation code is already taken.');
        }
        $id = self::inPrimary(fn() => insert('tenants', $row));
        $t = $row + ['id' => $id];
        $keep = tenant();
        try {
            tenant_use($t);
            migrate();
            $_SESSION = $_SESSION ?? [];
            $uid = install_system($name, $adminName, $username, $password, $branchName ?: 'Head Office', $branchCode ?: 'HQ', $profile);
            if ($email !== '') q('UPDATE users SET email = ? WHERE id = ?', [$email, $uid]);
        } catch (Throwable $e) {
            tenant_use($keep);
            self::inPrimary(fn() => q("UPDATE tenants SET status = 'failed' WHERE id = ?", [$id]));
            throw $e;
        }
        tenant_use($keep);
        return $slug;
    }

    private static function createPgDatabase(string $db): void
    {
        must(preg_match('/^[a-z0-9_]{3,60}$/', $db) === 1, 'Invalid database name.');
        $dsn = 'pgsql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_ADMIN_DB', 'postgres') . ';sslmode=' . env('DB_SSLMODE', is_production() ? 'require' : 'prefer');
        try {
            $pdo = new PDO($dsn, (string)env('DB_USER', 'postgres'), (string)env('DB_PASSWORD', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE "' . $db . '"');
        } catch (PDOException $e) {
            error_log($e->getMessage());
            throw new AppError('The database server refused to create a workspace. The database user needs the CREATEDB privilege (or choose another organisation code if this one exists).');
        }
    }
}

/**
 * Bind this web request to an organisation before any data is read. The organisation comes from the "org" form field / query
 * parameter (login and register pages) or the remembered cookie. Unknown codes fall back to the primary organisation.
 */
function tenant_boot(): void
{
    if (PHP_SAPI === 'cli') return;
    $given = strtolower(trim((string)($_POST['org'] ?? $_GET['org'] ?? '')));
    $explicit = isset($_POST['org']) || isset($_GET['org']);
    $slug = $explicit ? $given : strtolower((string)($_COOKIE['bono_org'] ?? ''));
    if ($slug === '' || $slug === 'default') {
        if ($explicit && isset($_COOKIE['bono_org'])) setcookie('bono_org', '', ['expires' => time() - 3600, 'path' => '/']);
        return;
    }
    $t = Tenancy::find($slug);
    if (!$t) { $GLOBALS['__tenant_unknown'] = $slug; return; }
    tenant_use($t);
    if ($explicit) setcookie('bono_org', $slug, ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => request_is_https()]);
}
