<?php
/**
 * Business-date control and End-of-Day (EOD) reconciliation - per branch.
 * Each branch has its own business date and EOD cycle; closing one branch never blocks another.
 */

/** The business date currently open for posting in a branch (distinct from the computer clock), or null. */
function current_bd(?int $branch = null): ?string
{
    static $cache = [];
    if (isset($GLOBALS['__bd_dirty'])) { $cache = []; unset($GLOBALS['__bd_dirty']); }
    $branch = $branch ?? branch_id();
    if (!$branch) return null;
    if (!array_key_exists($branch, $cache)) {
        $cache[$branch] = val("SELECT business_date FROM business_days WHERE branch_id = ? AND status IN ('Open','Reopened') ORDER BY business_date DESC LIMIT 1", [$branch]) ?: '';
    }
    return $cache[$branch] === '' ? null : $cache[$branch];
}

function bd_changed(): void { $GLOBALS['__bd_dirty'] = true; }

/** Date to use for "as at" calculations (overdue, due today): the branch's open business date, else the clock. */
function reporting_date(?int $branch = null): string
{
    return current_bd($branch) ?? today();
}

/** Date new transactions are posted against in a branch. Throws when no business day is open. */
function posting_date(?int $branch = null): string
{
    $branch = $branch ?? branch_id();
    must($branch > 0, 'You are not assigned to a branch.');
    $d = current_bd($branch);
    if ($d) return $d;
    $last = val('SELECT MAX(business_date) FROM business_days WHERE branch_id = ?', [$branch]);
    if ($last) throw new AppError('This business date is already closed. A new business day must be opened before posting transactions.');
    throw new AppError('No business day is open. An authorized user must start the business day first.');
}

/** Working-day check using the branch calendar, then the institution calendar, then the weekday rule. */
function is_working_day(string $date, ?int $branch = null): bool
{
    $branch = $branch ?? branch_id();
    $o = row('SELECT is_working FROM business_calendar WHERE date = ? AND branch_id IN (?, 0) ORDER BY branch_id DESC LIMIT 1', [$date, (int)$branch]);
    if ($o) return (bool)$o['is_working'];
    $b = $branch ? branch_row($branch) : null;
    $days = array_map('intval', array_filter(explode(',', ($b['working_days'] ?? '') ?: setting('working_days'))));
    return in_array((int)date('N', strtotime($date)), $days, true);
}

final class Eod
{
    public static function day(string $date, ?int $branch = null): ?array
    {
        return row('SELECT * FROM business_days WHERE branch_id = ? AND business_date = ?', [$branch ?? branch_id(), $date]);
    }

    public static function latestRecord(string $date, ?int $branch = null): ?array
    {
        return row('SELECT * FROM eod_records WHERE branch_id = ? AND business_date = ? ORDER BY version DESC LIMIT 1', [$branch ?? branch_id(), $date]);
    }

    public const ADMIN_MSG = 'End of Day is run once by the System Administrator (Administration → End of Day) after a verified backup.';

    /** One-step mode (default): the System Administrator closes the day. The alternative is the two-person workflow (finance prepares, a manager approves). */
    public static function adminMode(): bool { return setting('eod_mode') !== 'workflow'; }

    /** Has a verified "Pre-EOD" backup been taken for this business date? */
    public static function preBackupDone(string $date): bool
    {
        return (int)val("SELECT COUNT(*) FROM backup_history WHERE type = 'Pre-EOD' AND status = 'Success' AND file LIKE ?", ['%_bd' . str_replace('-', '', $date) . '%']) > 0;
    }

    public static function lastPreBackup(string $date): ?array
    {
        return row("SELECT * FROM backup_history WHERE type = 'Pre-EOD' AND status = 'Success' AND file LIKE ? ORDER BY id DESC LIMIT 1", ['%_bd' . str_replace('-', '', $date) . '%']) ?: null;
    }

    /** Start the first business day (or the next one) of a branch. */
    public static function startDay(string $date, bool $allowNonWorking, int $branch): void
    {
        require_perm('eod.run');
        self::openDayCore($date, $allowNonWorking, $branch);
    }

    /**
     * The one-step End of Day, run by the System Administrator after the backup: accrues penalties, records the day's summary,
     * closes and locks the business date, takes the closing backup and moves the system date to the next working day.
     */
    public static function runDay(string $date, string $notes, int $branch): array
    {
        self::runPhase('check', $date, $notes, $branch);
        must(self::preBackupDone($date), 'Take the verified backup first, then run End of Day.');
        self::runPhase('close', $date, $notes, $branch);
        self::runPhase('ledger', $date, $notes, $branch);
        $b = self::runPhase('closing_backup', $date, $notes, $branch);
        self::runPhase('next', $date, $notes, $branch);
        $b['next_date'] = current_bd($branch);
        return $b;
    }

