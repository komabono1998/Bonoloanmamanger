<?php
/** Cashier sessions (tills), cash movements, transfers, expenses and cash reconciliation. */

final class Cash
{
    public static function accountForMethod(string $method, ?int $branch = null): ?int
    {
        $name = ['Bank Transfer' => 'Bank Account', 'Mobile Money' => 'Mobile Money'][$method] ?? null;
        return $name ? (int)val('SELECT id FROM cash_accounts WHERE branch_id = ? AND name = ?', [$branch ?? branch_id(), $name]) ?: null : null;
    }

    public static function session(int $id): array
    {
        $s = row('SELECT s.*, u.full_name AS cashier_name FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.id = ?', [$id]);
        must((bool)$s, 'Cashier session does not exist.');
        assert_branch((int)$s['branch_id']);
        return $s;
    }

    /** The user's currently open till on the open business date. */
    public static function mySession(?int $userId = null): ?array
    {
        $branch = branch_id();
        $bd = current_bd($branch);
        if (!$bd) return null;
        return row("SELECT * FROM cashier_sessions WHERE branch_id = ? AND cashier_id = ? AND business_date = ? AND status = 'Open' ORDER BY id DESC LIMIT 1", [$branch, $userId ?? uid(), $bd]);
    }

    public static function requireOpenSession(): array
    {
        $s = self::mySession();
        must((bool)$s, 'You do not have an open cashier session. Open your till (with the opening float) before handling cash.');
        return $s;
    }

    /** Cash that should physically be in the till. Non-cash movements never change it. */
    public static function expected(int $sessionId): int
    {
        return (int)val('SELECT COALESCE(SUM(direction * amount),0) FROM cash_movements WHERE session_id = ? AND is_cash = 1', [$sessionId]);
    }

    /**
     * Post a cash/bank/mobile movement. The movement table is append-only.
     * @param array{session:?int,direction:int,amount:int,category:string,method:string,ref_type?:?string,ref_id?:?int,reference?:?string,description?:?string,bd?:?string,account_id?:?int,is_cash?:?bool} $m
     */
    public static function record(array $m): int
    {
        $sid = $m['session'] ?? null;
        $branch = $m['branch'] ?? ($sid ? (int)val('SELECT branch_id FROM cashier_sessions WHERE id = ?', [$sid]) : branch_id());
        $bd = $m['bd'] ?? posting_date($branch);
        if ($sid) {
            $sr = row('SELECT status, branch_id FROM cashier_sessions WHERE id = ?', [$sid]);
            must($sr['status'] === 'Open', 'This cashier session has already been closed.');
            must((int)$sr['branch_id'] === (int)$branch, 'The cashier session belongs to a different branch.');
        }
        $isCash = $m['is_cash'] ?? ($m['method'] === 'Cash');
        return insert('cash_movements', [
            'branch_id' => $branch, 'session_id' => $sid, 'account_id' => $m['account_id'] ?? ($isCash ? null : self::accountForMethod($m['method'], $branch)),
            'business_date' => $bd, 'created_at' => now(), 'direction' => $m['direction'], 'amount' => $m['amount'],
            'category' => $m['category'], 'method' => $m['method'], 'is_cash' => $isCash ? 1 : 0,
            'ref_type' => $m['ref_type'] ?? null, 'ref_id' => $m['ref_id'] ?? null, 'reference' => $m['reference'] ?? null,
            'description' => $m['description'] ?? null, 'user_id' => uid(),
        ]);
    }

    /** Session to carry a cash reversal: the original if still open, otherwise the user's own open till. */
    public static function sessionForReversal(?int $originalSessionId): int
    {
        if ($originalSessionId && val('SELECT status FROM cashier_sessions WHERE id = ?', [$originalSessionId]) === 'Open') return $originalSessionId;
        $s = self::mySession();
        must((bool)$s, 'The original till is closed. Open your own cashier session so the cash reversal can be recorded.');
        return (int)$s['id'];
    }

    // ---------------------------------------------------------------- sessions
    public static function lastClosingCash(int $userId): ?int
    {
        $v = val("SELECT counted_cash FROM cashier_sessions WHERE cashier_id = ? AND branch_id = ? AND status = 'Reconciled' ORDER BY id DESC LIMIT 1", [$userId, branch_id()]);
        return $v === null ? null : (int)$v;
    }

