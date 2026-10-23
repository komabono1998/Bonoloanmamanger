<?php
/**
 * Backup, verification, controlled restore and data-integrity checks - SQLite (VACUUM INTO) or PostgreSQL (pg_dump / pg_restore).
 * Backups are reported as successful only after they have been re-opened and verified. Restores need a COO-authorized request.
 */
final class Backup
{
    public static function ext(): string { return db_is_pg() ? 'dump' : 'sqlite'; }

    public static function dir(): string
    {
        $d = env('BACKUP_DIR') ?: setting('backup_dir') ?: 'storage/backups';
        $abs = preg_match('~^([A-Za-z]:[\\\\/]|/)~', $d) ? $d : BASE_PATH . '/' . $d;
        $abs .= tenant_dir_suffix();
        if (!is_dir($abs)) @mkdir($abs, 0775, true);
        return rtrim(str_replace('\\', '/', $abs), '/');
    }

    /** Locate a PostgreSQL client tool (pg_dump / pg_restore). */
    public static function pgTool(string $tool): ?string
    {
        $bin = env('PG_BIN');
        $win = DIRECTORY_SEPARATOR === '\\';
        if ($bin) { $p = rtrim($bin, '/\\') . DIRECTORY_SEPARATOR . $tool . ($win ? '.exe' : ''); return is_file($p) ? $p : null; }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $d) {
            $p = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $tool . ($win ? '.exe' : '');
            if ($d !== '' && is_file($p)) return $p;
        }
        return null;
    }

    /** Run a PostgreSQL client tool with the connection settings; returns [exitCode, output]. */
    private static function pgRun(string $tool, array $args): array
    {
        $exe = self::pgTool($tool);
        if (!$exe) return [127, "$tool was not found. Install the PostgreSQL client tools on the application server or set PG_BIN."];
        $cmd = escapeshellarg($exe) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $envv = array_merge(getenv(), ['PGPASSWORD' => (string)env('DB_PASSWORD', ''), 'PGSSLMODE' => (string)env('DB_SSLMODE', is_production() ? 'require' : 'prefer')]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envv);
        if (!is_resource($proc)) return [126, 'Could not start ' . $tool];
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($proc), trim($out)];
    }

    private static function pgConn(): array
    {
        $a = ['-h', (string)env('DB_HOST', 'localhost'), '-p', (string)env('DB_PORT', '5432'), '-U', (string)env('DB_USER', 'postgres')];
        return $a;
    }

    /** Open a backup read-only and confirm it is a complete, consistent Bono database. */
    public static function verify(string $file): array
    {
        if (!is_file($file) || filesize($file) < 1024) return [false, 'Backup file is missing or incomplete.'];
        if (db_is_pg()) {
            [$code, $out] = self::pgRun('pg_restore', ['--list', $file]);
            if ($code !== 0) return [false, 'pg_restore could not read the backup: ' . substr($out, 0, 200)];
            foreach (['TABLE public users', 'TABLE public loans', 'TABLE public payments', 'TABLE public loan_ledger', 'TABLE public audit_logs'] as $needle) {
                if (!preg_match('/' . preg_quote(str_replace('public', env('DB_SCHEMA') ?: 'public', $needle), '/') . '\b/', $out)) return [false, "Backup does not contain $needle."];
            }
            return [true, 'Verified'];
        }
        try {
            $p = new PDO('sqlite:' . $file);
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $ic = $p->query('PRAGMA integrity_check')->fetchColumn();
            if ($ic !== 'ok') return [false, 'Integrity check failed: ' . $ic];
            if ($p->query('PRAGMA foreign_key_check')->fetchAll()) return [false, 'Foreign key violations found in backup.'];
            foreach (['users', 'clients', 'loans', 'payments', 'loan_ledger', 'audit_logs'] as $t) $p->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            $n = (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $p = null;
            return $n > 0 ? [true, 'Verified'] : [false, 'Backup contains no users.'];
        } catch (Throwable $e) {
            return [false, 'Backup could not be read: ' . $e->getMessage()];
        }
    }

    /** Create a consistent copy, then verify it. Never claims success unless verification passed. */
    public static function create(string $type = 'Manual', ?string $businessDate = null): array
    {
        $name = 'bono_' . strtolower(preg_replace('/\W+/', '', $type)) . '_' . date('Ymd_His') . ($businessDate ? '_bd' . str_replace('-', '', $businessDate) : '') . '.' . self::ext();
        $file = self::dir() . '/' . $name;
        $ok = false; $msg = '';
        try {
            if (file_exists($file)) unlink($file);
            if (db_is_pg()) {
                $args = array_merge(self::pgConn(), ['-Fc', '--no-owner', '-f', $file, db_name()]);
                if (env('DB_SCHEMA')) { $args[] = '-n'; $args[] = (string)env('DB_SCHEMA'); }
                [$code, $out] = self::pgRun('pg_dump', $args);
                if ($code !== 0) throw new RuntimeException($out);
            } else {
                db()->exec('VACUUM INTO ' . db()->quote($file));
            }
            [$ok, $msg] = self::verify($file);
            if (!$ok && is_file($file)) @unlink($file);
        } catch (Throwable $e) {
            $msg = 'Backup failed: ' . $e->getMessage();
            if (is_file($file)) @unlink($file);
        }
        $filesMsg = '';
        if ($ok && Storage::driver() === 'local') { $filesMsg = self::backupFiles($name); }
        q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [now(), $type, $ok ? $name : null, $ok ? filesize($file) : null, $ok ? 'Success' : 'Failed', $ok ? 1 : 0, $msg . ($filesMsg ? ' · ' . $filesMsg : ''), uid()]);
        audit($ok ? 'Backup created' : 'Backup FAILED', 'Backup', 'backup', $name, null, $ok ? 'verified' : $msg);
        if ($ok) self::prune();
        return ['ok' => $ok, 'message' => $msg, 'file' => $ok ? $name : null];
    }

    /** Archive private uploads next to the database backup (local storage driver). Returns a short status text. */
    private static function backupFiles(string $dbBackupName): string
    {
        try {
            $src = Storage::localRoot();
            if (!is_dir($src) || !count(glob($src . '/*') ?: [])) return 'no uploaded files to archive';
            $tar = self::dir() . '/' . preg_replace('/\.(sqlite|dump)$/', '', $dbBackupName) . '_files.tar';
            $ph = new PharData($tar);
            $ph->buildFromDirectory($src);
            $n = count(new RecursiveIteratorIterator($ph));
            return "files archived ($n)";
        } catch (Throwable $e) {
            return 'file archive failed: ' . $e->getMessage();
        }
    }

    public static function prune(): void
    {
        $keep = max(5, setting_int('backup_keep'));
        $rows = rows("SELECT id, file FROM backup_history WHERE status = 'Success' AND file IS NOT NULL AND type <> 'Pre-restore' ORDER BY id DESC");
        foreach (array_slice($rows, $keep) as $r) {
            $f = self::dir() . '/' . $r['file'];
            if (is_file($f)) @unlink($f);
            $t = preg_replace('/\.(sqlite|dump)$/', '', $f) . '_files.tar';
            if (is_file($t)) @unlink($t);
            q("UPDATE backup_history SET status = 'Pruned' WHERE id = ?", [$r['id']]);
        }
    }

    /** Take an automatic backup if none succeeded within the configured number of hours. */
    public static function autoIfDue(): void
    {
        $h = setting_int('backup_auto_hours');
        if ($h <= 0) return;
        $last = val("SELECT MAX(at) FROM backup_history WHERE status = 'Success'");
        if (!$last || strtotime($last) < time() - $h * 3600) self::create('Scheduled');
    }

    public static function lastSuccess(): ?array
    {
        return row("SELECT * FROM backup_history WHERE status = 'Success' ORDER BY id DESC LIMIT 1");
    }

    /** Hours since the last verified backup (null = never). */
    public static function ageHours(): ?float
    {
        $l = self::lastSuccess();
        return $l ? round((time() - strtotime($l['at'])) / 3600, 1) : null;
    }

    // ---------------------------------------------------------------- controlled recovery
    public static function requestRestore(string $file, string $reason): int
    {
        require_perm('backup.manage');
        must(self::safeName($file) && is_file(self::dir() . '/' . $file), 'Backup file not found.');
        must($reason !== '', 'State why the database must be restored.');
        $branch = (int)val('SELECT MIN(id) FROM branches');
        $id = insert('approval_requests', ['branch_id' => $branch, 'type' => 'restore', 'ref_type' => 'backup', 'ref_id' => 0, 'amount' => 0, 'reason' => $file . ' — ' . $reason,
            'required_authority' => 'COO', 'requested_by' => uid(), 'requested_at' => now()]);
        audit('Restore requested', 'Backup', 'backup', $file, null, 'requested', $reason, 'REQ-' . $id, null, null, $branch);
        return $id;
    }

    public static function authorizeRestore(int $requestId, bool $approve, string $notes = ''): void
    {
        require_perm('backup.authorize');
        $r = row("SELECT * FROM approval_requests WHERE id = ? AND type = 'restore' AND status = 'Pending'", [$requestId]);
        must((bool)$r, 'That request is no longer pending.');
        must((int)$r['requested_by'] !== (int)uid(), 'Separation of duties: the requester cannot authorize their own recovery.');
        update('approval_requests', ['status' => $approve ? 'Approved' : 'Rejected', 'decided_by' => uid(), 'decided_at' => now(), 'decision_notes' => $notes ?: null], 'id = :id', ['id' => $requestId]);
        audit($approve ? 'Restore authorized' : 'Restore rejected', 'Backup', 'backup', $r['reason'], null, $approve ? 'authorized' : 'rejected', $notes, 'REQ-' . $requestId);
    }

    private static function safeName(string $n): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+\.(sqlite|dump)$/', $n) === 1;
    }

    public static function restore(string $name): void
    {
        require_perm('backup.manage');
        must(self::safeName($name), 'Invalid backup file name.');
        $file = self::dir() . '/' . $name;
        $authId = null;
        if (setting('restore_requires_authorization') !== '0') {
            $r = row("SELECT * FROM approval_requests WHERE type = 'restore' AND status = 'Approved' AND ref_type = 'backup' AND reason LIKE ? AND decided_at > ? ORDER BY id DESC LIMIT 1",
                [$name . ' — %', date('Y-m-d H:i:s', time() - 86400)]);
            must((bool)$r, 'A restore needs prior authorization from the COO (request it first; authorization is valid for 24 hours).');
            $authId = (int)$r['id'];
        }
        [$ok, $msg] = self::verify($file);
        must($ok, 'Database backup verification failed: ' . $msg);
        $safety = self::create('Pre-restore');
        must($safety['ok'], 'A safety backup of the current database could not be created, so the restore was cancelled.');
        $safetyFile = self::dir() . '/' . $safety['file'];
        if (db_is_pg()) {
            db_close();
            $args = array_merge(self::pgConn(), ['--clean', '--if-exists', '--no-owner', '--single-transaction', '-d', db_name(), $file]);
            [$code, $out] = self::pgRun('pg_restore', $args);
            must($code === 0, 'The restore failed and the database was left unchanged: ' . substr($out, 0, 300));
            db_close();
        } else {
            $live = db_path();
            db_close();
            foreach (['-wal', '-shm'] as $x) if (is_file($live . $x)) @unlink($live . $x);
            must(copy($file, $live), 'The backup could not be copied into place. The previous database is unchanged.');
            $chk = self::verify($live);
            if (!$chk[0]) {
                copy($safetyFile, $live);
                throw new AppError('Restored database failed verification and the previous database was put back: ' . $chk[1]);
            }
        }
        $GLOBALS['__settings_dirty'] = true;
        bd_changed();
        branches_changed();
        // the restored database predates the safety-backup record, so write both history rows again
        q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [now(), 'Pre-restore', $safety['file'], filesize($safetyFile), 'Success', 1, 'Safety copy of the database taken before restoring ' . $name, uid()]);
        q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [now(), 'Restore', $name, filesize($file), 'Success', 1, 'Restored from ' . $name, uid()]);
        audit('Database restored', 'Backup', 'backup', $name, null, 'restored', 'Safety backup: ' . $safety['file'], $authId ? 'REQ-' . $authId : '');
    }

    /** Cross-check derived balances against transaction history. Returns a list of problems (empty = healthy). */
    public static function integrityReport(): array
    {
        $issues = [];
        if (!db_is_pg()) {
            $ic = val('PRAGMA integrity_check');
            if ($ic !== 'ok') $issues[] = ['Database', 'SQLite integrity check: ' . $ic];
            $fk = rows('PRAGMA foreign_key_check');
            if ($fk) $issues[] = ['Database', count($fk) . ' foreign key violation(s).'];
        }
        // payment amount = allocations
        foreach (rows("SELECT p.id, p.amount, (SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.payment_id = p.id) AS s FROM payments p") as $r) {
            if ((int)$r['amount'] !== (int)$r['s']) $issues[] = ['Payment #' . $r['id'], 'Amount ' . $r['amount'] . ' does not equal allocations ' . $r['s']];
        }
        // principal outstanding = approved principal - principal paid - principal waived
        foreach (rows("SELECT l.id, l.loan_no, l.approved_principal, bl.paid_principal, bl.principal_out,
                       (SELECT COALESCE(-SUM(g.amount),0) FROM loan_ledger g WHERE g.loan_id = l.id AND g.type = 'waiver' AND g.component = 'principal') AS waived
                       FROM loans l JOIN v_loan_balances bl ON bl.loan_id = l.id WHERE l.disbursed_at IS NOT NULL") as $r) {
            if ((int)$r['approved_principal'] - (int)$r['paid_principal'] - (int)$r['waived'] !== (int)$r['principal_out']) $issues[] = [$r['loan_no'], 'Principal outstanding does not reconcile to payments.'];
        }
        // posted payment cash = movement
        foreach (rows("SELECT p.id, p.amount, p.status, (SELECT COALESCE(SUM(m.direction*m.amount),0) FROM cash_movements m WHERE m.ref_type = 'payment' AND m.ref_id = p.id) AS s
                       FROM payments p WHERE p.method <> 'Customer Credit'") as $r) {
            if (($r['status'] === 'Posted' && (int)$r['s'] !== (int)$r['amount']) || ($r['status'] === 'Reversed' && (int)$r['s'] !== 0)) $issues[] = ['Payment #' . $r['id'], 'Cash movements do not match the payment.'];
        }
        // closed loans must have zero balance
        foreach (rows("SELECT l.loan_no FROM loans l JOIN v_loan_balances bl ON bl.loan_id = l.id WHERE l.status = 'Closed' AND bl.total_out <> 0") as $r) {
            $issues[] = [$r['loan_no'], 'Loan is closed but has a non-zero balance.'];
        }
        // sessions: stored expected = movements
        foreach (rows("SELECT s.id, s.session_no, s.expected_cash FROM cashier_sessions s WHERE s.status IN ('Submitted','Reconciled')") as $r) {
            if ((int)$r['expected_cash'] !== Cash::expected((int)$r['id'])) $issues[] = [$r['session_no'], 'Expected cash changed after the session was counted.'];
        }
        // branch consistency: a payment must be in the same branch as its loan
        foreach (rows('SELECT p.id FROM payments p JOIN loans l ON l.id = p.loan_id WHERE p.branch_id <> l.branch_id') as $r) $issues[] = ['Payment #' . $r['id'], 'Payment branch differs from its loan branch.'];
        return $issues;
    }
}