    /**
     * One system date for the whole institution: a branch may not close its day while another active branch is still on an earlier date.
     * Returns [branch name, date] of the earliest branch that must close first, or null. (Setting "eod_align_branches", on by default.)
     */
    public static function alignmentBlock(int $branch, string $date): ?array
    {
        if (setting('eod_align_branches') === '0') return null;
        $worst = null;
        foreach (rows("SELECT id, name FROM branches WHERE status = 'active' AND id <> ?", [$branch]) as $b) {
            $d = current_bd((int)$b['id']);
            if ($d && $d < $date && (!$worst || $d < $worst[1])) $worst = [$b['name'], $d];
        }
        return $worst;
    }

    /** The phases of the one-step End of Day, in order. The screen runs them one after the other and shows each as it completes. */
    public const PHASES = ['check' => 'Checking the branch is ready', 'backup' => 'Backing up the database', 'close' => 'Accruing penalties and closing the business date',
        'ledger' => 'Posting the day to the general ledger', 'closing_backup' => 'Taking the closing backup', 'next' => 'Opening the next business day'];

    public static function runPhase(string $phase, string $date, string $notes, int $branch): array
    {
        require_perm('eod.run');
        $br = branch_row($branch);
        must($br && $br['status'] === 'active', 'This branch is inactive.');
        $day = self::day($date, $branch);
        if ($phase === 'check' || $phase === 'backup' || $phase === 'close') {
            must($day && $day['status'] !== 'Closed' && current_bd($branch) === $date, 'That business date is not the open day of this branch.');
        }
        switch ($phase) {
            case 'check':
                $blockers = self::blockers($date, $branch);
                must(!$blockers, 'End of Day cannot run yet: ' . implode('; ', array_map(fn($b) => $b['label'] . ' (' . $b['detail'] . ')', $blockers)) . '.');
                return ['ok' => true, 'message' => 'The branch is ready: tills are counted and reconciled.'];
            case 'backup':
                require_perm('backup.manage');
                $b = Backup::create('Pre-EOD', $date);
                must($b['ok'], 'The backup failed, so the business date was NOT closed: ' . $b['message']);
                return ['ok' => true, 'message' => 'Verified backup taken (' . ($b['file'] ?? '') . ').'];
            case 'close':
                must(self::blockers($date, $branch) === [], 'The branch is no longer ready to close.');
                must(self::preBackupDone($date), 'Take the verified backup first, then run End of Day.');
                tx(function () use ($date, $notes, $branch) {
                    Penalty::accrue($date, $branch);
                    Loans::refreshStatuses(null, $branch);
                    $rec = self::latestRecord($date, $branch);
                    $var = self::absVariance($date, $branch);
                    $id = insert('eod_records', ['branch_id' => $branch, 'business_date' => $date, 'version' => $rec ? $rec['version'] + 1 : 1, 'status' => 'Closed', 'summary_json' => json_encode(self::summary($date, $branch)),
                        'submitted_by' => uid(), 'submitted_at' => now(), 'reviewed_by' => uid(), 'reviewed_at' => now(), 'notes' => $notes ?: null, 'review_notes' => 'Run by the System Administrator', 'required_authority' => 'System Administrator']);
                    update('business_days', ['status' => 'Closed', 'closed_by' => uid(), 'closed_at' => now()], 'branch_id = :b AND business_date = :d', ['b' => $branch, 'd' => $date]);
                    bd_changed();
                    audit('End of Day run and business date locked', 'End of Day', 'eod_record', $id, null, ['date' => $date, 'cash variance (absolute)' => $var], $notes, 'run by the System Administrator', null, null, $branch);
                });
                return ['ok' => true, 'message' => fmt_date($date) . ' is closed and locked.'];
            case 'ledger':
                $n = Gl::postPendingSafe($branch, $date);
                return ['ok' => true, 'message' => $n ? $n . ' ledger journal(s) posted.' : 'The ledger is up to date.'];
            case 'closing_backup':
                $rec = self::latestRecord($date, $branch);
                $b = Backup::create('EOD', $date);
                if ($rec) update('eod_records', ['backup_status' => $b['ok'] ? 'Verified' : 'FAILED: ' . $b['message'], 'backup_file' => $b['file'] ?? null], 'id = :id', ['id' => $rec['id']]);
                return $b + ['message' => $b['message'] ?? ''];
            case 'next':
                if (setting('eod_auto_open_next') !== '0') {   // the system date always moves on (only the test fixtures switch this off)
                    try { self::openDayCore(self::suggestNextDate($branch), false, $branch); }
                    catch (Throwable $e) { error_log('Next business day was not opened automatically: ' . $e->getMessage()); return ['ok' => true, 'message' => 'The next business day was not opened automatically (' . $e->getMessage() . ') - start it below.']; }
                }
                $nx = current_bd($branch);
                return ['ok' => true, 'message' => $nx ? 'The system date is now ' . fmt_date($nx) . '.' : 'Start the next business day when ready.'];
        }
        throw new AppError('Unknown End-of-Day step.');
    }