    public static function openSession(int $float, bool $confirmed, string $note = ''): int
    {
        require_perm('till.use');
        must($float >= 0, 'Opening float cannot be negative.');
        must($confirmed, 'Please confirm that the opening balance has been counted.');
        $branch = branch_id();
        assert_active_branch($branch);
        return tx(function () use ($float, $note, $branch) {
            $bd = posting_date($branch);
            $active = val("SELECT COUNT(*) FROM cashier_sessions WHERE branch_id = ? AND cashier_id = ? AND business_date = ? AND status IN ('Open','Submitted','Investigation')", [$branch, uid(), $bd]);
            must(!$active, 'You already have an active cashier session for this business date.');
            $last = self::lastClosingCash(uid());
            if ($last !== null && $last !== $float) {
                must($note !== '', 'The opening float (' . ugx($float) . ') differs from your last closing cash (' . ugx($last) . '). Please explain the difference.');
            }
            $id = insert('cashier_sessions', [
                'branch_id' => $branch, 'session_no' => 'TL-' . branch_row($branch)['code'] . '-' . str_replace('-', '', $bd) . '-' . str_pad((string)next_seq('till-' . $branch . '-' . $bd), 3, '0', STR_PAD_LEFT),
                'cashier_id' => uid(), 'business_date' => $bd, 'opened_at' => now(), 'opening_float' => $float, 'opening_confirmed' => 1, 'status' => 'Open',
            ]);
            if ($float > 0) {
                self::record(['session' => $id, 'direction' => 1, 'amount' => $float, 'category' => 'float', 'method' => 'Cash',
                    'ref_type' => 'session', 'ref_id' => $id, 'description' => 'Opening float', 'bd' => $bd, 'branch' => $branch]);
            }
            audit('Cashier session opened', 'Cashier', 'cashier_session', $id, $last, $float, $note, '', null, null, $branch);
            return $id;
        });
    }

    /** Cashier counts the till and submits it for reconciliation. */
    public static function submitCount(int $sessionId, int $counted, string $reason, array $denoms = []): void
    {
        $s = self::session($sessionId);
        must((int)$s['cashier_id'] === (int)uid() || can('till.reconcile'), 'You do not have permission to perform this action.');
        must(in_array($s['status'], ['Open', 'Investigation'], true), 'This cashier session has already been closed.');
        must($counted >= 0, 'Counted cash cannot be negative.');
        tx(function () use ($s, $sessionId, $counted, $reason, $denoms) {
            $expected = self::expected($sessionId);
            $variance = $counted - $expected;
            must($variance === 0 || $reason !== '', 'Cash variance requires an explanation.');
            insert('cash_counts', ['session_id' => $sessionId, 'counted_by' => uid(), 'counted_at' => now(), 'denominations' => $denoms ? json_encode($denoms) : null,
                'expected' => $expected, 'counted' => $counted, 'variance' => $variance, 'reason' => $reason ?: null]);
            update('cashier_sessions', ['status' => 'Submitted', 'closed_at' => now(), 'closed_by' => uid(), 'expected_cash' => $expected,
                'counted_cash' => $counted, 'variance' => $variance, 'variance_reason' => $reason ?: null], 'id = :id', ['id' => $sessionId]);
            audit('Cashier session counted and submitted', 'Cashier', 'cashier_session', $sessionId, ugx($expected), ugx($counted), $reason ?: 'No variance');
        });
    }

    public static function reconcile(int $sessionId, string $notes): void
    {
        require_perm('till.reconcile');
        $s = self::session($sessionId);
        must($s['status'] === 'Submitted', 'Only a submitted cashier count can be reconciled.');
        must((int)$s['variance'] === 0 || $notes !== '', 'Cash variance requires an explanation / manager note.');
        if ((int)$s['variance'] !== 0) Approvals::assert('eod_variance', abs((int)$s['variance']), (int)$s['branch_id'], 'cash variance');
        must((int)$s['cashier_id'] !== (int)uid(), 'Separation of duties: a cashier cannot approve their own reconciliation.');
        tx(function () use ($sessionId, $s, $notes) {
            update('cashier_sessions', ['status' => 'Reconciled', 'reconciled_by' => uid(), 'reconciled_at' => now(), 'reconcile_notes' => $notes ?: null], 'id = :id', ['id' => $sessionId]);
            audit('Cashier session reconciled', 'Cashier', 'cashier_session', $sessionId, $s['status'], 'Reconciled', $notes, 'variance ' . $s['variance']);
        });
    }

