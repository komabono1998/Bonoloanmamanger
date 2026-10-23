<?php
/** Payments, allocation, receipts, reversals and customer credits. */

final class Payments
{
    public const METHODS = ['Cash', 'Bank Transfer', 'Mobile Money', 'Other'];

    public static function allocationOrder(): array
    {
        $valid = ['penalty', 'fee', 'interest', 'principal'];
        $o = array_values(array_filter(array_map('trim', explode(',', setting('alloc_order'))), fn($c) => in_array($c, $valid, true)));
        foreach ($valid as $c) if (!in_array($c, $o, true)) $o[] = $c;
        return $o;
    }

    /** What a payment would be allocated to, for display before posting. */
    public static function preview(int $loanId, int $amount): array
    {
        $b = Loans::balances($loanId);
        $a = Calc::allocate($amount, $b, self::allocationOrder());
        return ['balances' => $b] + $a;
    }

    /** Search open loans by loan number, client number, name or phone. */
    public static function searchLoans(string $term, bool $openOnly = true): array
    {
        $like = '%' . $term . '%';
        $sql = "SELECT l.id, l.loan_no, l.status, l.due_date, c.full_name, c.client_no, c.phone, b.total_out, b.principal_out, b.interest_out, b.penalty_out, b.fee_out
                FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id
                WHERE " . branch_scope('l.branch_id') . " AND (l.loan_no LIKE ? OR c.client_no LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ?)"
            . ($openOnly ? " AND l.status IN ('Active','Overdue')" : " AND l.status IN ('Active','Overdue','Closed')") . ' ORDER BY l.id DESC LIMIT 50';
        return rows($sql, [$like, $like, $like, $like, $like]);
    }

    public static function record(array $in): int
    {
        require_perm('payment.record');
        $loan = Loans::get((int)($in['loan_id'] ?? 0));
        $amount = parse_money($in['amount'] ?? '', 'Payment amount');
        $method = $in['method'] ?? '';
        must(in_array($method, self::METHODS, true), 'Select a payment method.');
        $ref = trim($in['reference'] ?? '');
        must($method === 'Cash' || $ref !== '', 'A transaction reference is required for non-cash payments.');
        return self::post($loan, $amount, $method, $ref, trim($in['remarks'] ?? ''), $in['txn_token'] ?? '', ($in['excess_mode'] ?? 'reject') === 'credit');
    }

