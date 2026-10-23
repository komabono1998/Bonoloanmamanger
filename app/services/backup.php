<?php
/** Backup, verification, restore and data-integrity checks. */

final class Backup
{
    public static function dir(): string
    {
        $d = setting('backup_dir') ?: 'storage/backups';
        $abs = preg_match('~^([A-Za-z]:[\\\\/]|/)~', $d) ? $d : BASE_PATH . '/' . $d;
        if (!is_dir($abs)) @mkdir($abs, 0775, true);
        return rtrim(str_replace('\\', '/', $abs), '/');
    }

    /** Open a backup file read-only and confirm it is a complete, consistent Bono database. */
    public static function verify(string $file): array
    {
        if (!is_file($file) || filesize($file) < 4096) return [false, 'Backup file is missing or incomplete.'];
        try {
            $p = new PDO('sqlite:' . $file);
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $ic = $p->query('PRAGMA integrity_check')->fetchColumn();
            if ($ic !== 'ok') return [false, 'Integrity check failed: ' . $ic];
            $fk = $p->query('PRAGMA foreign_key_check')->fetchAll();
            if ($fk) return [false, 'Foreign key violations found in backup.'];
            foreach (['users', 'clients', 'loans', 'payments', 'loan_ledger', 'audit_logs'] as $t) {
                $p->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            }
            $n = (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $p = null;
            return $n > 0 ? [true, 'Verified'] : [false, 'Backup contains no users.'];
        } catch (Throwable $e) {
            return [false, 'Backup could not be read: ' . $e->getMessage()];
        }
    }

    /** Create a consistent copy with VACUUM INTO, then verify it. Never claims success unless verification passed. */
    public static function create(string $type = 'Manual', ?string $businessDate = null): array
    {
        $dir = self::dir();
        $name = 'bono_' . strtolower(preg_replace('/\W+/', '', $type)) . '_' . date('Ymd_His') . ($businessDate ? '_bd' . str_replace('-', '', $businessDate) : '') . '.sqlite';
        $file = $dir . '/' . $name;
        $ok = false; $msg = '';
        try {
            if (file_exists($file)) unlink($file);
            db()->exec("VACUUM INTO " . db()->quote($file));
            [$ok, $msg] = self::verify($file);
            if (!$ok && is_file($file)) @unlink($file);
        } catch (Throwable $e) {
            $msg = 'Backup failed: ' . $e->getMessage();
            if (is_file($file)) @unlink($file);
        }
        q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [now(), $type, $ok ? $name : null, $ok ? filesize($file) : null, $ok ? 'Success' : 'Failed', $ok ? 1 : 0, $msg, uid()]);
        audit($ok ? 'Backup created' : 'Backup FAILED', 'Backup', 'backup', $name, null, $ok ? 'verified' : $msg);
        if ($ok) self::prune();
        return ['ok' => $ok, 'message' => $msg, 'file' => $ok ? $name : null];
    }

    public static function prune(): void
    {
        $keep = max(5, setting_int('backup_keep'));
        $rows = rows("SELECT id, file FROM backup_history WHERE status = 'Success' AND file IS NOT NULL AND type <> 'Pre-restore' ORDER BY id DESC");
        foreach (array_slice($rows, $keep) as $r) {
            $f = self::dir() . '/' . $r['file'];
            if (is_file($f)) @unlink($f);
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

    public static function restore(string $name): void
    {
        require_perm('backup.manage');
        must(preg_match('/^[A-Za-z0-9_.-]+\.sqlite$/', $name) === 1, 'Invalid backup file name.');
        $file = self::dir() . '/' . $name;
        [$ok, $msg] = self::verify($file);
        must($ok, 'Database backup verification failed: ' . $msg);
        $safety = self::create('Pre-restore');
        must($safety['ok'], 'A safety backup of the current database could not be created, so the restore was cancelled.');
        $live = db_path();
        db_close();
        foreach (['-wal', '-shm'] as $x) if (is_file($live . $x)) @unlink($live . $x);
        must(copy($file, $live), 'The backup could not be copied into place. The previous database is unchanged.');
        // verify the restored database
        $chk = self::verify($live);
        if (!$chk[0]) {
            copy(self::dir() . '/' . $safety['file'], $live);
            throw new AppError('Restored database failed verification and the previous database was put back: ' . $chk[1]);
        }
        $GLOBALS['__settings_dirty'] = true;
        bd_changed();
        audit('Database restored', 'Backup', 'backup', $name, null, 'restored', 'Safety backup: ' . $safety['file']);
        // the restored database predates the safety backup record, so write both history rows again
        $sf = self::dir() . '/' . $safety['file'];
        q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [now(), 'Pre-restore', $safety['file'], filesize($sf), 'Success', 1, 'Safety copy of the database taken before restoring ' . $name, uid()]);
        q('INSERT INTO backup_history(at, type, file, size, status, verified, message, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [now(), 'Restore', $name, filesize($file), 'Success', 1, 'Restored from ' . $name, uid()]);
    }

    /** Cross-check derived balances against transaction history. Returns a list of problems (empty = healthy). */
    public static function integrityReport(): array
    {
        $issues = [];
        $ic = val('PRAGMA integrity_check');
        if ($ic !== 'ok') $issues[] = ['Database', 'SQLite integrity check: ' . $ic];
        $fk = rows('PRAGMA foreign_key_check');
        if ($fk) $issues[] = ['Database', count($fk) . ' foreign key violation(s).'];
        // payment amount = allocations
        foreach (rows("SELECT p.id, p.amount, COALESCE(SUM(a.amount),0) s FROM payments p LEFT JOIN payment_allocations a ON a.payment_id = p.id GROUP BY p.id HAVING p.amount <> s") as $r) {
            $issues[] = ['Payment #' . $r['id'], 'Amount ' . $r['amount'] . ' does not equal allocations ' . $r['s']];
        }
        // ledger principal for each disbursed loan
        foreach (rows("SELECT l.id, l.loan_no, l.approved_principal, b.paid_principal, b.principal_out FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id
                       WHERE l.disbursed_at IS NOT NULL AND (l.approved_principal - b.paid_principal - COALESCE((SELECT -SUM(amount) FROM loan_ledger WHERE loan_id=l.id AND type='waiver' AND component='principal'),0)) <> b.principal_out") as $r) {
            $issues[] = [$r['loan_no'], 'Principal outstanding does not reconcile to payments.'];
        }
        // posted payment cash = movement
        foreach (rows("SELECT p.id, p.amount, COALESCE(SUM(m.direction*m.amount),0) s FROM payments p LEFT JOIN cash_movements m ON m.ref_type='payment' AND m.ref_id=p.id
                       WHERE p.method <> 'Customer Credit' GROUP BY p.id HAVING (p.status='Posted' AND s <> p.amount) OR (p.status='Reversed' AND s <> 0)") as $r) {
            $issues[] = ['Payment #' . $r['id'], 'Cash movements do not match the payment.'];
        }
        // closed loans must have zero balance
        foreach (rows("SELECT l.loan_no FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status = 'Closed' AND b.total_out <> 0") as $r) {
            $issues[] = [$r['loan_no'], 'Loan is closed but has a non-zero balance.'];
        }
        // sessions: stored expected = movements
        foreach (rows("SELECT s.id, s.session_no, s.expected_cash FROM cashier_sessions s WHERE s.status IN ('Submitted','Reconciled')") as $r) {
            if ((int)$r['expected_cash'] !== Cash::expected((int)$r['id'])) $issues[] = [$r['session_no'], 'Expected cash changed after the session was counted.'];
        }
        return $issues;
    }
}