    public static function investigate(int $sessionId, string $notes): void
    {
        require_perm('till.reconcile');
        $s = self::session($sessionId);
        must(in_array($s['status'], ['Submitted', 'Reconciled'], true), 'This session cannot be sent for investigation.');
        must($notes !== '', 'Please state what must be investigated.');
        must($s['status'] !== 'Reconciled' || current_bd((int)$s['branch_id']) === $s['business_date'], 'This business date is already closed.');
        tx(function () use ($sessionId, $s, $notes) {
            update('cashier_sessions', ['status' => 'Investigation', 'reconcile_notes' => $notes], 'id = :id', ['id' => $sessionId]);
            audit('Cashier session sent for investigation', 'Cashier', 'cashier_session', $sessionId, $s['status'], 'Investigation', $notes);
        });
    }

    /** Put a submitted/investigated till back to Open so a missed transaction can be posted. Original counts are kept. */
    public static function reopenSession(int $sessionId, string $reason): void
    {
        require_perm('till.reconcile');
        $s = self::session($sessionId);
        must(in_array($s['status'], ['Submitted', 'Investigation'], true), 'Only a submitted or investigated session can be reopened.');
        must($reason !== '', 'A reason is required to reopen a cashier session.');
        must(current_bd((int)$s['branch_id']) === $s['business_date'], 'This business date is already closed.');
        tx(function () use ($sessionId, $s, $reason) {
            update('cashier_sessions', ['status' => 'Open'], 'id = :id', ['id' => $sessionId]);
            audit('Cashier session reopened', 'Cashier', 'cashier_session', $sessionId, $s['status'], 'Open', $reason);
        });
    }

    // ---------------------------------------------------------------- transfers
    /**
     * @param string $type to_account | from_account | to_session
     */
    public static function transfer(string $type, int $amount, ?int $accountId, ?int $otherSessionId, string $reason): int
    {
        require_perm('till.use');
        must($amount > 0, 'Transfer amount must be greater than zero.');
        must($reason !== '', 'A reason is required for a cash transfer.');
        return tx(function () use ($type, $amount, $accountId, $otherSessionId, $reason) {
            $me = self::requireOpenSession();
            $br = (int)$me['branch_id'];
            $bd = posting_date($br);
            $row = ['branch_id' => $br, 'business_date' => $bd, 'amount' => $amount, 'reason' => $reason, 'created_by' => uid(), 'created_at' => now()];
            $base = ['category' => 'transfer', 'method' => 'Cash', 'description' => $reason, 'bd' => $bd, 'ref_type' => 'transfer', 'branch' => $br];
            if ($type === 'to_account' || $type === 'from_account') {
                $acc = row('SELECT * FROM cash_accounts WHERE id = ? AND active = 1 AND branch_id = ?', [$accountId, $br]);
                must((bool)$acc, 'Select a valid cash account.');
                if ($type === 'to_account') {
                    must(self::expected((int)$me['id']) >= $amount, 'Transfer exceeds the cash expected in your till.');
                    $row += ['from_session_id' => $me['id'], 'to_account_id' => $accountId];
                    $tid = insert('cash_transfers', $row);
                    self::record($base + ['session' => $me['id'], 'direction' => -1, 'amount' => $amount, 'ref_id' => $tid]);
                    self::record($base + ['session' => null, 'direction' => 1, 'amount' => $amount, 'ref_id' => $tid, 'account_id' => $accountId, 'is_cash' => false]);
                } else {
                    $row += ['to_session_id' => $me['id'], 'from_account_id' => $accountId];
                    $tid = insert('cash_transfers', $row);
                    self::record($base + ['session' => null, 'direction' => -1, 'amount' => $amount, 'ref_id' => $tid, 'account_id' => $accountId, 'is_cash' => false]);
                    self::record($base + ['session' => $me['id'], 'direction' => 1, 'amount' => $amount, 'ref_id' => $tid]);
                }
            } else {
                $other = self::session((int)$otherSessionId);
                must($other['status'] === 'Open' && $other['business_date'] === $bd && (int)$other['branch_id'] === $br, 'The other cashier session is not open in this branch.');
                must((int)$other['id'] !== (int)$me['id'], 'Choose a different cashier session.');
                must(self::expected((int)$me['id']) >= $amount, 'Transfer exceeds the cash expected in your till.');
                $row += ['from_session_id' => $me['id'], 'to_session_id' => $other['id']];
                $tid = insert('cash_transfers', $row);
                self::record($base + ['session' => $me['id'], 'direction' => -1, 'amount' => $amount, 'ref_id' => $tid]);
                self::record($base + ['session' => $other['id'], 'direction' => 1, 'amount' => $amount, 'ref_id' => $tid]);
            }
            audit('Cash transfer', 'Cashier', 'cash_transfer', $tid, null, ['type' => $type, 'amount' => $amount], $reason, '', null, null, $br);
            return $tid;
        });
    }

