<?php
/** Business-date control and End-of-Day (EOD) reconciliation workflow. */

/** The business date currently open for posting (distinct from the computer clock), or null. */
function current_bd(): ?string
{
    static $cache = null;
    if (isset($GLOBALS['__bd_dirty'])) { $cache = null; unset($GLOBALS['__bd_dirty']); }
    if ($cache === null) {
        $cache = val("SELECT business_date FROM business_days WHERE status IN ('Open','Reopened') ORDER BY business_date DESC LIMIT 1") ?: '';
    }
    return $cache === '' ? null : $cache;
}

function bd_changed(): void { $GLOBALS['__bd_dirty'] = true; }

/** Date to use for "as at" calculations (overdue, due today): the open business date, else the clock. */
function reporting_date(): string
{
    return current_bd() ?? today();
}

/** Date new transactions are posted against. Throws when no business day is open. */
function posting_date(): string
{
    $d = current_bd();
    if ($d) return $d;
    $last = val('SELECT MAX(business_date) FROM business_days');
    if ($last) throw new AppError('This business date is already closed. A new business day must be opened before posting transactions.');
    throw new AppError('No business day is open. An authorized user must start the business day first.');
}

function is_working_day(string $date): bool
{
    $o = row('SELECT is_working FROM business_calendar WHERE date = ?', [$date]);
    if ($o) return (bool)$o['is_working'];
    $days = array_map('intval', array_filter(explode(',', setting('working_days'))));
    return in_array((int)date('N', strtotime($date)), $days, true);
}

final class Eod
{
    public static function day(string $date): ?array
    {
        return row('SELECT * FROM business_days WHERE business_date = ?', [$date]);
    }

    public static function latestRecord(string $date): ?array
    {
        return row('SELECT * FROM eod_records WHERE business_date = ? ORDER BY version DESC LIMIT 1', [$date]);
    }

    public static function suggestNextDate(): string
    {
        $last = val('SELECT MAX(business_date) FROM business_days');
        $d = $last ? Calc::addDays($last, 1) : today();
        for ($i = 0; $i < 14 && !is_working_day($d); $i++) $d = Calc::addDays($d, 1);
        return $d;
    }

    public static function openDay(string $date, bool $allowNonWorking = false): void
    {
        require_perm('eod.manage');
        must(is_date($date), 'Business date is not a valid date.');
        tx(function () use ($date, $allowNonWorking) {
            $open = current_bd();
            must($open === null, "Business date " . fmt_date($open) . " is still open. Complete its End-of-Day before starting a new day.");
            $last = val('SELECT MAX(business_date) FROM business_days');
            must(!$last || $date > $last, 'The new business date must be after the last closed date (' . fmt_date($last) . ').');
            must($allowNonWorking || is_working_day($date), 'That date is not a working day in the business calendar. Tick the confirmation to open it anyway.');
            insert('business_days', ['business_date' => $date, 'status' => 'Open', 'opened_by' => uid(), 'opened_at' => now()]);
            bd_changed();
            audit('Business day opened', 'End of Day', 'business_day', $date, null, 'Open');
        });
        Loans::refreshStatuses();
    }

    /** Derived EOD status for display. */
    public static function status(string $date): string
    {
        $day = self::day($date);
        if (!$day) return 'Not Started';
        if ($day['status'] === 'Closed') return 'Closed';
        if ($day['status'] === 'Reopened') {
            $rec = self::latestRecord($date);
            if ($rec && $rec['status'] === 'Awaiting Manager Review') return 'Awaiting Manager Review';
            return 'Reopened by Authorization';
        }
        $rec = self::latestRecord($date);
        if ($rec && $rec['status'] === 'Awaiting Manager Review') return 'Awaiting Manager Review';
        $c = self::sessionCounts($date);
        if ($c['Investigation'] > 0) return 'Variance Investigation';
        if ($rec && $rec['status'] === 'Variance Investigation' && $c['Open'] + $c['Submitted'] === 0 && $c['Investigation'] === 0) {
            return 'Variance Investigation';
        }
        if ($c['Submitted'] > 0 || ($c['Open'] === 0 && $c['Reconciled'] > 0 && !$rec)) return 'Awaiting Cashier Reconciliation';
        return 'In Progress';
    }

