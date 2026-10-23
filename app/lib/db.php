<?php
/** Data access layer: thin PDO wrapper around the local SQLite database. */

class AppError extends Exception {}

function db_path(): string
{
    return defined('DB_FILE') ? DB_FILE : BASE_PATH . '/storage/db/bono.sqlite';
}

function &db_holder(): ?PDO
{
    static $pdo = null;
    return $pdo;
}

function db(): PDO
{
    $pdo = &db_holder();
    if ($pdo === null) {
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

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
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

/** Run $fn inside a transaction (nested calls use savepoints). All-or-nothing. */
function tx(callable $fn)
{
    static $depth = 0;
    $pdo = db();
    $outer = $depth === 0;
    $sp = 'sp' . $depth;
    if ($outer) $pdo->exec('BEGIN IMMEDIATE'); else $pdo->exec("SAVEPOINT $sp");
    $depth++;
    try {
        $res = $fn();
        $depth--;
        if ($outer) $pdo->exec('COMMIT'); else $pdo->exec("RELEASE $sp");
        return $res;
    } catch (Throwable $e) {
        $depth--;
        try {
            if ($outer) $pdo->exec('ROLLBACK');
            else { $pdo->exec("ROLLBACK TO $sp"); $pdo->exec("RELEASE $sp"); }
        } catch (Throwable $x) { /* SQLite already aborted the transaction */ }
        throw $e;
    }
}

/** Apply any pending migration files from database/migrations in order. */
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
    return $applied;
}
