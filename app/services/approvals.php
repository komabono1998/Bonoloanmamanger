<?php
/**
 * Configurable approval limits and escalation (SRS v3 section 6).
 * Thresholds are set by the institution (COO / policy owner). The system ships with NONE: until a limit is configured
 * for a category, nothing is auto-escalated and administrators/COO see a warning. No monetary limit is invented here.
 */
final class Approvals
{
    public const CATEGORIES = [
        'loan' => 'Loan approval (approved principal)',
        'disbursement' => 'Disbursement authorization (gross principal)',
        'expense' => 'Expense approval',
        'waiver' => 'Penalty / interest / fee waiver',
        'reversal' => 'Payment reversal',
        'eod_variance' => 'End-of-Day cash variance (total absolute)',
    ];

    /** Branch-specific limit, else the institution default (branch 0), else null (not configured). */
    public static function limit(string $category, int $branch): ?int
    {
        $v = val('SELECT max_amount FROM approval_limits WHERE category = ? AND branch_id = ?', [$category, $branch]);
        if ($v === null) $v = val('SELECT max_amount FROM approval_limits WHERE category = ? AND branch_id = 0', [$category]);
        return $v === null ? null : (int)$v;
    }

    /** Authority level needed for this amount: 'Branch Manager' or 'COO' (escalated). */
    public static function required(string $category, int $amount, int $branch): string
    {
        $limit = self::limit($category, $branch);
        if ($limit === null) return 'Branch Manager';
        return $amount <= $limit ? 'Branch Manager' : 'COO';
    }

    /** The approval level of the current (or given) user: 'COO', 'Branch Manager' or null. */
    public static function authority(?int $userId = null): ?string
    {
        $p = user_permissions($userId);
        if (in_array('authority.institution', $p, true)) return 'COO';
        if (in_array('authority.branch', $p, true)) return 'Branch Manager';
        return null;
    }

    public static function covers(?string $have, string $need): bool
    {
        if ($have === 'COO') return true;
        return $have === 'Branch Manager' && $need === 'Branch Manager';
    }

    /** Throw unless the user's authority covers the amount. */
    public static function assert(string $category, int $amount, int $branch, string $what, ?int $userId = null): void
    {
        $need = self::required($category, $amount, $branch);
        $have = self::authority($userId);
        if (!self::covers($have, $need)) {
            if ($have === null) throw new AppError('You do not have approval authority for this action.');
            $lim = self::limit($category, $branch);
            throw new AppError("This $what of " . ugx($amount) . ' exceeds the Branch Manager limit of ' . ugx((int)$lim) . '. It must be approved by the COO (escalated).');
        }
    }

    public static function setLimit(string $category, int $branch, ?int $max): void
    {
        must(can('limits.manage') || policy_bootstrap_open(), 'You do not have permission to perform this action.');
        must(isset(self::CATEGORIES[$category]), 'Unknown approval category.');
        must($max === null || $max >= 0, 'A limit cannot be negative.');
        if ($branch) assert_branch($branch);
        tx(function () use ($category, $branch, $max) {
            $old = row('SELECT max_amount FROM approval_limits WHERE category = ? AND branch_id = ?', [$category, $branch]);
            q('INSERT INTO approval_limits(category, branch_id, max_amount, updated_by, updated_at) VALUES (?,?,?,?,?)
               ON CONFLICT (category, branch_id) DO UPDATE SET max_amount = excluded.max_amount, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
                [$category, $branch, $max, uid(), now()]);
            audit('Approval limit changed', 'Settings', 'approval_limit', $category . ($branch ? '@' . branch_name($branch) : ''), $old['max_amount'] ?? 'not set', $max === null ? 'not set' : $max);
        });
    }

