<?php
$title = 'System health';
$h = System::health();
page_head('System health', btn('Public probe (/health)', url('health')), 'Technical status of the cloud deployment. No client or financial data is shown here.');
echo '<div class="kpis">'
    . kpi('Database', $h['db']['ok'] ? 'Online' : 'DOWN', $h['db']['engine'], $h['db']['ok'] ? 'ok' : 'bad')
    . kpi('File storage', $h['storage']['ok'] ? 'Writable' : 'PROBLEM', $h['storage']['driver'], $h['storage']['ok'] ? 'ok' : 'bad')
    . kpi('Last verified backup', $h['backup']['age_hours'] === null ? 'never' : $h['backup']['age_hours'] . ' h ago', (string)$h['backup']['last'], $h['backup']['ok'] ? 'ok' : 'bad')
    . kpi('Backup failures (7 d)', num($h['backup']['failed_recent']), '', $h['backup']['failed_recent'] ? 'bad' : 'ok')
    . kpi('Pending migrations', num($h['migrations_pending']), '', $h['migrations_pending'] ? 'bad' : 'ok')
    . kpi('Database size', db_is_pg() ? e((string)(val('SELECT pg_size_pretty(pg_database_size(current_database()))') ?? '')) : number_format((is_file(db_path()) ? filesize(db_path()) : 0) / 1048576, 2) . ' MB')
    . '</div>';

echo '<div class="grid g2"><div class="card"><h2>Production readiness</h2>';
if ($h['production_ready']) echo '<div class="notice ok">All production checks pass.</div>';
else { echo '<ul style="margin:0;padding-left:1.1rem">'; foreach ($h['production_notes'] as $n) echo '<li>' . e($n) . '</li>'; echo '</ul>'; }
echo '</div><div class="card"><h2>Recovery objectives</h2>';
desc_list(['Recovery time objective (RTO)' => setting('rto_hours') !== '' ? e(setting('rto_hours')) . ' hours' : '<span class="ico-bad">not recorded</span>', 'Recovery point objective (RPO)' => setting('rpo_hours') !== '' ? e(setting('rpo_hours')) . ' hours' : '<span class="ico-bad">not recorded</span>',
    'Automatic backup interval' => e(setting('backup_auto_hours')) . ' hours', 'Backups kept' => e(setting('backup_keep'))], 2);
echo '<p class="muted">The institution must approve these. Set them under Settings → Security &amp; backup.</p></div></div>';

echo '<div class="card"><h2>Configuration (no secrets shown)</h2>';
desc_list(['Environment' => e(env('APP_ENV', 'local')), 'Database engine' => e(db_driver()), 'Storage driver' => e(Storage::driver()), 'HTTPS' => request_is_https() ? 'yes' : 'no',
    'Trusted proxy headers' => env('TRUST_PROXY') === '1' ? 'yes' : 'no', 'PHP' => e(PHP_VERSION), 'Session timeout' => e(setting('session_timeout_min')) . ' min', 'MFA required for' => e(setting('mfa_required_roles')),
    'Backup folder' => e(Backup::dir()), 'Time zone' => e(date_default_timezone_get())], 3);
echo '</div>';

$log = BASE_PATH . '/storage/logs/php-error.log';
echo '<div class="card"><h2>Recent technical errors</h2>';
if (is_file($log) && filesize($log) > 0) {
    $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES), -25);
    echo '<pre style="white-space:pre-wrap;font-size:.8rem;max-height:320px;overflow:auto">' . e(implode("\n", $lines)) . '</pre>';
} else echo '<p class="muted">No errors logged.</p>';
echo '</div>';
