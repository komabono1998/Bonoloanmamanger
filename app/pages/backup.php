<?php
$title = 'Backup & recovery';
$a = get('a');
$ext = Backup::ext();

if ($a === 'download') {
    require_perm('backup.manage');
    $f = basename(get('file'));
    must(preg_match('/^[A-Za-z0-9_.-]+\.(sqlite|dump)$/', $f) === 1 && is_file(Backup::dir() . '/' . $f), 'Backup file not found.');
    audit('Backup downloaded', 'Backup', 'backup', $f);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize(Backup::dir() . '/' . $f));
    readfile(Backup::dir() . '/' . $f);
    exit;
}
if ($a === 'create') post_action(function () {
    require_perm('backup.manage');
    $r = Backup::create('Manual');
    if (!$r['ok']) throw new AppError('Database backup verification failed: ' . $r['message']);
    q("UPDATE approval_requests SET status = 'Approved', decided_by = ?, decided_at = ?, decision_notes = 'Backup executed' WHERE type = 'backup_request' AND status = 'Pending'", [uid(), now()]);
    return 'Backup created and verified: ' . $r['file'];
});
if ($a === 'request') post_action(function () {
    require_perm('backup.request');
    $br = branch_id() ?: (int)val('SELECT MIN(id) FROM branches');
    insert('approval_requests', ['branch_id' => $br, 'type' => 'backup_request', 'ref_type' => 'backup', 'ref_id' => 0, 'amount' => 0, 'reason' => clean(post('reason')) ?: 'Backup requested',
        'required_authority' => 'System Administrator', 'requested_by' => uid(), 'requested_at' => now()]);
    audit('Backup requested', 'Backup', 'backup', 'request', null, 'requested', clean(post('reason')));
    return 'Backup requested. The System Administrator has been notified on the Backup page.';
});
if ($a === 'req_restore') post_action(function () {
    Backup::requestRestore(post('file'), clean(post('reason')));
    return 'Restore requested. The COO must authorize it before it can run (authorization is valid for 24 hours).';
});
if ($a === 'auth_restore') post_action(function () {
    Backup::authorizeRestore((int)post('request_id'), post('decision') === 'approve', clean(post('notes')));
    return post('decision') === 'approve' ? 'Recovery authorized for 24 hours. The System Administrator can now execute the restore.' : 'Recovery request rejected.';
});
if ($a === 'restore') post_action(function () {
    must(post('confirm_text') === 'RESTORE', 'Type RESTORE (in capitals) to confirm.');
    Backup::restore(post('file'));
    flash('ok', 'Database restored and verified. A safety copy of the previous database was kept.');
    return [null, url('backup')];
});
if ($a === 'upload') post_action(function () use ($ext) {
    require_perm('backup.manage');
    $f = $_FILES['backup'] ?? null;
    must($f && $f['error'] === UPLOAD_ERR_OK, 'Choose a backup file to upload.');
    must(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === $ext, "Backup files for this database end in .$ext.");
    $name = 'uploaded_' . date('Ymd_His') . '.' . $ext;
    $dest = Backup::dir() . '/' . $name;
    must(move_uploaded_file($f['tmp_name'], $dest), 'The file could not be saved.');
    [$ok, $msg] = Backup::verify($dest);
    if (!$ok) { @unlink($dest); throw new AppError('Database backup verification failed: ' . $msg); }
    q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)', [now(), 'Uploaded', $name, filesize($dest), 'Success', 1, 'Uploaded and verified', uid()]);
    audit('Backup uploaded', 'Backup', 'backup', $name);
    return 'Backup uploaded and verified. A restore from it still needs COO authorization.';
});

page_head('Backup & recovery', '', 'Verified backups of the database and uploaded documents; controlled restoration with independent authorization.');
$last = Backup::lastSuccess();
$h = System::health();
echo '<div class="kpis">' . kpi('Last verified backup', $last ? e(fmt_dt($last['at'])) : '<span class="ico-bad">never</span>', $last ? $last['type'] : '', $last ? ($h['backup']['ok'] ? 'ok' : 'warn') : 'bad')
    . kpi('Database', e($h['db']['engine'])) . kpi('Auto-backup', setting_int('backup_auto_hours') ? 'every ' . setting_int('backup_auto_hours') . ' h' : 'off')
    . kpi('Recovery objectives', 'RTO ' . (setting('rto_hours') !== '' ? setting('rto_hours') . ' h' : '—') . ' · RPO ' . (setting('rpo_hours') !== '' ? setting('rpo_hours') . ' h' : '—'), setting('rto_hours') === '' ? 'not yet approved by the institution' : '') . '</div>';
if (db_is_pg() && !Backup::pgTool('pg_dump')) echo '<div class="notice bad">pg_dump was not found on this server. Install the PostgreSQL client tools (or set PG_BIN), or rely on your database provider\'s managed backups and point-in-time recovery.</div>';

if (can('backup.request') && !can('backup.manage')) {
    echo '<div class="card"><h2>Request a backup</h2>';
    form_open(url('backup', ['a' => 'request']), ['attrs' => 'style="grid-template-columns:1fr auto;align-items:end"']);
    field('reason', 'Reason (optional)'); echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Request backup'); echo '</div>'; form_close(); echo '</div>';
}