    // ---------------------------------------------------------------- expenses
    public static function expense(int $id): array
    {
        $e = row('SELECT * FROM expenses WHERE id = ?', [$id]);
        must((bool)$e, 'Expense does not exist.');
        assert_branch((int)$e['branch_id']);
        return $e;
    }

    public static function addExpense(array $in): int
    {
        require_perm('expense.create');
        $branch = branch_id();
        assert_active_branch($branch);
        $amount = parse_money($in['amount'] ?? '', 'Expense amount');
        $desc = clean($in['description'] ?? '');
        must($desc !== '', 'Expense description is required.');
        $cat = row('SELECT * FROM expense_categories WHERE id = ? AND active = 1', [(int)($in['category_id'] ?? 0)]);
        must((bool)$cat, 'Select an expense category.');
        $method = $in['method'] ?? 'Cash';
        must(in_array($method, ['Cash', 'Bank Transfer', 'Mobile Money', 'Other'], true), 'Invalid payment method.');
        $token = $in['txn_token'] ?? bin2hex(random_bytes(8));
        $needsApproval = $amount > setting_int('expense_approval_limit');
        return tx(function () use ($amount, $desc, $cat, $method, $token, $in, $needsApproval, $branch) {
            must(!val('SELECT COUNT(*) FROM expenses WHERE txn_token = ?', [$token]), 'This expense has already been posted.');
            $bd = posting_date($branch);
            $id = insert('expenses', [
                'branch_id' => $branch, 'expense_no' => 'EX-' . str_pad((string)next_seq('expense'), 6, '0', STR_PAD_LEFT), 'business_date' => $bd, 'entry_date' => today(),
                'category_id' => $cat['id'], 'description' => $desc, 'amount' => $amount, 'method' => $method,
                'reference' => trim($in['reference'] ?? '') ?: null, 'entered_by' => uid(), 'status' => 'Pending', 'txn_token' => $token, 'created_at' => now(),
                'required_authority' => $needsApproval ? Approvals::required('expense', $amount, $branch) : null,
            ]);
            audit('Expense recorded', 'Expenses', 'expense', $id, null, ugx($amount) . ' ' . $cat['name'], $desc, '', null, null, $branch);
            if ($needsApproval) {
                flash('warn', 'Expense exceeds the routine limit of ' . ugx(setting_int('expense_approval_limit')) . ' and is awaiting approval by ' . Approvals::required('expense', $amount, $branch) . '.');
                return $id;
            }
            self::pay($id, true);
            return $id;
        });
    }

