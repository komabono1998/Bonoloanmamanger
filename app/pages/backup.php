<?php
$title = 'Backup & restore';
$a = get('a');

if ($a === 'download') {
    $f = basename(get('file'));
    must(preg_match('/^[A-Za-z0-9_.-]+\.sqlite$/', $f) === 1 && is_file(Backup::dir() . '/' . $f), 'Backup file not found.');
    audit('Backup downloaded', 'Backup', 'backup', $f);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize(Backup::dir() . '/' . $f));
    readfile(Backup::dir() . '/' . $f);
    exit;
}
if ($a === 'create') post_action(function () {
    $r = Backup::create('Manual');
    if (!$r['ok']) throw new AppError('Database backup verification failed: ' . $r['message']);
    return 'Backup created and verified: ' . $r['file'];
});
if ($a === 'restore') post_action(function () {
    must(post('confirm_text') === 'RESTORE', 'Type RESTORE (in capitals) to confirm.');
    Backup::restore(post('file'));
    return ['Database restored and verified. A safety copy of the previous database was kept.', url('backup')];
});
if ($a === 'upload') post_action(function () {
    require_perm('backup.manage');
    $f = $_FILES['backup'] ?? null;
    must($f && $f['error'] === UPLOAD_ERR_OK, 'Choose a backup file to upload.');
    must(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === 'sqlite', 'Backup files end in .sqlite.');
    $name = 'uploaded_' . date('Ymd_His') . '.sqlite';
    $dest = Backup::dir() . '/' . $name;
    must(move_uploaded_file($f['tmp_name'], $dest), 'The file could not be saved.');
    [$ok, $msg] = Backup::verify($dest);
    if (!$ok) { @unlink($dest); throw new AppError('Database backup verification failed: ' . $msg); }
    q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)', [now(), 'Uploaded', $name, filesize($dest), 'Success', 1, 'Uploaded and verified', uid()]);
    audit('Backup uploaded', 'Backup', 'backup', $name);
    return 'Backup uploaded and verified. You can now restore from it below.';
});

page_head('Backup & restore', '', 'Backups are consistent snapshots, verified before they are reported as successful.');
$last = Backup::lastSuccess();
$size = is_file(db_path()) ? filesize(db_path()) : 0;
echo '<div class="kpis">' . kpi('Last verified backup', $last ? e(fmt_dt($last['at'])) : '<span class="ico-bad">never</span>', $last ? $last['type'] : '', $last ? '' : 'bad')
    . kpi('Database size', number_format($size / 1048576, 2) . ' MB') . kpi('Backup folder', '<span style="font-size:.9rem">' . e(Backup::dir()) . '</span>') . kpi('Auto-backup', setting_int('backup_auto_hours') ? 'every ' . setting_int('backup_auto_hours') . ' h' : 'off') . '</div>';

echo '<div class="grid g2"><div class="card"><h2>Create a backup now</h2><p class="muted">Also created automatically at each approved End-of-Day and when the last backup is older than the configured interval. Copy backups to a different drive or flash disk regularly.</p>';
echo '<form method="post" action="' . e(url('backup', ['a' => 'create'])) . '">' . csrf_field() . '<button class="btn primary">Back up now</button></form>';
echo '<h3>Upload a backup file</h3>';
form_open(url('backup', ['a' => 'upload']), ['upload' => true, 'attrs' => 'style="grid-template-columns:1fr auto;align-items:end"']);
field('backup', 'Backup file (.sqlite)', ['type' => 'file']); echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Upload & verify', 'default'); echo '</div>'; form_close();
echo '</div><div class="card"><h2>Data integrity check</h2><p class="muted">Cross-checks derived balances against the transaction history (payments vs allocations, cash movements, closed loans, till expectations).</p>';
if (get('check') === '1') {
    $iss = Backup::integrityReport();
    if (!$iss) echo '<div class="notice ok">✔ All checks passed. Balances reconcile with the transaction history.</div>';
    else { echo '<div class="notice bad">' . count($iss) . ' issue(s) found:</div>'; data_table(['0' => 'Record', '1' => 'Problem'], array_map(fn($i) => ['0' => $i[0], '1' => $i[1]], $iss), ['class' => 'nofilter']); }
    audit('Integrity check run', 'Backup', 'database', 'integrity', null, count($iss) . ' issue(s)');
}
echo btn('Run integrity check', url('backup', ['check' => 1]), 'primary') . '</div></div>';

$h = rows('SELECT h.*, u.full_name FROM backup_history h LEFT JOIN users u ON u.id = h.user_id ORDER BY h.id DESC LIMIT 100');
echo '<div class="card"><h2>Backup history</h2>';
data_table(['at' => ['When', 'dt'], 'type' => 'Type', 'file' => 'File', 'size' => ['Size', fn($r) => $r['size'] ? number_format($r['size'] / 1024) . ' KB' : '', 'r'], 'status' => ['Status', 'badge'], 'verified' => ['Verified', fn($r) => $r['verified'] ? '<span class="ico-ok">✔ verified</span>' : '<span class="ico-bad">no</span>'],
    'message' => 'Message', 'full_name' => 'By',
    'act' => ['', function ($r) {
        if ($r['status'] !== 'Success' || !$r['file'] || !is_file(Backup::dir() . '/' . $r['file'])) return '';
        return '<a class="btn sm" href="' . e(url('backup', ['a' => 'download', 'file' => $r['file']])) . '">Download</a> <form method="post" action="' . e(url('backup', ['a' => 'restore'])) . '" style="display:inline-flex;gap:.2rem" data-confirm="RESTORE the database from this backup? All changes made after it will be lost. A safety backup of the current database is taken first.">'
            . csrf_field() . '<input type="hidden" name="file" value="' . e($r['file']) . '"><input type="text" name="confirm_text" placeholder="type RESTORE" style="width:100px" required><button class="btn sm danger">Restore</button></form>';
    }]], $h, ['empty' => 'No backups yet.']);
echo '</div>';