$pendingReq = rows("SELECT a.*, u.full_name AS req FROM approval_requests a JOIN users u ON u.id = a.requested_by WHERE a.type IN ('backup_request','restore') AND a.status = 'Pending' ORDER BY a.id");
if ($pendingReq) {
    echo '<div class="card"><h2>Pending requests</h2>';
    data_table(['requested_at' => ['When', 'dt'], 'type' => 'Type', 'reason' => 'Details', 'req' => 'Requested by',
        'act' => ['', function ($r) {
            if ($r['type'] === 'backup_request') return can('backup.manage') ? 'Run “Back up now” to fulfil' : '';
            if (!can('backup.authorize') || (int)$r['requested_by'] === (int)uid()) return '<span class="muted">awaiting COO authorization</span>';
            return '<form method="post" action="' . e(url('backup', ['a' => 'auth_restore'])) . '" style="display:flex;gap:.3rem" data-confirm="Authorize this database restore?">' . csrf_field() . '<input type="hidden" name="request_id" value="' . (int)$r['id'] . '"><input type="text" name="notes" placeholder="notes" style="width:110px">'
                . '<button class="btn sm primary" name="decision" value="approve">Authorize</button><button class="btn sm danger" name="decision" value="reject">Reject</button></form>';
        }]], $pendingReq, ['class' => 'nofilter']);
    echo '</div>';
}

if (can('backup.manage')) {
    echo '<div class="grid g2"><div class="card"><h2>Create a backup now</h2><p class="muted">Also created automatically at each approved End-of-Day and when the last backup is older than the configured interval. Keep copies on a separate system.</p>';
    echo '<form method="post" action="' . e(url('backup', ['a' => 'create'])) . '">' . csrf_field() . '<button class="btn primary">Back up now</button></form>';
    echo '<h3>Upload a backup file</h3>';
    form_open(url('backup', ['a' => 'upload']), ['upload' => true, 'attrs' => 'style="grid-template-columns:1fr auto;align-items:end"']);
    field('backup', "Backup file (.$ext)", ['type' => 'file']); echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Upload & verify', 'default'); echo '</div>'; form_close();
    echo '</div><div class="card"><h2>Data integrity check</h2><p class="muted">Cross-checks derived balances against the transaction history (payments vs allocations, cash movements, closed loans, till expectations, branch consistency).</p>';
    if (get('check') === '1') {
        $iss = Backup::integrityReport();
        if (!$iss) echo '<div class="notice ok">✔ All checks passed. Balances reconcile with the transaction history.</div>';
        else { echo '<div class="notice bad">' . count($iss) . ' issue(s) found:</div>'; data_table(['0' => 'Record', '1' => 'Problem'], array_map(fn($i) => ['0' => $i[0], '1' => $i[1]], $iss), ['class' => 'nofilter']); }
        audit('Integrity check run', 'Backup', 'database', 'integrity', null, count($iss) . ' issue(s)');
    }
    echo btn('Run integrity check', url('backup', ['check' => 1]), 'primary') . '</div></div>';
}

$hist = rows('SELECT h.*, u.full_name FROM backup_history h LEFT JOIN users u ON u.id = h.user_id ORDER BY h.id DESC LIMIT 100');
$authorized = array_column(rows("SELECT reason FROM approval_requests WHERE type = 'restore' AND status = 'Approved' AND decided_at > ?", [date('Y-m-d H:i:s', time() - 86400)]), 'reason');
echo '<div class="card"><h2>Backup history</h2>';
data_table(['at' => ['When', 'dt'], 'type' => 'Type', 'file' => 'File', 'size' => ['Size', fn($r) => $r['size'] ? number_format($r['size'] / 1024) . ' KB' : '', 'r'], 'status' => ['Status', 'badge'], 'verified' => ['Verified', fn($r) => $r['verified'] ? '<span class="ico-ok">✔ verified</span>' : '<span class="ico-bad">no</span>'],
    'message' => 'Message', 'full_name' => 'By',
    'act' => ['', function ($r) use ($authorized) {
        if (!can('backup.manage') || $r['status'] !== 'Success' || !$r['file'] || !is_file(Backup::dir() . '/' . $r['file'])) return '';
        $o = '<a class="btn sm" href="' . e(url('backup', ['a' => 'download', 'file' => $r['file']])) . '">Download</a> ';
        $isAuth = false;
        foreach ($authorized as $x) if (str_starts_with($x, $r['file'] . ' — ')) $isAuth = true;
        if (setting('restore_requires_authorization') !== '0' && !$isAuth) {
            return $o . '<form method="post" action="' . e(url('backup', ['a' => 'req_restore'])) . '" style="display:inline-flex;gap:.2rem">' . csrf_field() . '<input type="hidden" name="file" value="' . e($r['file']) . '"><input type="text" name="reason" placeholder="why restore?" required style="width:120px"><button class="btn sm">Request restore</button></form>';
        }
        return $o . '<form method="post" action="' . e(url('backup', ['a' => 'restore'])) . '" style="display:inline-flex;gap:.2rem" data-confirm="RESTORE the database from this backup? All changes made after it will be lost. A safety backup of the current database is taken first.">'
            . csrf_field() . '<input type="hidden" name="file" value="' . e($r['file']) . '"><input type="text" name="confirm_text" placeholder="type RESTORE" style="width:100px" required><button class="btn sm danger">Restore</button></form>';
    }]], $hist, ['empty' => 'No backups yet.']);
echo '</div>';