    public static function approveExpense(int $id): void
    {
        require_perm('expense.approve');
        $e = self::expense($id);
        must($e['status'] === 'Pending', 'This expense is not pending approval.');
        must((int)$e['entered_by'] !== (int)uid(), 'Separation of duties: you cannot approve an expense that you entered.');
        Approvals::assert('expense', (int)$e['amount'], (int)$e['branch_id'], 'expense');
        tx(function () use ($id, $e) {
            update('expenses', ['status' => 'Approved', 'approved_by' => uid()], 'id = :id', ['id' => $id]);
            audit('Expense approved', 'Expenses', 'expense', $id, 'Pending', 'Approved', '', Approvals::authority() ?? '', null, null, (int)$e['branch_id']);
        });
    }

    public static function rejectExpense(int $id, string $reason): void
    {
        require_perm('expense.approve');
        $e = self::expense($id);
        must(in_array($e['status'], ['Pending', 'Approved'], true), 'This expense cannot be rejected.');
        must($reason !== '', 'A reason is required.');
        tx(function () use ($id, $e, $reason) {
            update('expenses', ['status' => 'Rejected', 'reversal_reason' => $reason], 'id = :id', ['id' => $id]);
            audit('Expense rejected', 'Expenses', 'expense', $id, $e['status'], 'Rejected', $reason, '', null, null, (int)$e['branch_id']);
        });
    }

    /** Pay an approved expense (or a within-limit expense immediately): posts the cash/bank movement. */
    public static function pay(int $id, bool $skipPermCheck = false): void
    {
        if (!$skipPermCheck) must(can('expense.create') || can('till.use'), 'You do not have permission to perform this action.');
        tx(function () use ($id) {
            $e = self::expense($id);
            must(in_array($e['status'], ['Pending', 'Approved'], true), 'This expense has already been paid or closed.');
            must($e['status'] === 'Approved' || !$e['required_authority'], 'This expense must be approved before it can be paid.');
            $br = (int)$e['branch_id'];
            assert_active_branch($br);
            $bd = posting_date($br);
            $session = null;
            if ($e['method'] === 'Cash') $session = self::requireOpenSession();
            if ($session) must(self::expected((int)$session['id']) >= $e['amount'], 'The till has insufficient cash (' . ugx(self::expected((int)$session['id'])) . ') for this expense.');
            self::record(['session' => $session['id'] ?? null, 'direction' => -1, 'amount' => (int)$e['amount'], 'category' => 'expense', 'method' => $e['method'],
                'ref_type' => 'expense', 'ref_id' => $id, 'reference' => $e['reference'] ?: $e['expense_no'], 'description' => $e['description'], 'bd' => $bd, 'branch' => $br]);
            update('expenses', ['status' => 'Paid', 'paid_at' => now(), 'paid_business_date' => $bd, 'session_id' => $session['id'] ?? null,
                'account_id' => $session ? null : self::accountForMethod($e['method'], $br)], 'id = :id', ['id' => $id]);
            audit('Expense paid', 'Expenses', 'expense', $id, $e['status'], 'Paid', $e['description'], '', null, null, $br);
        });
    }

    public static function reverseExpense(int $id, string $reason): void
    {
        require_perm('expense.approve');
        must($reason !== '', 'A reason is required to reverse an expense.');
        tx(function () use ($id, $reason) {
            $e = self::expense($id);
            must($e['status'] === 'Paid', 'Only a paid expense can be reversed.');
            $br = (int)$e['branch_id'];
            assert_active_branch($br);
            must((int)$e['entered_by'] !== (int)uid(), 'Separation of duties: you cannot reverse an expense that you entered.');
            Approvals::assert('expense', (int)$e['amount'], $br, 'expense reversal');
            $bd = posting_date($br);
            $sid = $e['method'] === 'Cash' ? self::sessionForReversal($e['session_id'] ? (int)$e['session_id'] : null) : null;
            self::record(['session' => $sid, 'direction' => 1, 'amount' => (int)$e['amount'], 'category' => 'reversal', 'method' => $e['method'],
                'ref_type' => 'expense', 'ref_id' => $id, 'description' => 'Reversal of ' . $e['expense_no'] . ': ' . $reason, 'bd' => $bd, 'branch' => $br]);
            update('expenses', ['status' => 'Reversed', 'reversal_reason' => $reason], 'id = :id', ['id' => $id]);
            audit('Expense reversed', 'Expenses', 'expense', $id, 'Paid', 'Reversed', $reason, '', null, null, $br);
        });
    }
}
