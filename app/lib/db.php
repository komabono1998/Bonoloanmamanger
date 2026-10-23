<?php
/**
 * Data access layer. One thin PDO wrapper that runs on SQLite (local / evaluation) and PostgreSQL (cloud).
 * SQL elsewhere in the app is written to be portable; the few differences are handled here.
 */

class AppError extends Exception {}

/** Tables whose primary key is not a single `id` column (so INSERT must not use RETURNING id). */
const NO_ID_TABLES = ['business_days', 'settings', 'sequences', 'roles', 'role_permissions', 'user_permissions', 'business_calendar', 'user_branches'];

function db_driver(): string
{
    if (defined('DB_FILE')) return 'sqlite';
    return strtolower((string)env('DB_DRIVER', 'sqlite')) === 'pgsql' ? 'pgsql' : 'sqlite';
}

function db_is_pg(): bool { return db_driver() === 'pgsql'; }

function db_path(): string
{
    if (defined('DB_FILE')) return DB_FILE;
    return env('SQLITE_PATH') ?: BASE_PATH . '/storage/db/bono.sqlite';
}

function &db_holder(): ?PDO
{
    static $pdo = null;
    return $pdo;
}

function db(): PDO
{
    $pdo = &db_holder();
    if ($pdo !== null) return $pdo;
    if (db_is_pg()) {
        $dsn = 'pgsql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_NAME', 'bono')
            . ';sslmode=' . env('DB_SSLMODE', is_production() ? 'require' : 'prefer') . ';application_name=bono-loan-manager';
        $pdo = new PDO($dsn, (string)env('DB_USER', 'postgres'), (string)env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $schema = env('DB_SCHEMA');
        if ($schema) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) throw new RuntimeException('Invalid DB_SCHEMA.');
            $pdo->exec('SET search_path TO ' . $schema);
        }
        $pdo->exec("SET TIME ZONE 'Africa/Kampala'");
        $pdo->exec('SET statement_timeout TO 30000');
    } else {
        $dir = dirname(db_path());
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . db_path());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    }
    return $pdo;
}

/** Drop the connection (used before restoring a backup). */
function db_close(): void
{
    $pdo = &db_holder();
    $pdo = null;
}

/** Engine-specific rewrite of the portable SQL we write. */
function adapt_sql(string $sql): string
{
    if (!db_is_pg()) return $sql;
    return preg_replace('/\bLIKE\b/i', 'ILIKE', $sql);   // SQLite LIKE is case-insensitive; make PostgreSQL behave the same
}

/** PostgreSQL returns NUMERIC (e.g. SUM of BIGINT) as strings; turn whole numbers back into ints. */
function normalize_rows(PDOStatement $st, array $rows): array
{
    if (!db_is_pg() || !$rows) return $rows;
    $num = [];
    for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
        $m = $st->getColumnMeta($i);
        if (($m['native_type'] ?? '') === 'numeric') $num[] = $m['name'];
    }
    if (!$num) return $rows;
    foreach ($rows as &$r) {
        foreach ($num as $c) if (isset($r[$c]) && is_string($r[$c])) $r[$c] = preg_match('/^-?\d+$/', $r[$c]) ? (int)$r[$c] : (float)$r[$c];
    }
    return $rows;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare(adapt_sql($sql));
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    $st = q($sql, $params);
    return normalize_rows($st, $st->fetchAll());
}

function row(string $sql, array $params = []): ?array
{
    $st = q($sql, $params);
    $r = $st->fetch();
    if ($r === false) return null;
    return normalize_rows($st, [$r])[0];
}

function val(string $sql, array $params = [])
{
    $r = row($sql, $params);
    return $r === null ? null : reset($r);
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
    if (db_is_pg()) {
        if (in_array($table, NO_ID_TABLES, true)) { q($sql, $data); return 0; }
        return (int)q($sql . ' RETURNING id', $data)->fetchColumn();
    }
    q($sql, $data);
    return (int)db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $wp = []): int
{
    $set = implode(',', array_map(fn($c) => "$c = :s_$c", array_keys($data)));
    $params = [];
    foreach ($data as $k => $v) $params['s_' . $k] = $v;
    return q("UPDATE $table SET $set WHERE $where", array_merge($params, $wp))->rowCount();
}

/** " FOR UPDATE" on PostgreSQL (row lock inside a transaction); SQLite serialises writers already. */
function for_update(): string
{
    return db_is_pg() ? ' FOR UPDATE' : '';
}

/** SQL expression: whole days from $earlier to $later (both ISO date strings / columns / placeholders). */
function sql_daydiff(string $later, string $earlier): string
{
    return db_is_pg() ? "(($later)::date - ($earlier)::date)" : "CAST(julianday($later) - julianday($earlier) AS INTEGER)";
}

/** Run $fn inside a transaction (nested calls use savepoints). All-or-nothing. */
function tx(callable $fn)
{
    static $depth = 0;
    $pdo = db();
    $outer = $depth === 0;
    $sp = 'sp' . $depth;
    if ($outer) $pdo->exec(db_is_pg() ? 'BEGIN' : 'BEGIN IMMEDIATE'); else $pdo->exec("SAVEPOINT $sp");
    $depth++;
    try {
        $res = $fn();
        $depth--;
        if ($outer) $pdo->exec('COMMIT'); else $pdo->exec("RELEASE SAVEPOINT $sp");
        return $res;
    } catch (Throwable $e) {
        $depth--;
        try {
            if ($outer) $pdo->exec('ROLLBACK');
            else { $pdo->exec("ROLLBACK TO SAVEPOINT $sp"); $pdo->exec("RELEASE SAVEPOINT $sp"); }
        } catch (Throwable $x) { /* the engine already aborted the transaction */ }
        throw $e;
    }
}

/** Convert the portable schema to PostgreSQL. */
function pg_schema(string $sql): string
{
    $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY', $sql);
    return preg_replace('/\bINTEGER\b/', 'BIGINT', $sql);
}

/** Apply any pending migration files from database/migrations in order, then (re)create protection triggers. */
function migrate(): array
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    $done = array_column(rows('SELECT name FROM schema_migrations'), 'name');
    $applied = [];
    $files = glob(BASE_PATH . '/database/migrations/*.sql');
    sort($files);
    foreach ($files as $f) {
        $name = basename($f);
        if (in_array($name, $done, true)) continue;
        $sql = file_get_contents($f);
        if (db_is_pg()) $sql = pg_schema($sql);
        $pdo->exec('BEGIN');
        try {
            $pdo->exec($sql);
            q('INSERT INTO schema_migrations(name, applied_at) VALUES (?, ?)', [$name, date('Y-m-d H:i:s')]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        $applied[] = $name;
    }
    $mark = 'triggers:' . TRIGGERS_VERSION;
    if ($applied || !(int)val('SELECT COUNT(*) FROM schema_migrations WHERE name = ?', [$mark])) {
        install_triggers();
        q('INSERT INTO schema_migrations(name, applied_at) VALUES (?, ?) ON CONFLICT (name) DO NOTHING', [$mark, date('Y-m-d H:i:s')]);
    }
    return $applied;
}
