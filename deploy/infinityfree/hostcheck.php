<?php
/**
 * Host check: upload to the web root, open it in the browser, read the result, then DELETE it.
 * It shows whether this hosting account can run Bono Loan Manager (PHP version, extensions, SQLite, writable folders, limits).
 * It shows no passwords or data.
 */
header('Content-Type: text/html; charset=utf-8');
header('Robots-Tag: noindex');
$root = __DIR__;
$rows = [];
$add = function (string $what, bool $ok, string $detail = '', bool $required = true) use (&$rows) { $rows[] = [$what, $ok, $detail, $required]; };

$add('PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), 'This server runs PHP ' . PHP_VERSION);
foreach (['pdo' => true, 'pdo_sqlite' => true, 'sqlite3' => false, 'mbstring' => true, 'iconv' => true, 'openssl' => true, 'json' => true, 'ctype' => true, 'fileinfo' => false, 'curl' => false, 'zlib' => true, 'gd' => false, 'zip' => false] as $ext => $req) {
    $add('Extension ' . $ext, extension_loaded($ext), $req ? 'required' : 'optional', $req);
}
$sqliteOk = false; $detail = '';
if (extension_loaded('pdo_sqlite')) {
    try {
        $f = $root . '/hostcheck_test.sqlite';
        $pdo = new PDO('sqlite:' . $f);
        $pdo->exec('CREATE TABLE t(id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $pdo->exec("INSERT INTO t(v) VALUES ('ok')");
        $row = $pdo->query('SELECT v, sqlite_version() AS ver FROM t')->fetch(PDO::FETCH_ASSOC);
        $sqliteOk = ($row['v'] ?? '') === 'ok'; $detail = 'SQLite ' . ($row['ver'] ?? '?') . ' can create and read a database file here';
        $pdo = null; @unlink($f);
    } catch (Throwable $e) { $detail = 'SQLite could not create a database file here: ' . $e->getMessage(); }
}
$add('SQLite database works', $sqliteOk, $detail ?: 'pdo_sqlite is missing');
foreach (['storage', 'storage/db', 'storage/uploads', 'storage/backups', 'storage/logs'] as $d) {
    $p = $root . '/' . $d; $ok = false;
    if (is_dir($p)) { $t = $p . '/.write_test'; $ok = @file_put_contents($t, 'x') !== false; @unlink($t); }
    $add('Writable folder ' . $d, $ok, is_dir($p) ? ($ok ? 'ok' : 'not writable: set permissions to 755/775 or ask hosting support') : 'folder missing: upload the storage folder');
}
$add('HTTPS in use', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'Use the https:// address; turn on the free SSL certificate in the hosting panel', false);
$ini = fn($k) => ini_get($k);
$mem = (int)$ini('memory_limit'); $add('Memory limit', $mem === -1 || $mem >= 64, 'memory_limit = ' . $ini('memory_limit') . ' (64M or more recommended)', false);
$add('Script time limit', (int)$ini('max_execution_time') === 0 || (int)$ini('max_execution_time') >= 10, 'max_execution_time = ' . $ini('max_execution_time') . ' s (End of Day runs in short steps, so 10 s is enough)', false);
$add('Upload limit', true, 'upload_max_filesize = ' . $ini('upload_max_filesize') . ', post_max_size = ' . $ini('post_max_size') . ' (documents are limited to 5 MB in the app)', false);
$add('Time zone', true, 'The application uses Africa/Kampala', false);
$allReq = true; foreach ($rows as [, $ok, , $req]) if ($req && !$ok) $allReq = false;
?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Host check</title>
<style>body{font:15px/1.5 system-ui,Segoe UI,Arial;max-width:760px;margin:2rem auto;padding:0 1rem;color:#1b2333}h1{margin-bottom:.2rem}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #e3e7ee;padding:.4rem .5rem;vertical-align:top}.ok{color:#14663f;font-weight:700}.bad{color:#b53030;font-weight:700}.opt{color:#9a6b00;font-weight:700}.banner{padding:.8rem 1rem;border-radius:8px;margin:1rem 0;font-weight:600}.g{background:#e3f4ea}.r{background:#fbe5e5}small{color:#667}</style>
<h1>Host check</h1><small><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></small>
<div class="banner <?= $allReq ? 'g' : 'r' ?>"><?= $allReq ? 'This hosting account can run Bono Loan Manager. Delete this file now.' : 'Something required is missing (see the red items). This hosting account cannot run Bono Loan Manager as it is.' ?></div>
<table><?php foreach ($rows as [$what, $ok, $detail, $req]): ?><tr><td><?= htmlspecialchars($what) ?></td><td class="<?= $ok ? 'ok' : ($req ? 'bad' : 'opt') ?>"><?= $ok ? 'OK' : ($req ? 'MISSING' : 'optional') ?></td><td><small><?= htmlspecialchars($detail) ?></small></td></tr><?php endforeach; ?></table>
<p><small>After installing, check that <b>/storage/db/</b> and <b>/app/</b> show "403 Forbidden" in the browser. Delete hostcheck.php when you are done.</small></p>
