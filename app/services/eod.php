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
        assert_active_branch($branch);
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
        $b = Backup::create('EOD', $date);
        update('eod_records', ['backup_status' => $b['ok'] ? 'Verified' : 'FAILED: ' . $b['message'], 'backup_file' => $b['file'] ?? null], 'id = :id', ['id' => $rec['id']]);
        // the system date moves on: the next working day opens automatically
        if (setting('eod_auto_open_next') === '1') {
            try { self::openDayCore(self::suggestNextDate($branch), false, $branch); }
            catch (Throwable $e) { error_log('Next business day was not opened automatically: ' . $e->getMessage()); }
        }
        $b['next_date'] = current_bd($branch);
        return $b;
    }

    public static function returnForInvestigation(string $date, string $notes, ?int $branch = null): void
    {
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