    public static function sessionCounts(string $date): array
    {
        $c = ['Open' => 0, 'Submitted' => 0, 'Investigation' => 0, 'Reconciled' => 0];
        foreach (rows('SELECT status, COUNT(*) n FROM cashier_sessions WHERE business_date = ? GROUP BY status', [$date]) as $r) {
            $c[$r['status']] = (int)$r['n'];
        }
        return $c;
    }

    /** Outstanding items that prevent or qualify closing the day. */
    public static function checklist(string $date): array
    {
        $c = self::sessionCounts($date);
        $items = [];
        $items[] = ['ok' => $c['Open'] === 0, 'label' => 'All cashier tills closed and counted', 'detail' => $c['Open'] . ' till(s) still open', 'blocking' => true];
        $items[] = ['ok' => $c['Submitted'] === 0, 'label' => 'All submitted cashier counts reconciled', 'detail' => $c['Submitted'] . ' awaiting reconciliation', 'blocking' => true];
        $items[] = ['ok' => $c['Investigation'] === 0, 'label' => 'No sessions under variance investigation', 'detail' => $c['Investigation'] . ' under investigation', 'blocking' => true];
        $pend = (int)val("SELECT COUNT(*) FROM expenses WHERE status IN ('Pending','Approved')");
        $items[] = ['ok' => $pend === 0, 'label' => 'No unpaid / unapproved expenses', 'detail' => $pend . ' pending (warning only)', 'blocking' => false];
        $unrec = (int)val('SELECT COUNT(*) FROM cashier_sessions WHERE business_date = ? AND variance <> 0 AND status = ?', [$date, 'Reconciled']);
        $items[] = ['ok' => $unrec === 0, 'label' => 'Cash variances', 'detail' => $unrec . ' reconciled session(s) carry a variance (approved by manager)', 'blocking' => false];
        return $items;
    }

    public static function blockers(string $date): array
    {
        if (setting('require_reconciliation') !== '1') return [];
        return array_values(array_filter(self::checklist($date), fn($i) => !$i['ok'] && $i['blocking']));
    }