    /** Core posting routine, shared by ordinary payments and customer-credit applications. */
    private static function post(array $loan, int $amount, string $method, string $ref, string $remarks, string $token,
                                 bool $excessAsCredit, ?int $creditId = null): int
    {
        return tx(function () use ($loan, $amount, $method, $ref, $remarks, $token, $excessAsCredit, $creditId) {
            $loanId = (int)$loan['id'];
            $loan = Loans::get($loanId);
            $br = (int)$loan['branch_id'];
            assert_active_branch($br);
            $bd = posting_date($br);
            if ($loan['status'] === 'Closed') throw new AppError('This loan is closed. Ordinary payments cannot be posted against a closed loan.');
            must(in_array($loan['status'], Loans::OPEN_STATUSES, true), 'This loan has not been disbursed.');
            $token = $token ?: bin2hex(random_bytes(10));
            must(!val('SELECT COUNT(*) FROM payments WHERE txn_token = ?', [$token]), 'This payment has already been posted (duplicate submission blocked).');
            if ($ref !== '' && $method !== 'Cash') {
                must(!val('SELECT COUNT(*) FROM payments WHERE method = ? AND reference = ?', [$method, $ref]), 'A payment with this transaction reference has already been recorded.');
            }
            $session = null;
            if ($method === 'Cash') $session = Cash::requireOpenSession();
            elseif ($creditId === null) $session = Cash::mySession();

            $b = Loans::balances($loanId);
            $alloc = Calc::allocate($amount, $b, self::allocationOrder());
            $excess = $alloc['excess'];
            if ($excess > 0) {
                must($excessAsCredit, 'Payment exceeds the outstanding amount of ' . ugx($b['total']) . ' by ' . ugx($excess) . '. Reduce the amount or record the excess as a customer credit.');
                must(can('credit.manage'), 'Recording an overpayment as customer credit requires authorization.');
                must($creditId === null, 'A credit application cannot exceed the outstanding amount.');
            }
            $pid = insert('payments', [
                'branch_id' => $br, 'loan_id' => $loanId, 'client_id' => $loan['client_id'], 'payment_date' => $bd, 'business_date' => $bd, 'amount' => $amount,
                'method' => $method, 'reference' => $ref ?: null, 'cashier_id' => uid(), 'session_id' => $session['id'] ?? null,
                'remarks' => $remarks ?: null, 'status' => 'Posted', 'txn_token' => $token, 'prev_balance' => $b['total'], 'created_at' => now(),
            ]);
            foreach ($alloc['allocations'] as $comp => $amt) {
                insert('payment_allocations', ['payment_id' => $pid, 'loan_id' => $loanId, 'component' => $comp, 'amount' => $amt]);
                Loans::ledger($loanId, 'payment', $comp, -$amt, 'payment', $pid, 'Payment received (' . $method . ')', $bd);
            }
            if ($excess > 0) {
                insert('payment_allocations', ['payment_id' => $pid, 'loan_id' => $loanId, 'component' => 'credit', 'amount' => $excess]);
                $cid = insert('customer_credits', ['branch_id' => $br, 'client_id' => $loan['client_id'], 'loan_id' => $loanId, 'payment_id' => $pid, 'amount' => $excess,
                    'remaining' => $excess, 'status' => 'Open', 'created_at' => now()]);
                insert('credit_events', ['credit_id' => $cid, 'type' => 'Created from overpayment', 'amount' => $excess, 'business_date' => $bd, 'user_id' => uid(), 'at' => now()]);
            }
            // money movement (credit applications move no cash: the cash was received earlier)
            if ($creditId === null) {
                Cash::record(['session' => $session['id'] ?? null, 'direction' => 1, 'amount' => $amount, 'category' => 'collection', 'method' => $method,
                    'ref_type' => 'payment', 'ref_id' => $pid, 'reference' => $ref ?: null, 'description' => 'Payment ' . $loan['loan_no'], 'bd' => $bd, 'branch' => $br]);
            }
            $rno = make_number('receipt_prefix', 'receipt', true);
            insert('receipts', ['receipt_no' => $rno, 'payment_id' => $pid, 'issued_at' => now(), 'issued_by' => uid(), 'status' => 'Valid']);
            audit('Payment recorded', 'Payments', 'payment', $pid, ugx($b['total']), ugx($b['total'] - ($amount - $excess)), $remarks, $rno);
            Loans::autoClose($loanId, $rno);
            Loans::refreshStatuses($loanId);
            return $pid;
        });
    }