    /** Tell the people responsible that the day was closed (e-mail where an address and a mail server exist; always recorded in the audit trail). */
    public static function notifyClosed(int $branch, string $date, ?string $nextDate, bool $backupOk): void
    {
        $name = branch_name($branch);
        $subject = 'End of Day completed: ' . $name . ' (' . fmt_date($date) . ')';
        $html = '<p>End of Day was run successfully for <b>' . e($name) . '</b>.</p><ul><li>Business date closed and locked: <b>' . e(fmt_date($date)) . '</b></li>'
            . '<li>Next system date: <b>' . e($nextDate ? fmt_date($nextDate) : 'not yet opened') . '</b></li><li>Closing backup: <b>' . ($backupOk ? 'verified' : 'FAILED - take a manual backup') . '</b></li>'
            . '<li>Run by: ' . e(user()['full_name']) . ' at ' . e(fmt_dt(now())) . '</li></ul>';
        $to = [];
        foreach (rows("SELECT DISTINCT u.email FROM users u LEFT JOIN user_branches ub ON ub.user_id = u.id WHERE u.status = 'active' AND u.email IS NOT NULL AND u.email <> ''
                       AND (u.role = 'COO' OR u.id = ? OR (ub.branch_id = ? AND u.role = 'Branch Manager'))", [uid(), $branch]) as $r) $to[] = $r['email'];
        foreach (array_unique($to) as $addr) { try { send_mail($addr, $subject, $html); } catch (Throwable $e) { error_log('EOD notice not sent to ' . $addr . ': ' . $e->getMessage()); } }
        audit('End-of-Day notice sent', 'End of Day', 'business_day', $date, null, count(array_unique($to)) . ' recipient(s)', '', '', null, null, $branch);
    }

    public static function suggestNextDate(?int $branch = null): string
    {
        $branch = $branch ?? branch_id();
        $last = val('SELECT MAX(business_date) FROM business_days WHERE branch_id = ?', [$branch]);
        $d = $last ? Calc::addDays($last, 1) : today();
        for ($i = 0; $i < 14 && !is_working_day($d, $branch); $i++) $d = Calc::addDays($d, 1);
        return $d;
    }

    public static function openDay(string $date, bool $allowNonWorking = false, ?int $branch = null): void
    {
        require_perm('eod.manage');
        self::openDayCore($date, $allowNonWorking, $branch);
    }

    /** Opens a business day without a permission check (used by the manual action and by the automatic roll-over after End-of-Day). */
    private static function openDayCore(string $date, bool $allowNonWorking = false, ?int $branch = null): void
    {
        $branch = $branch ?? branch_id();
        if (can('eod.run')) { $br = branch_row($branch); must($br && $br['status'] === 'active', 'This branch is inactive.'); } else assert_active_branch($branch);
        must(is_date($date), 'Business date is not a valid date.');
        tx(function () use ($date, $allowNonWorking, $branch) {
            $open = current_bd($branch);
            must($open === null, "Business date " . fmt_date($open) . " is still open. Complete its End-of-Day before starting a new day.");
            $last = val('SELECT MAX(business_date) FROM business_days WHERE branch_id = ?', [$branch]);
            must(!$last || $date > $last, 'The new business date must be after the last closed date (' . fmt_date($last) . ').');
            must($allowNonWorking || is_working_day($date, $branch), 'That date is not a working day in the business calendar. Tick the confirmation to open it anyway.');
            insert('business_days', ['branch_id' => $branch, 'business_date' => $date, 'status' => 'Open', 'opened_by' => uid(), 'opened_at' => now()]);
            bd_changed();
            audit('Business day opened', 'End of Day', 'business_day', $date, null, 'Open', '', '', null, null, $branch);
        });
        Loans::refreshStatuses(null, $branch);
    }

    /** Derived EOD status for display. */
    public static function status(string $date, ?int $branch = null): string
    {
        $branch = $branch ?? branch_id();
        $day = self::day($date, $branch);
        if (!$day) return 'Not Started';
        if ($day['status'] === 'Closed') return 'Closed';
        $rec = self::latestRecord($date, $branch);
        if ($rec && in_array($rec['status'], ['Awaiting Manager Review', 'Escalated to COO'], true)) return $rec['status'];
        if ($day['status'] === 'Reopened') return 'Reopened by Authorization';
        $c = self::sessionCounts($date, $branch);
        if ($c['Investigation'] > 0) return 'Variance Investigation';
        if ($rec && $rec['status'] === 'Variance Investigation' && $c['Open'] + $c['Submitted'] === 0) return 'Variance Investigation';
        if ($c['Submitted'] > 0 || ($c['Open'] === 0 && $c['Reconciled'] > 0 && !$rec)) return 'Awaiting Cashier Reconciliation';
        return 'In Progress';
    }

    public static function sessionCounts(string $date, ?int $branch = null): array
    {
        $c = ['Open' => 0, 'Submitted' => 0, 'Investigation' => 0, 'Reconciled' => 0];
        foreach (rows('SELECT status, COUNT(*) n FROM cashier_sessions WHERE branch_id = ? AND business_date = ? GROUP BY status', [$branch ?? branch_id(), $date]) as $r) {
            $c[$r['status']] = (int)$r['n'];
        }
        return $c;
    }

    /** Outstanding items that prevent or qualify closing the day. */
    public static function checklist(string $date, ?int $branch = null): array
    {
        $branch = $branch ?? branch_id();
        $c = self::sessionCounts($date, $branch);
        $items = [];
        $items[] = ['ok' => $c['Open'] === 0, 'label' => 'All cashier tills closed and counted', 'detail' => $c['Open'] . ' till(s) still open', 'blocking' => true];
        $items[] = ['ok' => $c['Submitted'] === 0, 'label' => 'All submitted cashier counts reconciled', 'detail' => $c['Submitted'] . ' awaiting reconciliation', 'blocking' => true];
        $items[] = ['ok' => $c['Investigation'] === 0, 'label' => 'No sessions under variance investigation', 'detail' => $c['Investigation'] . ' under investigation', 'blocking' => true];
        $pend = (int)val("SELECT COUNT(*) FROM expenses WHERE branch_id = ? AND status IN ('Pending','Approved')", [$branch]);
        $items[] = ['ok' => $pend === 0, 'label' => 'No unpaid / unapproved expenses', 'detail' => $pend . ' pending (warning only)', 'blocking' => false];
        $unrec = (int)val("SELECT COUNT(*) FROM cashier_sessions WHERE branch_id = ? AND business_date = ? AND variance <> 0 AND status = 'Reconciled'", [$branch, $date]);
        $items[] = ['ok' => $unrec === 0, 'label' => 'Cash variances', 'detail' => $unrec . ' reconciled session(s) carry a variance (reviewed by an independent approver)', 'blocking' => false];
        return $items;
    }

    public static function blockers(string $date, ?int $branch = null): array
    {
        if (setting('require_reconciliation') !== '1') return [];
        return array_values(array_filter(self::checklist($date, $branch), fn($i) => !$i['ok'] && $i['blocking']));
    }

    /** Sum of absolute cash variances for the day (used for escalation). */
    public static function absVariance(string $date, ?int $branch = null): int
    {
        return (int)val('SELECT COALESCE(SUM(ABS(variance)),0) FROM cashier_sessions WHERE branch_id = ? AND business_date = ?', [$branch ?? branch_id(), $date]);
    }

    public static function summary(string $d, ?int $branch = null): array
    {
        $b = $branch ?? branch_id();
        $s = [];
        // A. applications
        $s['applications'] = [
            'received' => (int)val('SELECT COUNT(*) FROM loans WHERE branch_id = ? AND application_date = ?', [$b, $d]),
            'requested' => (int)val('SELECT COALESCE(SUM(amount_requested),0) FROM loans WHERE branch_id = ? AND application_date = ?', [$b, $d]),
            'approved' => (int)val("SELECT COUNT(*) FROM loan_approvals a JOIN loans l ON l.id = a.loan_id WHERE l.branch_id = ? AND a.business_date = ? AND a.decision = 'Approved'", [$b, $d]),
            'rejected' => (int)val("SELECT COUNT(*) FROM loan_approvals a JOIN loans l ON l.id = a.loan_id WHERE l.branch_id = ? AND a.business_date = ? AND a.decision = 'Rejected'", [$b, $d]),
            'awaiting' => (int)val("SELECT COUNT(*) FROM loans WHERE branch_id = ? AND status IN ('Submitted','Under Review')", [$b]),
        ];
        // B. disbursements
        $dis = row("SELECT COUNT(*) n, COALESCE(SUM(approved_principal),0) principal,
                COALESCE(SUM(processing_fee+application_fee+inspection_fee+other_deductions),0) fees, COALESCE(SUM(net_amount),0) net
                FROM disbursements WHERE branch_id = ? AND business_date = ?", [$b, $d]);
        $byM = [];
        foreach (rows('SELECT method, COALESCE(SUM(net_amount),0) amt FROM disbursements WHERE branch_id = ? AND business_date = ? GROUP BY method', [$b, $d]) as $r) $byM[$r['method']] = (int)$r['amt'];
        $s['disbursements'] = [
            'count' => (int)$dis['n'], 'principal' => (int)$dis['principal'], 'fees' => (int)$dis['fees'], 'net' => (int)$dis['net'],
            'cash' => $byM['Cash'] ?? 0, 'bank' => $byM['Bank Transfer'] ?? 0,
            'other' => array_sum($byM) - ($byM['Cash'] ?? 0) - ($byM['Bank Transfer'] ?? 0),
        ];
        // C. collections
        $m = [];
        foreach (rows("SELECT method, COUNT(*) n, COALESCE(SUM(amount),0) amt FROM payments WHERE branch_id = ? AND business_date = ? AND status = 'Posted' GROUP BY method", [$b, $d]) as $r) {
            $m[$r['method']] = ['n' => (int)$r['n'], 'amt' => (int)$r['amt']];
        }
        $comp = [];
        foreach (rows("SELECT a.component, COALESCE(SUM(a.amount),0) amt FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
                       WHERE p.branch_id = ? AND p.business_date = ? AND p.status = 'Posted' GROUP BY a.component", [$b, $d]) as $r) $comp[$r['component']] = (int)$r['amt'];
        $s['collections'] = [
            'count' => array_sum(array_column($m, 'n')),
            'total' => array_sum(array_column($m, 'amt')),
            'cash' => $m['Cash']['amt'] ?? 0, 'bank' => $m['Bank Transfer']['amt'] ?? 0, 'mobile' => $m['Mobile Money']['amt'] ?? 0,
            'other' => array_sum(array_column($m, 'amt')) - ($m['Cash']['amt'] ?? 0) - ($m['Bank Transfer']['amt'] ?? 0) - ($m['Mobile Money']['amt'] ?? 0),
            'principal' => $comp['principal'] ?? 0, 'interest' => $comp['interest'] ?? 0, 'fees' => $comp['fee'] ?? 0,
            'penalties' => $comp['penalty'] ?? 0, 'credits' => $comp['credit'] ?? 0,
            'reversed_count' => (int)val('SELECT COUNT(*) FROM payments WHERE branch_id = ? AND reversal_business_date = ?', [$b, $d]),
            'reversed_amount' => (int)val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE branch_id = ? AND reversal_business_date = ?', [$b, $d]),
        ];
        // D. expenses
        $ex = row("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN method='Cash' THEN amount END),0) cash,
                COALESCE(SUM(CASE WHEN method<>'Cash' THEN amount END),0) noncash FROM expenses WHERE branch_id = ? AND paid_business_date = ? AND status = 'Paid'", [$b, $d]);
        $cats = [];
        foreach (rows("SELECT c.name, COALESCE(SUM(e.amount),0) amt FROM expenses e JOIN expense_categories c ON c.id = e.category_id
                       WHERE e.branch_id = ? AND e.paid_business_date = ? AND e.status = 'Paid' GROUP BY c.name", [$b, $d]) as $r) $cats[$r['name']] = (int)$r['amt'];
        $s['expenses'] = [
            'count' => (int)$ex['n'], 'cash' => (int)$ex['cash'], 'noncash' => (int)$ex['noncash'], 'categories' => $cats,
            'pending' => (int)val("SELECT COUNT(*) FROM expenses WHERE branch_id = ? AND status IN ('Pending','Approved')", [$b]),
            'authorized' => (int)val("SELECT COUNT(*) FROM expenses WHERE branch_id = ? AND paid_business_date = ? AND status='Paid' AND approved_by IS NOT NULL", [$b, $d]),
        ];
        // E. cash movements
        $cm = function (string $cat, int $dir) use ($d, $b): int {
            return (int)val('SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE branch_id = ? AND business_date = ? AND is_cash = 1 AND category = ? AND direction = ?', [$b, $d, $cat, $dir]);
        };
        $s['cash'] = [
            'opening' => $cm('float', 1), 'collections' => $cm('collection', 1), 'disbursements' => $cm('disbursement', -1),
            'expenses' => $cm('expense', -1), 'transfers_in' => $cm('transfer', 1), 'transfers_out' => $cm('transfer', -1),
            'reversals_in' => $cm('reversal', 1), 'reversals_out' => $cm('reversal', -1),
        ];
        $c = $s['cash'];
        $s['cash']['expected'] = $c['opening'] + $c['collections'] + $c['transfers_in'] + $c['reversals_in']
            - $c['disbursements'] - $c['expenses'] - $c['transfers_out'] - $c['reversals_out'];
        $cs = row("SELECT COALESCE(SUM(counted_cash),0) counted, COALESCE(SUM(variance),0) var, COUNT(*) n FROM cashier_sessions WHERE branch_id = ? AND business_date = ?", [$b, $d]);
        $s['cash']['actual'] = (int)$cs['counted'];
        $s['cash']['variance'] = (int)$cs['var'];
        $s['cash']['sessions'] = (int)$cs['n'];
        // F. portfolio
        $s['portfolio'] = [
            'opened' => (int)val('SELECT COUNT(*) FROM disbursements WHERE branch_id = ? AND business_date = ?', [$b, $d]),
            'closed' => (int)val('SELECT COUNT(*) FROM loans WHERE branch_id = ? AND closed_date = ?', [$b, $d]),
            'becoming_overdue' => (int)val('SELECT COUNT(*) FROM loans WHERE branch_id = ? AND overdue_since IS NOT NULL AND overdue_since = ?', [$b, Calc::addDays($d, -1)]),
            'principal_outstanding' => (int)val("SELECT COALESCE(SUM(bl.principal_out),0) FROM v_loan_balances bl JOIN loans l ON l.id=bl.loan_id WHERE l.branch_id = ? AND l.status IN ('Active','Overdue')", [$b]),
            'contractual_outstanding' => (int)val("SELECT COALESCE(SUM(bl.principal_out+bl.interest_out),0) FROM v_loan_balances bl JOIN loans l ON l.id=bl.loan_id WHERE l.branch_id = ? AND l.status IN ('Active','Overdue')", [$b]),
            'penalties_accrued' => (int)val('SELECT COALESCE(SUM(p.amount),0) FROM penalties p JOIN loans l ON l.id = p.loan_id WHERE l.branch_id = ? AND p.business_date = ?', [$b, $d]),
            'penalties_collected' => $comp['penalty'] ?? 0,
        ];
        return $s;
    }

    public static function exceptions(string $date, ?int $branch = null): array
    {
        $b = $branch ?? branch_id();
        $ex = [];
        foreach (rows("SELECT session_no, variance, variance_reason, status FROM cashier_sessions WHERE branch_id = ? AND business_date = ? AND (variance <> 0 OR status <> 'Reconciled')", [$b, $date]) as $r) {
            $ex[] = ['type' => 'Cashier session', 'ref' => $r['session_no'], 'detail' => $r['status'] . ($r['variance'] ? ' · variance ' . ugx($r['variance']) . ' · ' . $r['variance_reason'] : '')];
        }
        foreach (rows("SELECT p.id, r.receipt_no, p.amount, p.reversal_reason FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
                       WHERE p.branch_id = ? AND (p.reversal_business_date = ? OR (p.business_date = ? AND p.status = 'Reversed'))", [$b, $date, $date]) as $r) {
            $ex[] = ['type' => 'Reversed payment', 'ref' => $r['receipt_no'] ?: ('#' . $r['id']), 'detail' => ugx($r['amount']) . ' · ' . $r['reversal_reason']];
        }
        foreach (rows("SELECT expense_no, amount, status FROM expenses WHERE branch_id = ? AND (status IN ('Pending','Approved') OR (paid_business_date = ? AND status = 'Reversed'))", [$b, $date]) as $r) {
            $ex[] = ['type' => 'Expense', 'ref' => $r['expense_no'], 'detail' => $r['status'] . ' · ' . ugx($r['amount'])];
        }
        foreach (rows("SELECT id, amount, reason FROM approval_requests WHERE branch_id = ? AND status = 'Pending'", [$b]) as $r) {
            $ex[] = ['type' => 'Pending approval', 'ref' => '#' . $r['id'], 'detail' => ugx($r['amount']) . ' · ' . $r['reason']];
        }
        return $ex;
    }

    public static function submit(string $date, string $notes = '', ?int $branch = null): int
    {
        must(!self::adminMode(), self::ADMIN_MSG);
        require_perm('eod.manage');
        $branch = $branch ?? branch_id();
        assert_active_branch($branch);
        $day = self::day($date, $branch);
        must($day && $day['status'] !== 'Closed', 'This business date is already closed.');
        $blockers = self::blockers($date, $branch);
        must(!$blockers, 'EOD cannot be submitted while required reconciliations are outstanding: ' . implode('; ', array_map(fn($b) => $b['label'] . ' (' . $b['detail'] . ')', $blockers)) . '.');
        $rec = self::latestRecord($date, $branch);
        must(!$rec || !in_array($rec['status'], ['Awaiting Manager Review', 'Escalated to COO'], true), 'This business date has already been submitted for review.');
        return tx(function () use ($date, $notes, $rec, $branch) {
            Penalty::accrue($date, $branch);
            Loans::refreshStatuses(null, $branch);
            $version = $rec ? $rec['version'] + 1 : 1;
            $summary = self::summary($date, $branch);
            $var = self::absVariance($date, $branch);
            $required = $var > 0 ? Approvals::required('eod_variance', $var, $branch) : 'Branch Manager';
            $id = insert('eod_records', [
                'branch_id' => $branch, 'business_date' => $date, 'version' => $version, 'status' => $required === 'COO' ? 'Escalated to COO' : 'Awaiting Manager Review',
                'summary_json' => json_encode($summary), 'submitted_by' => uid(), 'submitted_at' => now(), 'notes' => $notes ?: null, 'required_authority' => $required,
            ]);
            audit('EOD submitted for review', 'End of Day', 'eod_record', $id, null, ['date' => $date, 'version' => $version, 'review by' => $required, 'variance' => $var], '', '', null, null, $branch);
            return $id;
        });
    }

    /** Independent review: closes and locks the branch business date, then takes a verified backup. */
    public static function approve(string $date, string $notes = '', ?int $branch = null, bool $backupFirst = false): array
    {
        must(!self::adminMode(), self::ADMIN_MSG);
        require_perm('eod.approve');
        $branch = $branch ?? branch_id();
        assert_active_branch($branch);
        $rec = self::latestRecord($date, $branch);
        must($rec && in_array($rec['status'], ['Awaiting Manager Review', 'Escalated to COO'], true), 'This business date has not been submitted for approval.');
        must((int)$rec['submitted_by'] !== (int)uid(), 'Separation of duties: the person who prepared the End-of-Day cannot be its approver.');
        must(!val('SELECT COUNT(*) FROM cashier_sessions WHERE branch_id = ? AND business_date = ? AND cashier_id = ?', [$branch, $date, uid()]), 'You acted as a cashier on this business date and cannot approve its reconciliation.');
        // re-evaluate: variance may have been changed by a reopened till
        $var = self::absVariance($date, $branch);
        $required = $var > 0 ? Approvals::required('eod_variance', $var, $branch) : 'Branch Manager';
        if ($required === 'COO' || $rec['status'] === 'Escalated to COO') {
            must(can('authority.institution'), 'This End-of-Day carries a cash variance above the Branch Manager limit and must be approved by the COO (escalated review).');
        }
        $blockers = self::blockers($date, $branch);
        must(!$blockers, 'EOD cannot be approved while required reconciliations are outstanding.');
        if ($backupFirst || setting('eod_pre_backup') === 'always') {
            $pre = Backup::create('Pre-EOD', $date);
            must($pre['ok'], 'The backup taken before End-of-Day FAILED (' . $pre['message'] . '). The business date was NOT closed. Fix the backup problem and try again.');
        }
        tx(function () use ($date, $notes, $rec, $branch) {
            update('eod_records', [
                'status' => 'Closed', 'reviewed_by' => uid(), 'reviewed_at' => now(), 'review_notes' => $notes ?: null,
                'summary_json' => json_encode(self::summary($date, $branch)),
            ], 'id = :id', ['id' => $rec['id']]);
            update('business_days', ['status' => 'Closed', 'closed_by' => uid(), 'closed_at' => now()], 'branch_id = :b AND business_date = :d', ['b' => $branch, 'd' => $date]);
            bd_changed();
            audit('EOD approved and business date locked', 'End of Day', 'eod_record', $rec['id'], $rec['status'], 'Closed', $notes, 'submitted by #' . $rec['submitted_by'], null, null, $branch);
        });
        Gl::postPendingSafe($branch, $date);
        $b = Backup::create('EOD', $date);
        update('eod_records', ['backup_status' => $b['ok'] ? 'Verified' : 'FAILED: ' . $b['message'], 'backup_file' => $b['file'] ?? null], 'id = :id', ['id' => $rec['id']]);
        // the system date moves on: the next working day opens automatically
        if (setting('eod_auto_open_next') !== '0') {
            try { self::openDayCore(self::suggestNextDate($branch), false, $branch); }
            catch (Throwable $e) { error_log('Next business day was not opened automatically: ' . $e->getMessage()); }
        }
        $b['next_date'] = current_bd($branch);
        return $b;
    }

    public static function returnForInvestigation(string $date, string $notes, ?int $branch = null): void
    {
        must(!self::adminMode(), self::ADMIN_MSG);
        require_perm('eod.approve');
        $branch = $branch ?? branch_id();
        assert_active_branch($branch);
        must($notes !== '', 'Please state what must be investigated.');
        $rec = self::latestRecord($date, $branch);
        must($rec && in_array($rec['status'], ['Awaiting Manager Review', 'Escalated to COO'], true), 'This business date is not awaiting review.');
        tx(function () use ($rec, $notes, $branch) {
            update('eod_records', ['status' => 'Variance Investigation', 'reviewed_by' => uid(), 'reviewed_at' => now(), 'review_notes' => $notes], 'id = :id', ['id' => $rec['id']]);
            audit('EOD returned for investigation', 'End of Day', 'eod_record', $rec['id'], $rec['status'], 'Variance Investigation', $notes, '', null, null, $branch);
        });
    }

    public static function requestReopen(string $date, string $reason, ?int $branch = null): int
    {
        require_perm('eod.reopen');
        $branch = $branch ?? branch_id();
        assert_active_branch($branch);
        must($reason !== '', 'A reason is required to request reopening a closed business date.');
        $day = self::day($date, $branch);
        must($day && $day['status'] === 'Closed', 'Only a closed business date can be reopened.');
        must(current_bd($branch) === null, 'Another business day is currently open in this branch. Close it before reopening an earlier date.');
        must($date === val('SELECT MAX(business_date) FROM business_days WHERE branch_id = ?', [$branch]), 'Only the most recent closed business date can be reopened.');
        must(!val("SELECT COUNT(*) FROM eod_reopen_requests WHERE branch_id = ? AND business_date = ? AND status = 'Pending'", [$branch, $date]), 'A reopen request for this date is already pending.');
        $id = insert('eod_reopen_requests', ['branch_id' => $branch, 'business_date' => $date, 'requested_by' => uid(), 'requested_at' => now(), 'reason' => $reason]);
        audit('EOD reopen requested', 'End of Day', 'eod_reopen_request', $id, null, $date, $reason, '', null, null, $branch);
        return $id;
    }

    public static function reopenAuthority(): string
    {
        return setting('reopen_authority') === 'coo' ? 'COO' : 'Branch Manager';
    }

    public static function decideReopen(int $reqId, bool $approve, string $notes): void
    {
        require_perm('eod.approve');
        $r = row("SELECT * FROM eod_reopen_requests WHERE id = ? AND status = 'Pending'", [$reqId]);
        must((bool)$r, 'That request is no longer pending.');
        $branch = (int)$r['branch_id'];
        assert_active_branch($branch);
        must((int)$r['requested_by'] !== (int)uid(), 'Separation of duties: you cannot authorize your own reopening request.');
        if ($approve && self::reopenAuthority() === 'COO') must(can('authority.institution'), 'Reopening a closed business date requires COO authorization under the current policy.');
        tx(function () use ($r, $approve, $notes, $reqId, $branch) {
            update('eod_reopen_requests', ['status' => $approve ? 'Approved' : 'Rejected', 'decided_by' => uid(), 'decided_at' => now(), 'decision_notes' => $notes ?: null], 'id = :id', ['id' => $reqId]);
            if ($approve) {
                must(current_bd($branch) === null, 'Another business day is currently open in this branch.');
                update('business_days', ['status' => 'Reopened'], 'branch_id = :b AND business_date = :d', ['b' => $branch, 'd' => $r['business_date']]);
                bd_changed();
            }
            audit($approve ? 'EOD reopening authorized' : 'EOD reopening rejected', 'End of Day', 'eod_reopen_request', $reqId, 'Closed', $approve ? 'Reopened' : 'Closed', $notes . ' | request: ' . $r['reason'], 'REQ-' . $reqId, null, null, $branch);
        });
    }

    /** One row per accessible branch: business date, status, tills, last closed date (COO / compliance oversight). */
    public static function overview(): array
    {
        $out = [];
        foreach (accessible_branch_ids() as $b) {
            $br = branch_row($b);
            if (!$br) continue;
            $d = current_bd($b);
            $last = val("SELECT MAX(business_date) FROM business_days WHERE branch_id = ? AND status = 'Closed'", [$b]);
            $c = $d ? self::sessionCounts($d, $b) : ['Open' => 0, 'Submitted' => 0, 'Investigation' => 0, 'Reconciled' => 0];
            $out[] = ['id' => $b, 'name' => $br['name'], 'status_active' => $br['status'], 'business_date' => $d, 'eod_status' => $d ? self::status($d, $b) : 'No day open', 'last_closed' => $last,
                'open_tills' => $c['Open'], 'unreconciled' => $c['Submitted'] + $c['Investigation'], 'variance' => $d ? (int)val('SELECT COALESCE(SUM(variance),0) FROM cashier_sessions WHERE branch_id = ? AND business_date = ?', [$b, $d]) : 0,
                'days_behind' => $last ? max(0, days_between($last, today()) - 1) : null];
        }
        return $out;
    }
}