    public static function summary(string $d): array
    {
        $s = [];
        // A. applications
        $s['applications'] = [
            'received' => (int)val('SELECT COUNT(*) FROM loans WHERE application_date = ?', [$d]),
            'requested' => (int)val('SELECT COALESCE(SUM(amount_requested),0) FROM loans WHERE application_date = ?', [$d]),
            'approved' => (int)val("SELECT COUNT(*) FROM loan_approvals WHERE business_date = ? AND decision = 'Approved'", [$d]),
            'rejected' => (int)val("SELECT COUNT(*) FROM loan_approvals WHERE business_date = ? AND decision = 'Rejected'", [$d]),
            'awaiting' => (int)val("SELECT COUNT(*) FROM loans WHERE status IN ('Submitted','Under Review')"),
        ];
        // B. disbursements
        $dis = row("SELECT COUNT(*) n, COALESCE(SUM(approved_principal),0) principal,
                COALESCE(SUM(processing_fee+application_fee+inspection_fee+other_deductions),0) fees, COALESCE(SUM(net_amount),0) net
                FROM disbursements WHERE business_date = ?", [$d]);
        $byM = [];
        foreach (rows('SELECT method, COALESCE(SUM(net_amount),0) amt FROM disbursements WHERE business_date = ? GROUP BY method', [$d]) as $r) $byM[$r['method']] = (int)$r['amt'];
        $s['disbursements'] = [
            'count' => (int)$dis['n'], 'principal' => (int)$dis['principal'], 'fees' => (int)$dis['fees'], 'net' => (int)$dis['net'],
            'cash' => $byM['Cash'] ?? 0, 'bank' => $byM['Bank Transfer'] ?? 0,
            'other' => array_sum($byM) - ($byM['Cash'] ?? 0) - ($byM['Bank Transfer'] ?? 0),
        ];
        // C. collections (posted payments on this business date)
        $m = [];
        foreach (rows("SELECT method, COUNT(*) n, COALESCE(SUM(amount),0) amt FROM payments WHERE business_date = ? AND status = 'Posted' GROUP BY method", [$d]) as $r) {
            $m[$r['method']] = ['n' => (int)$r['n'], 'amt' => (int)$r['amt']];
        }
        $comp = [];
        foreach (rows("SELECT a.component, COALESCE(SUM(a.amount),0) amt FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
                       WHERE p.business_date = ? AND p.status = 'Posted' GROUP BY a.component", [$d]) as $r) $comp[$r['component']] = (int)$r['amt'];
        $s['collections'] = [
            'count' => array_sum(array_column($m, 'n')),
            'total' => array_sum(array_column($m, 'amt')),
            'cash' => $m['Cash']['amt'] ?? 0, 'bank' => $m['Bank Transfer']['amt'] ?? 0, 'mobile' => $m['Mobile Money']['amt'] ?? 0,
            'other' => array_sum(array_column($m, 'amt')) - ($m['Cash']['amt'] ?? 0) - ($m['Bank Transfer']['amt'] ?? 0) - ($m['Mobile Money']['amt'] ?? 0),
            'principal' => $comp['principal'] ?? 0, 'interest' => $comp['interest'] ?? 0, 'fees' => $comp['fee'] ?? 0,
            'penalties' => $comp['penalty'] ?? 0, 'credits' => $comp['credit'] ?? 0,
            'reversed_count' => (int)val("SELECT COUNT(*) FROM payments WHERE reversal_business_date = ?", [$d]),
            'reversed_amount' => (int)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE reversal_business_date = ?", [$d]),
        ];
        // D. expenses
        $ex = row("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN method='Cash' THEN amount END),0) cash,
                COALESCE(SUM(CASE WHEN method<>'Cash' THEN amount END),0) noncash FROM expenses WHERE paid_business_date = ? AND status = 'Paid'", [$d]);
        $cats = [];
        foreach (rows("SELECT c.name, COALESCE(SUM(e.amount),0) amt FROM expenses e JOIN expense_categories c ON c.id = e.category_id
                       WHERE e.paid_business_date = ? AND e.status = 'Paid' GROUP BY c.name", [$d]) as $r) $cats[$r['name']] = (int)$r['amt'];
        $s['expenses'] = [
            'count' => (int)$ex['n'], 'cash' => (int)$ex['cash'], 'noncash' => (int)$ex['noncash'], 'categories' => $cats,
            'pending' => (int)val("SELECT COUNT(*) FROM expenses WHERE status IN ('Pending','Approved')"),
            'authorized' => (int)val("SELECT COUNT(*) FROM expenses WHERE paid_business_date = ? AND status='Paid' AND approved_by IS NOT NULL", [$d]),
        ];
        // E. cash movements
        $cm = function (string $cat, int $dir) use ($d): int {
            return (int)val('SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE business_date = ? AND is_cash = 1 AND category = ? AND direction = ?', [$d, $cat, $dir]);
        };
        $opening = $cm('float', 1);
        $s['cash'] = [
            'opening' => $opening, 'collections' => $cm('collection', 1), 'disbursements' => $cm('disbursement', -1),
            'expenses' => $cm('expense', -1), 'transfers_in' => $cm('transfer', 1), 'transfers_out' => $cm('transfer', -1),
            'reversals_in' => $cm('reversal', 1), 'reversals_out' => $cm('reversal', -1),
        ];
        $c = $s['cash'];
        $s['cash']['expected'] = $c['opening'] + $c['collections'] + $c['transfers_in'] + $c['reversals_in']
            - $c['disbursements'] - $c['expenses'] - $c['transfers_out'] - $c['reversals_out'];
        $cs = row("SELECT COALESCE(SUM(counted_cash),0) counted, COALESCE(SUM(variance),0) var, COUNT(*) n FROM cashier_sessions WHERE business_date = ?", [$d]);
        $s['cash']['actual'] = (int)$cs['counted'];
        $s['cash']['variance'] = (int)$cs['var'];
        $s['cash']['sessions'] = (int)$cs['n'];
        // F. portfolio
        $s['portfolio'] = [
            'opened' => (int)val('SELECT COUNT(*) FROM disbursements WHERE business_date = ?', [$d]),
            'closed' => (int)val('SELECT COUNT(*) FROM loans WHERE closed_date = ?', [$d]),
            'becoming_overdue' => (int)val("SELECT COUNT(*) FROM loans WHERE overdue_since IS NOT NULL AND date(overdue_since, '+1 day') = ?", [$d]),
            'principal_outstanding' => (int)val("SELECT COALESCE(SUM(b.principal_out),0) FROM v_loan_balances b JOIN loans l ON l.id=b.loan_id WHERE l.status IN ('Active','Overdue')"),
            'contractual_outstanding' => (int)val("SELECT COALESCE(SUM(b.principal_out+b.interest_out),0) FROM v_loan_balances b JOIN loans l ON l.id=b.loan_id WHERE l.status IN ('Active','Overdue')"),
            'penalties_accrued' => (int)val('SELECT COALESCE(SUM(amount),0) FROM penalties WHERE business_date = ?', [$d]),
            'penalties_collected' => $comp['penalty'] ?? 0,
        ];
        return $s;
    }

    public static function exceptions(string $date): array
    {
        $ex = [];
        foreach (rows("SELECT session_no, variance, variance_reason, status FROM cashier_sessions WHERE business_date = ? AND (variance <> 0 OR status <> 'Reconciled')", [$date]) as $r) {
            $ex[] = ['type' => 'Cashier session', 'ref' => $r['session_no'], 'detail' => $r['status'] . ($r['variance'] ? ' · variance ' . ugx($r['variance']) . ' · ' . $r['variance_reason'] : '')];
        }
        foreach (rows("SELECT p.id, r.receipt_no, p.amount, p.reversal_reason FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
                       WHERE p.reversal_business_date = ? OR (p.business_date = ? AND p.status = 'Reversed')", [$date, $date]) as $r) {
            $ex[] = ['type' => 'Reversed payment', 'ref' => $r['receipt_no'] ?: ('#' . $r['id']), 'detail' => ugx($r['amount']) . ' · ' . $r['reversal_reason']];
        }
        foreach (rows("SELECT expense_no, amount, status FROM expenses WHERE status IN ('Pending','Approved') OR (paid_business_date = ? AND status = 'Reversed')", [$date]) as $r) {
            $ex[] = ['type' => 'Expense', 'ref' => $r['expense_no'], 'detail' => $r['status'] . ' · ' . ugx($r['amount'])];
        }
        return $ex;
    }

    public static function submit(string $date, string $notes = ''): int
    {
        require_perm('eod.manage');
        $day = self::day($date);
        must($day && $day['status'] !== 'Closed', 'This business date is already closed.');
        $blockers = self::blockers($date);
        must(!$blockers, 'EOD cannot be submitted while required reconciliations are outstanding: ' . implode('; ', array_map(fn($b) => $b['label'] . ' (' . $b['detail'] . ')', $blockers)) . '.');
        $rec = self::latestRecord($date);
        must(!$rec || $rec['status'] !== 'Awaiting Manager Review', 'This business date has already been submitted for review.');
        return tx(function () use ($date, $notes, $rec) {
            Penalty::accrue($date);
            Loans::refreshStatuses();
            $version = $rec ? $rec['version'] + 1 : 1;
            $summary = self::summary($date);
            $id = insert('eod_records', [
                'business_date' => $date, 'version' => $version, 'status' => 'Awaiting Manager Review',
                'summary_json' => json_encode($summary), 'submitted_by' => uid(), 'submitted_at' => now(), 'notes' => $notes ?: null,
            ]);
            audit('EOD submitted for review', 'End of Day', 'eod_record', $id, null, ['date' => $date, 'version' => $version]);
            return $id;
        });
    }

    /** Manager approval: closes and locks the business date, then takes a verified backup. */
    public static function approve(string $date, string $notes = ''): array
    {
        require_perm('eod.approve');
        $rec = self::latestRecord($date);
        must($rec && $rec['status'] === 'Awaiting Manager Review', 'This business date has not been submitted for approval.');
        $blockers = self::blockers($date);
        must(!$blockers, 'EOD cannot be approved while required reconciliations are outstanding.');
        tx(function () use ($date, $notes, $rec) {
            // re-take the snapshot so the closing record reflects the final books
            update('eod_records', [
                'status' => 'Closed', 'reviewed_by' => uid(), 'reviewed_at' => now(), 'review_notes' => $notes ?: null,
                'summary_json' => json_encode(self::summary($date)),
            ], 'id = :id', ['id' => $rec['id']]);
            update('business_days', ['status' => 'Closed', 'closed_by' => uid(), 'closed_at' => now()], 'business_date = :d', ['d' => $date]);
            bd_changed();
            audit('EOD approved and business date locked', 'End of Day', 'eod_record', $rec['id'], 'Awaiting Manager Review', 'Closed', $notes);
        });
        $b = Backup::create('EOD', $date);
        update('eod_records', ['backup_status' => $b['ok'] ? 'Verified' : 'FAILED: ' . $b['message'], 'backup_file' => $b['file'] ?? null], 'id = :id', ['id' => $rec['id']]);
        return $b;
    }

    public static function returnForInvestigation(string $date, string $notes): void
    {
        require_perm('eod.approve');
        must($notes !== '', 'Please state what must be investigated.');
        $rec = self::latestRecord($date);
        must($rec && $rec['status'] === 'Awaiting Manager Review', 'This business date is not awaiting manager review.');
        tx(function () use ($rec, $notes, $date) {
            update('eod_records', ['status' => 'Variance Investigation', 'reviewed_by' => uid(), 'reviewed_at' => now(), 'review_notes' => $notes], 'id = :id', ['id' => $rec['id']]);
            audit('EOD returned for investigation', 'End of Day', 'eod_record', $rec['id'], 'Awaiting Manager Review', 'Variance Investigation', $notes);
        });
    }

    public static function requestReopen(string $date, string $reason): int
    {
        require_perm('eod.reopen');
        must($reason !== '', 'A reason is required to request reopening a closed business date.');
        $day = self::day($date);
        must($day && $day['status'] === 'Closed', 'Only a closed business date can be reopened.');
        must(current_bd() === null, 'Another business day is currently open. Close it before reopening an earlier date.');
        must($date === val('SELECT MAX(business_date) FROM business_days'), 'Only the most recent closed business date can be reopened.');
        must(!val("SELECT COUNT(*) FROM eod_reopen_requests WHERE business_date = ? AND status = 'Pending'", [$date]), 'A reopen request for this date is already pending.');
        $id = insert('eod_reopen_requests', ['business_date' => $date, 'requested_by' => uid(), 'requested_at' => now(), 'reason' => $reason]);
        audit('EOD reopen requested', 'End of Day', 'eod_reopen_request', $id, null, $date, $reason);
        return $id;
    }

    public static function decideReopen(int $reqId, bool $approve, string $notes): void
    {
        require_perm('eod.approve');
        $r = row("SELECT * FROM eod_reopen_requests WHERE id = ? AND status = 'Pending'", [$reqId]);
        must((bool)$r, 'That request is no longer pending.');
        tx(function () use ($r, $approve, $notes, $reqId) {
            update('eod_reopen_requests', ['status' => $approve ? 'Approved' : 'Rejected', 'decided_by' => uid(), 'decided_at' => now(), 'decision_notes' => $notes ?: null], 'id = :id', ['id' => $reqId]);
            if ($approve) {
                must(current_bd() === null, 'Another business day is currently open.');
                update('business_days', ['status' => 'Reopened'], 'business_date = :d', ['d' => $r['business_date']]);
                bd_changed();
            }
            audit($approve ? 'EOD reopening authorized' : 'EOD reopening rejected', 'End of Day', 'eod_reopen_request', $reqId, 'Closed', $approve ? 'Reopened' : 'Closed', $notes . ' | request: ' . $r['reason'], 'REQ-' . $reqId);
        });
    }
}