    /** Categories with no institution-wide limit configured. */
    public static function unconfigured(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $k => $label) if (val('SELECT COUNT(*) FROM approval_limits WHERE category = ? AND branch_id = 0 AND max_amount IS NOT NULL', [$k]) == 0) $out[$k] = $label;
        return $out;
    }

    /**
     * Everything waiting for the current user's decision, newest first.
     * @return list<array{type:string,ref:string,detail:string,branch:string,amount:int,age:string,url:array}>
     */
    public static function inbox(): array
    {
        $items = [];
        $have = self::authority();
        $b = fn(string $col) => branch_scope($col);
        if (can('loan.approve')) {
            foreach (rows("SELECT l.id, l.loan_no, l.amount_requested, l.branch_id, l.status, l.escalated_at, l.updated_at, c.full_name FROM loans l JOIN clients c ON c.id = l.client_id
                           WHERE l.status IN ('Submitted','Under Review') AND " . $b('l.branch_id') . ' ORDER BY l.id') as $l) {
                $need = self::required('loan', (int)$l['amount_requested'], (int)$l['branch_id']);
                $esc = $l['escalated_at'] || $need === 'COO';
                if ($esc && $have !== 'COO') continue;                 // escalated items go to the COO
                $items[] = ['type' => $esc ? 'Loan (escalated)' : 'Loan approval', 'ref' => $l['loan_no'], 'detail' => $l['full_name'] . ' · ' . $l['status'], 'branch' => branch_name((int)$l['branch_id']),
                    'amount' => (int)$l['amount_requested'], 'age' => (string)($l['updated_at'] ?? ''), 'url' => ['approvals', ['id' => $l['id']]]];
            }
        }
        if (can('payment.reverse')) {
            foreach (rows("SELECT a.*, r.receipt_no, u.full_name AS req FROM approval_requests a LEFT JOIN receipts r ON r.payment_id = a.ref_id JOIN users u ON u.id = a.requested_by
                           WHERE a.type = 'payment_reversal' AND a.status = 'Pending' AND " . $b('a.branch_id')) as $a) {
                if ($a['required_authority'] === 'COO' && $have !== 'COO') continue;
                $items[] = ['type' => 'Payment reversal', 'ref' => $a['receipt_no'] ?: '#' . $a['ref_id'], 'detail' => $a['req'] . ': ' . $a['reason'], 'branch' => branch_name((int)$a['branch_id']),
                    'amount' => (int)$a['amount'], 'age' => $a['requested_at'], 'url' => ['payments', ['tab' => 'requests']]];
            }
        }
        if (can('expense.approve')) {
            foreach (rows("SELECT e.*, c.name AS cat FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.status = 'Pending' AND " . $b('e.branch_id')) as $e) {
                if ($e['required_authority'] === 'COO' && $have !== 'COO') continue;
                $items[] = ['type' => 'Expense', 'ref' => $e['expense_no'], 'detail' => $e['cat'] . ': ' . $e['description'], 'branch' => branch_name((int)$e['branch_id']), 'amount' => (int)$e['amount'],
                    'age' => $e['created_at'], 'url' => ['cashbook', ['tab' => 'expenses', 'status' => 'Pending']]];
            }
        }
        if (can('till.reconcile')) {
            foreach (rows("SELECT s.*, u.full_name AS cashier FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.status = 'Submitted' AND s.cashier_id <> ? AND " . $b('s.branch_id'), [uid()]) as $s) {
                $items[] = ['type' => 'Till reconciliation', 'ref' => $s['session_no'], 'detail' => $s['cashier'] . ' · variance ' . ugx((int)$s['variance']), 'branch' => branch_name((int)$s['branch_id']),
                    'amount' => abs((int)$s['variance']), 'age' => (string)$s['closed_at'], 'url' => ['till', ['tab' => 'sessions', 'session' => $s['id']]]];
            }
        }
        if (can('eod.approve')) {
            foreach (rows("SELECT e.*, u.full_name AS sub FROM eod_records e LEFT JOIN users u ON u.id = e.submitted_by WHERE e.status IN ('Awaiting Manager Review','Escalated to COO') AND e.submitted_by <> ? AND " . $b('e.branch_id'), [uid()]) as $e) {
                if ($e['status'] === 'Escalated to COO' && $have !== 'COO') continue;
                $items[] = ['type' => $e['status'] === 'Escalated to COO' ? 'EOD (escalated)' : 'End-of-Day', 'ref' => $e['business_date'], 'detail' => 'Prepared by ' . $e['sub'], 'branch' => branch_name((int)$e['branch_id']), 'amount' => 0,
                    'age' => (string)$e['submitted_at'], 'url' => ['eod', ['branch' => $e['branch_id']]]];
            }
            foreach (rows("SELECT q.*, u.full_name AS req FROM eod_reopen_requests q JOIN users u ON u.id = q.requested_by WHERE q.status = 'Pending' AND q.requested_by <> ? AND " . $b('q.branch_id'), [uid()]) as $q) {
                $items[] = ['type' => 'EOD reopen request', 'ref' => $q['business_date'], 'detail' => $q['req'] . ': ' . $q['reason'], 'branch' => branch_name((int)$q['branch_id']), 'amount' => 0,
                    'age' => $q['requested_at'], 'url' => ['eod', ['branch' => $q['branch_id']]]];
            }
        }
        if (can('backup.authorize')) {
            foreach (rows("SELECT a.*, u.full_name AS req FROM approval_requests a JOIN users u ON u.id = a.requested_by WHERE a.type = 'restore' AND a.status = 'Pending'") as $a) {
                $items[] = ['type' => 'System recovery (restore)', 'ref' => $a['reason'], 'detail' => 'Requested by ' . $a['req'], 'branch' => 'Institution', 'amount' => 0, 'age' => $a['requested_at'], 'url' => ['backup', []]];
            }
        }
        return $items;
    }
}
