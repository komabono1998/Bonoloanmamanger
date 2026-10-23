<?php
/** Operational health and security monitoring for the cloud deployment. */
final class System
{
    public static function migrationsPending(): int
    {
        $files = array_map('basename', glob(BASE_PATH . '/database/migrations/*.sql') ?: []);
        try { $done = array_column(rows('SELECT name FROM schema_migrations'), 'name'); } catch (Throwable $e) { return count($files); }
        return count(array_diff($files, $done));
    }

    /** Full health snapshot for administrators / COO. */
    public static function health(): array
    {
        $h = [];
        try { val('SELECT 1'); $h['db'] = ['ok' => true, 'engine' => db_is_pg() ? 'PostgreSQL' : 'SQLite (single server)']; }
        catch (Throwable $e) { $h['db'] = ['ok' => false, 'engine' => 'unreachable']; }
        $h['storage'] = ['ok' => (bool)Storage::writable(), 'driver' => Storage::driver() === 's3' ? 'S3-compatible bucket' : 'local private folder'];
        $age = Backup::ageHours();
        $maxAge = max(24, setting_int('backup_auto_hours') * 2);
        $last = Backup::lastSuccess();
        $h['backup'] = ['age_hours' => $age, 'last' => $last ? $last['type'] . ' · ' . fmt_dt($last['at']) : null, 'ok' => $age !== null && $age <= $maxAge,
            'failed_recent' => (int)val("SELECT COUNT(*) FROM backup_history WHERE status = 'Failed' AND at > ?", [date('Y-m-d H:i:s', time() - 7 * 86400)])];
        $since = date('Y-m-d H:i:s', time() - 86400);
        $h['security'] = ['failed_24h' => (int)val('SELECT COUNT(*) FROM login_events WHERE success = 0 AND at > ?', [$since]),
            'locked' => (int)val('SELECT COUNT(*) FROM users WHERE locked_until > ?', [now()]), 'new_ip_24h' => (int)val("SELECT COUNT(*) FROM login_events WHERE success = 1 AND reason LIKE 'new IP%' AND at > ?", [$since])];
        $h['users'] = ['active' => (int)val("SELECT COUNT(*) FROM users WHERE status = 'active'"), 'mfa' => (int)val("SELECT COUNT(*) FROM users WHERE status = 'active' AND mfa_enabled = 1")];
        $h['branches'] = ['active' => (int)val("SELECT COUNT(*) FROM branches WHERE status = 'active'")];
        $h['migrations_pending'] = self::migrationsPending();
        $req = array_map('trim', explode(',', setting('mfa_required_roles')));
        $miss = rows("SELECT username, role FROM users WHERE status = 'active' AND mfa_enabled = 0");
        $h['mfa_missing'] = array_values(array_map(fn($u) => $u['username'], array_filter($miss, fn($u) => in_array($u['role'], $req, true))));
        $h['mfa_ok'] = !$h['mfa_missing'];
        $notes = [];
        if (!is_production()) $notes[] = 'APP_ENV is not "production"';
        if (!request_is_https()) $notes[] = 'this request is not over HTTPS';
        if (!env('APP_KEY')) $notes[] = 'APP_KEY is not set (MFA seeds use a development key)';
        if (!db_is_pg()) $notes[] = 'running on SQLite — use managed PostgreSQL for multi-user cloud production';
        if (!setting('rto_hours') || !setting('rpo_hours')) $notes[] = 'recovery objectives (RTO/RPO) are not recorded';
        $h['production_notes'] = $notes;
        $h['production_ready'] = !$notes;
        return $h;
    }

    /** Minimal, non-sensitive status for load balancers and uptime monitors. */
    public static function publicStatus(): array
    {
        $out = ['status' => 'ok', 'time' => gmdate('c'), 'version' => '3.0'];
        if (!is_installed()) return ['status' => 'not_installed'] + $out;
        $h = self::health();
        $out['db'] = $h['db']['ok'] ? 'ok' : 'down';
        $out['storage'] = $h['storage']['ok'] ? 'ok' : 'down';
        $out['migrations'] = $h['migrations_pending'] ? 'pending' : 'ok';
        $out['backup'] = $h['backup']['ok'] ? 'ok' : 'stale';
        if (!$h['db']['ok'] || !$h['storage']['ok'] || $h['migrations_pending']) $out['status'] = 'down';
        elseif (!$h['backup']['ok']) $out['status'] = 'degraded';
        return $out;
    }
}