    public static function receipt(int $paymentId): array
    {
        $p = row("SELECT p.*, r.receipt_no, r.status AS receipt_status, r.print_count, r.void_reason, r.issued_at, c.full_name AS client_name,
                  c.client_no, l.loan_no, u.full_name AS cashier_name
                  FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN clients c ON c.id = p.client_id
                  JOIN loans l ON l.id = p.loan_id JOIN users u ON u.id = p.cashier_id WHERE p.id = ?", [$paymentId]);
        must((bool)$p, 'Receipt does not exist.');
        assert_branch((int)$p['branch_id']);
        $p['allocations'] = rows('SELECT component, amount FROM payment_allocations WHERE payment_id = ?', [$paymentId]);
        $applied = 0;
        foreach ($p['allocations'] as $a) if ($a['component'] !== 'credit') $applied += (int)$a['amount'];
        $p['applied'] = $applied;
        $p['remaining_balance'] = (int)$p['prev_balance'] - $applied;
        return $p;
    }

    public static function markPrinted(int $paymentId): bool
    {
        $r = row('SELECT * FROM receipts WHERE payment_id = ?', [$paymentId]);
        $isCopy = $r && (int)$r['print_count'] > 0;
        if ($isCopy) require_perm('receipt.reprint');
        q('UPDATE receipts SET print_count = print_count + 1 WHERE payment_id = ?', [$paymentId]);
        audit($isCopy ? 'Receipt reprinted' : 'Receipt printed', 'Payments', 'receipt', $r['receipt_no'] ?? $paymentId);
        return $isCopy;
    }

    public static function independentApprovalRequired(): bool
    {
        return setting('reversal_independent_approval') !== '0';
    }

    /** Maker step: ask for a payment to be reversed. An independent approver (Branch Manager / COO) decides. */
    public static function requestReversal(int $paymentId, string $reason): int
    {
        must(can('payment.reverse.request') || can('payment.reverse'), 'You do not have permission to perform this action.');
        must($reason !== '', 'A reason is required to request a payment reversal.');
        $p = row('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        must((bool)$p, 'Payment does not exist.');
        assert_branch((int)$p['branch_id']);
        must($p['status'] === 'Posted', 'This payment has already been reversed.');
        must(!val("SELECT COUNT(*) FROM approval_requests WHERE type = 'payment_reversal' AND ref_id = ? AND status = 'Pending'", [$paymentId]), 'A reversal request for this payment is already pending.');
        $need = Approvals::required('reversal', (int)$p['amount'], (int)$p['branch_id']);
        $id = insert('approval_requests', ['branch_id' => $p['branch_id'], 'type' => 'payment_reversal', 'ref_type' => 'payment', 'ref_id' => $paymentId, 'amount' => $p['amount'], 'reason' => $reason,
            'required_authority' => $need, 'requested_by' => uid(), 'requested_at' => now()]);
        audit('Payment reversal requested', 'Payments', 'payment', $paymentId, null, 'requested', $reason, 'REQ-' . $id, null, null, (int)$p['branch_id']);
        return $id;
    }

    /** Checker step: approve a pending request (must not be the requester) and execute the reversal. */
    public static function approveReversal(int $requestId, string $notes = ''): void
    {
        require_perm('payment.reverse');
        $r = row("SELECT * FROM approval_requests WHERE id = ? AND type = 'payment_reversal' AND status = 'Pending'", [$requestId]);
        must((bool)$r, 'That request is no longer pending.');
        assert_active_branch((int)$r['branch_id']);
        must((int)$r['requested_by'] !== (int)uid(), 'Separation of duties: you cannot approve a reversal that you requested.');
        Approvals::assert('reversal', (int)$r['amount'], (int)$r['branch_id'], 'payment reversal');
        tx(function () use ($r, $requestId, $notes) {
            update('approval_requests', ['status' => 'Approved', 'decided_by' => uid(), 'decided_at' => now(), 'decision_notes' => $notes ?: null], 'id = :id', ['id' => $requestId]);
            self::reverse((int)$r['ref_id'], $r['reason'], $requestId);
        });
    }

    public static function rejectReversal(int $requestId, string $notes): void
    {
        require_perm('payment.reverse');
        must($notes !== '', 'Please give a reason for rejecting the request.');
        $r = row("SELECT * FROM approval_requests WHERE id = ? AND type = 'payment_reversal' AND status = 'Pending'", [$requestId]);
        must((bool)$r, 'That request is no longer pending.');
        assert_branch((int)$r['branch_id']);
        update('approval_requests', ['status' => 'Rejected', 'decided_by' => uid(), 'decided_at' => now(), 'decision_notes' => $notes], 'id = :id', ['id' => $requestId]);
        audit('Payment reversal rejected', 'Payments', 'payment', $r['ref_id'], 'requested', 'rejected', $notes, 'REQ-' . $requestId, null, null, (int)$r['branch_id']);
    }

    /**
     * Post the reversal. With independent approval on (default) this is only reachable through approveReversal();
     * set reversal_independent_approval = 0 to let an authorized manager reverse directly.
     */
    public static function reverse(int $paymentId, string $reason, ?int $approvalId = null): void
    {
        require_perm('payment.reverse');
        must($reason !== '', 'A reason is required to reverse a payment.');
        must($approvalId !== null || !self::independentApprovalRequired(), 'A payment reversal needs an independent approval. Submit a reversal request for another authorized approver to decide.');
        tx(function () use ($paymentId, $reason, $approvalId) {
            $p = row('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            must((bool)$p, 'Payment does not exist.');
            $br = (int)$p['branch_id'];
            assert_active_branch($br);
            must($p['status'] === 'Posted', 'This payment has already been reversed.');
            if ($approvalId === null) Approvals::assert('reversal', (int)$p['amount'], $br, 'payment reversal');
            $bd = posting_date($br);
            $rno = val('SELECT receipt_no FROM receipts WHERE payment_id = ?', [$paymentId]);
            $credit = row('SELECT * FROM customer_credits WHERE payment_id = ?', [$paymentId]);
            if ($credit) {
                must((int)$credit['remaining'] === (int)$credit['amount'] && $credit['status'] === 'Open', 'The overpayment credit from this payment has already been applied or refunded; reverse that first.');
            }
            // cash first, so a missing till aborts the reversal before anything is written
            if ($p['method'] !== 'Customer Credit') {
                $sid = $p['method'] === 'Cash' ? Cash::sessionForReversal($p['session_id'] ? (int)$p['session_id'] : null) : null;
                Cash::record(['session' => $sid, 'direction' => -1, 'amount' => (int)$p['amount'], 'category' => 'reversal', 'method' => $p['method'], 'branch' => $br,
                    'ref_type' => 'payment', 'ref_id' => $paymentId, 'reference' => $rno, 'description' => 'Reversal of receipt ' . $rno . ': ' . $reason, 'bd' => $bd]);
            }
            foreach (rows('SELECT * FROM payment_allocations WHERE payment_id = ?', [$paymentId]) as $a) {
                if ($a['component'] === 'credit') continue;
                Loans::ledger((int)$p['loan_id'], 'payment_reversal', $a['component'], (int)$a['amount'], 'payment', $paymentId, 'Reversal of receipt ' . $rno, $bd);
            }
            if ($credit) {
                update('customer_credits', ['status' => 'Void', 'remaining' => 0], 'id = :id', ['id' => $credit['id']]);
                insert('credit_events', ['credit_id' => $credit['id'], 'type' => 'Voided (payment reversed)', 'amount' => $credit['amount'], 'business_date' => $bd, 'user_id' => uid(), 'at' => now()]);
            }
            if ($p['method'] === 'Customer Credit' && $p['reference']) {
                $cid = (int)str_replace('CREDIT-', '', $p['reference']);
                if ($cid) {
                    q("UPDATE customer_credits SET remaining = remaining + ?, status = 'Open' WHERE id = ?", [$p['amount'], $cid]);
                    insert('credit_events', ['credit_id' => $cid, 'type' => 'Restored (payment reversed)', 'amount' => $p['amount'], 'ref' => $rno, 'business_date' => $bd, 'user_id' => uid(), 'at' => now()]);
                }
            }
            update('payments', ['status' => 'Reversed', 'reversed_by' => uid(), 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_business_date' => $bd], 'id = :id', ['id' => $paymentId]);
            update('receipts', ['status' => 'Void', 'void_reason' => $reason], 'payment_id = :id', ['id' => $paymentId]);
            audit('Payment reversed', 'Payments', 'payment', $paymentId, 'Posted', 'Reversed', $reason, ($approvalId ? 'REQ-' . $approvalId . ' ' : '') . (string)$rno, null, null, $br);
            Loans::reopenIfOwing((int)$p['loan_id'], 'Payment ' . $rno . ' reversed');
            Loans::refreshStatuses((int)$p['loan_id']);
        });
    }

    // ---------------------------------------------------------------- customer credit
    public static function creditsFor(int $clientId): array
    {
        return rows("SELECT * FROM customer_credits WHERE client_id = ? AND status = 'Open' AND remaining > 0 AND " . branch_scope('branch_id') . ' ORDER BY id', [$clientId]);
    }

    public static function applyCredit(int $creditId, int $loanId, int $amount): int
    {
        require_perm('credit.manage');
        $credit = row('SELECT * FROM customer_credits WHERE id = ?', [$creditId]);
        must($credit && $credit['status'] === 'Open', 'This credit is not available.');
        must($amount > 0 && $amount <= (int)$credit['remaining'], 'Amount exceeds the available credit of ' . ugx($credit['remaining']) . '.');
        $loan = Loans::get($loanId);
        must((int)$loan['client_id'] === (int)$credit['client_id'], 'The credit belongs to a different client.');
        assert_active_branch((int)$loan['branch_id']);
        return tx(function () use ($credit, $creditId, $loan, $amount) {
            $pid = self::post($loan, $amount, 'Customer Credit', 'CREDIT-' . $creditId, 'Applied from customer credit', '', false, $creditId);
            $left = (int)$credit['remaining'] - $amount;
            update('customer_credits', ['remaining' => $left, 'status' => $left === 0 ? 'Applied' : 'Open'], 'id = :id', ['id' => $creditId]);
            insert('credit_events', ['credit_id' => $creditId, 'type' => 'Applied to ' . $loan['loan_no'], 'amount' => $amount, 'business_date' => posting_date((int)$loan['branch_id']), 'user_id' => uid(), 'at' => now()]);
            audit('Customer credit applied', 'Payments', 'customer_credit', $creditId, ugx($credit['remaining']), ugx($left), $loan['loan_no']);
            return $pid;
        });
    }

    public static function refundCredit(int $creditId, int $amount, string $reason): void
    {
        require_perm('credit.manage');
        must($reason !== '', 'A reason is required for a refund.');
        tx(function () use ($creditId, $amount, $reason) {
            $credit = row('SELECT * FROM customer_credits WHERE id = ?', [$creditId]);
            must($credit && $credit['status'] === 'Open', 'This credit is not available.');
            assert_active_branch((int)$credit['branch_id']);
            must($amount > 0 && $amount <= (int)$credit['remaining'], 'Amount exceeds the available credit of ' . ugx($credit['remaining']) . '.');
            $session = Cash::requireOpenSession();
            must(Cash::expected((int)$session['id']) >= $amount, 'The till has insufficient cash for this refund.');
            $bd = posting_date((int)$credit['branch_id']);
            Cash::record(['session' => $session['id'], 'direction' => -1, 'amount' => $amount, 'category' => 'adjustment', 'method' => 'Cash',
                'ref_type' => 'customer_credit', 'ref_id' => $creditId, 'description' => 'Refund of customer credit: ' . $reason, 'bd' => $bd, 'branch' => (int)$credit['branch_id']]);
            $left = (int)$credit['remaining'] - $amount;
            update('customer_credits', ['remaining' => $left, 'status' => $left === 0 ? 'Refunded' : 'Open'], 'id = :id', ['id' => $creditId]);
            insert('credit_events', ['credit_id' => $creditId, 'type' => 'Refunded in cash', 'amount' => $amount, 'ref' => $reason, 'business_date' => $bd, 'user_id' => uid(), 'at' => now()]);
            audit('Customer credit refunded', 'Payments', 'customer_credit', $creditId, ugx($credit['remaining']), ugx($left), $reason);
        });
    }
}
