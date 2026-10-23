<?php
$title = 'Payments';
$loanId = (int)get('loan_id');
$a = get('a');

if ($a === 'pay') {
    post_action(function () {
        $pid = Payments::record($_POST);
        return ['Payment recorded and receipt issued.', url('receipts', ['a' => 'view', 'id' => $pid, 'print' => 1])];
    });
}
if ($a === 'reverse') {
    post_action(function () {
        if (Payments::independentApprovalRequired()) {
            Payments::requestReversal((int)post('payment_id'), clean(post('reason')));
            return ['Reversal requested. An independent approver (Branch Manager / COO) must approve it before anything changes.', url('payments', ['tab' => 'history'])];
        }
        Payments::reverse((int)post('payment_id'), clean(post('reason')));
        return ['Payment reversed. The original record and receipt are retained and marked reversed.', url('payments', ['tab' => 'history'])];
    });
}
if ($a === 'approve_reversal') post_action(function () { Payments::approveReversal((int)post('request_id'), clean(post('notes'))); return ['Reversal approved and posted. The original record is retained.', url('payments', ['tab' => 'requests'])]; });
if ($a === 'reject_reversal') post_action(function () { Payments::rejectReversal((int)post('request_id'), clean(post('notes'))); return ['Reversal request rejected.', url('payments', ['tab' => 'requests'])]; });
if ($a === 'apply_credit') {
    post_action(function () use ($loanId) {
        $pid = Payments::applyCredit((int)post('credit_id'), (int)post('loan_id'), parse_money(post('amount'), 'Amount'));
        return ['Customer credit applied.', url('receipts', ['a' => 'view', 'id' => $pid])];
    });
}
if ($a === 'refund_credit') {
    post_action(function () {
        Payments::refundCredit((int)post('credit_id'), parse_money(post('amount'), 'Amount'), clean(post('reason')));
        return 'Credit refunded in cash from your till.';
    });
}

$tab = get('tab', 'receive');
page_head('Payments & collections');
$pendingReq = (int)val("SELECT COUNT(*) FROM approval_requests WHERE type = 'payment_reversal' AND status = 'Pending' AND " . branch_scope('branch_id'));
tabs(['receive' => 'Receive payment', 'history' => 'Payment history', 'requests' => 'Reversal requests' . ($pendingReq ? " ($pendingReq)" : ''), 'credits' => 'Customer credits'], $tab);

if ($tab === 'receive') {
    $mine = Cash::mySession();
    if (!current_bd()) echo '<div class="notice bad">No business day is open. Payments cannot be posted until a manager starts the day.</div>';
    elseif (!$mine && can('till.use')) echo '<div class="notice">You have no open cashier session. Cash payments require an open till. <a href="' . e(url('till')) . '">Open your till →</a></div>';

    if ($loanId) {
        $l = Loans::get($loanId);
        if (!in_array($l['status'], Loans::OPEN_STATUSES, true)) {
            echo '<div class="notice bad">' . ($l['status'] === 'Closed' ? 'This loan is closed. Ordinary payments cannot be posted against a closed loan.' : 'This loan has not been disbursed.') . '</div>';
        } else {
            Loans::refreshStatuses($loanId);
            $b = Loans::balances($loanId);
            $info = Loans::overdueInfo(Loans::get($loanId));
            echo '<div class="grid g2"><div class="card"><h2>' . e($l['loan_no']) . ' · ' . e($l['client_name']) . '</h2>';
            if ($info['since']) echo '<div class="notice bad">Overdue since ' . e(fmt_date($info['since'])) . ' (' . $info['days'] . ' day(s)); overdue amount ' . ugx($info['amount']) . '.</div>';
            echo '<div class="calc"><table><tr><td>Outstanding principal</td><td>' . num($b['principal']) . '</td></tr><tr><td>Outstanding interest</td><td>' . num($b['interest']) . '</td></tr>'
                . '<tr><td>Penalties</td><td>' . num($b['penalty']) . '</td></tr><tr><td>Fees</td><td>' . num($b['fee']) . '</td></tr><tr class="tot"><td>Amount due now (total outstanding)</td><td>' . num($b['total']) . '</td></tr></table></div>'
                . '<p class="muted" style="margin-top:.6rem">Due date ' . e(fmt_date($l['due_date'])) . ' · allocation order: ' . e(implode(' → ', Payments::allocationOrder())) . '</p>'
                . '<p>' . btn('Loan file', url('loan', ['id' => $loanId]), 'sm') . ' ' . btn('Statement', url('print', ['doc' => 'statement', 'id' => $loanId]), 'sm') . '</p></div>';
            echo '<div class="card"><h2>Record payment</h2>';
            if (!can('payment.record')) { echo '<p class="muted">Payments are recorded by cashiers with collection rights. You can view the balances and history of this loan here.</p></div></div>'; } else {
            form_open(url('payments', ['a' => 'pay', 'loan_id' => $loanId]), ['attrs' => 'style="grid-template-columns:1fr 1fr"']);
            echo token_field();
            field('loan_id', '', ['type' => 'hidden', 'value' => $loanId]);
            field('amount', 'Amount received (UGX)', ['type' => 'money', 'required' => true, 'attrs' => 'id="pay-amount" data-due="' . (int)$b['total'] . '" autofocus', 'help' => 'Partial payments are allowed.']);
            field('method', 'Payment method', ['type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)]);
            field('reference', 'Transaction reference', ['help' => 'Required for bank / mobile money.']);
            field('remarks', 'Remarks');
            echo '<div class="full" id="pay-note" style="grid-column:1/-1;font-weight:600"></div>';
            if (can('credit.manage')) field('excess_mode', 'Record any excess as customer credit', ['type' => 'checkbox', 'value' => '', 'span' => 2, 'attrs' => 'data-check-values="credit|reject"']);
            echo '<div class="form-actions">'; submit_btn('Post payment & issue receipt', 'primary', 'data-confirm="Post this payment? A receipt will be issued."'); echo '</div>';
            form_close();
            echo '</div></div>';
            }
            // customer credit available
            $cr = Payments::creditsFor((int)$l['client_id']);
            if ($cr && can('credit.manage')) {
                echo '<div class="card"><h2>Apply customer credit to this loan</h2>';
                foreach ($cr as $c) {
                    form_open(url('payments', ['a' => 'apply_credit', 'loan_id' => $loanId]), ['attrs' => 'style="grid-template-columns:2fr 1fr auto;align-items:end"']);
                    field('credit_id', '', ['type' => 'hidden', 'value' => $c['id']]); field('loan_id', '', ['type' => 'hidden', 'value' => $loanId]);
                    echo '<div class="fld"><label>Credit #' . (int)$c['id'] . '</label><div style="padding:.5rem 0">Available ' . ugx($c['remaining']) . '</div></div>';
                    field('amount', 'Amount to apply', ['type' => 'money', 'value' => min((int)$c['remaining'], (int)$b['total'])]);
                    echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Apply', 'default'); echo '</div>'; form_close();
                }
                echo '</div>';
            }
        }
    } else {
        $q = get('q');
        echo '<div class="card"><h2>Find the loan</h2>';
        filter_bar(['q' => ['label' => 'Loan ID, Client ID, name or phone', 'placeholder' => 'Loan number, client ID, name or phone']], ['q' => $q], 'payments');
        if ($q !== '') {
            $res = Payments::searchLoans($q);
            data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'phone' => 'Phone', 'due_date' => ['Due', 'date'], 'status' => ['Status', 'badge'], 'total_out' => ['Amount due', 'money'],
                'go' => ['', fn($r) => '<a class="btn sm primary" href="' . e(url('payments', ['loan_id' => $r['id']])) . '">Receive payment</a>']], $res, ['class' => 'nofilter', 'empty' => 'No active loans match.']);
        } else {
            echo '<p class="muted">Search for an active loan to see the amount due and record a payment.</p>';
            $due = rows("SELECT l.id, l.loan_no, c.full_name, l.due_date, l.status, b.total_out FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id
                         WHERE l.status IN ('Active','Overdue') AND " . branch_scope('l.branch_id') . " ORDER BY l.due_date LIMIT 15");
            echo '<h3>Next due</h3>';
            data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'due_date' => ['Due', 'date'], 'status' => ['Status', 'badge'], 'total_out' => ['Amount due', 'money'],
                'go' => ['', fn($r) => '<a class="btn sm" href="' . e(url('payments', ['loan_id' => $r['id']])) . '">Receive payment</a>']], $due, ['class' => 'nofilter', 'empty' => 'No active loans.']);
        }
        echo '</div>';
    }
}

if ($tab === 'history') {
    $from = get('from') ?: date('Y-m-d', strtotime('-30 days', strtotime(reporting_date()))); $to = get('to') ?: reporting_date();
    $meth = get('method'); $stt = get('status'); $q = get('q');
    $w = ['p.business_date BETWEEN ? AND ?', branch_scope('p.branch_id')]; $p = [$from, $to];
    if ($meth !== '') { $w[] = 'p.method = ?'; $p[] = $meth; }
    if ($stt !== '') { $w[] = 'p.status = ?'; $p[] = $stt; }
    if ($q !== '') { $w[] = '(l.loan_no LIKE ? OR c.full_name LIKE ? OR r.receipt_no LIKE ? OR p.reference LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $ps = rows("SELECT p.id, r.receipt_no, p.business_date, l.loan_no, c.full_name, p.amount, p.method, p.reference, u.full_name AS cashier, p.status, p.reversal_reason
                FROM payments p JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id JOIN users u ON u.id = p.cashier_id LEFT JOIN receipts r ON r.payment_id = p.id
                WHERE " . implode(' AND ', $w) . ' ORDER BY p.id DESC LIMIT 500', $p);
    filter_bar(['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'], 'q' => ['label' => 'Search', 'placeholder' => 'receipt, loan, client'],
        'method' => ['label' => 'Method', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)], 'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['Posted' => 'Posted', 'Reversed' => 'Reversed']]],
        ['from' => $from, 'to' => $to, 'q' => $q, 'method' => $meth, 'status' => $stt], 'payments', ['tab' => 'history']);
    data_table(['receipt_no' => 'Receipt', 'business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'amount' => ['Amount', 'money'], 'method' => 'Method', 'reference' => 'Reference',
        'cashier' => 'Cashier', 'status' => ['Status', 'badge'],
        'act' => ['', fn($r) => $r['status'] === 'Posted' && (can('payment.reverse') || can('payment.reverse.request')) ? '<form method="post" action="' . e(url('payments', ['a' => 'reverse'])) . '" data-confirm="' . (Payments::independentApprovalRequired() ? 'Submit a reversal request for independent approval?' : 'Reverse this payment? The receipt will be voided and balances restored.') . '" style="display:flex;gap:.3rem">' . csrf_field() . '<input type="hidden" name="payment_id" value="' . (int)$r['id'] . '"><input type="text" name="reason" required placeholder="reason" style="width:130px"><button class="btn sm danger">' . (Payments::independentApprovalRequired() ? 'Request reversal' : 'Reverse') . '</button></form>' : e($r['reversal_reason'] ?? '')]],
        $ps, ['link' => fn($r) => url('receipts', ['a' => 'view', 'id' => $r['id']]), 'empty' => 'No payments in this range.', 'totals' => ['amount']]);
}

if ($tab === 'credits') {
    $cr = rows("SELECT cc.*, c.full_name, c.client_no FROM customer_credits cc JOIN clients c ON c.id = cc.client_id WHERE " . branch_scope('cc.branch_id') . " ORDER BY cc.id DESC LIMIT 200");
    data_table(['id' => '#', 'full_name' => 'Client', 'created_at' => ['Created', 'dt'], 'amount' => ['Amount', 'money'], 'remaining' => ['Remaining', 'money'], 'status' => ['Status', 'badge'],
        'act' => ['', fn($r) => $r['status'] === 'Open' && can('credit.manage') ? '<form method="post" action="' . e(url('payments', ['a' => 'refund_credit', 'tab' => 'credits'])) . '" data-confirm="Refund this credit in cash from your till?" style="display:flex;gap:.3rem">' . csrf_field() . '<input type="hidden" name="credit_id" value="' . (int)$r['id'] . '"><input type="text" class="money" name="amount" value="' . (int)$r['remaining'] . '" style="width:100px"><input type="text" name="reason" placeholder="reason" required style="width:110px"><button class="btn sm">Refund</button></form>' : '']],
        $cr, ['empty' => 'No customer credits.', 'class' => 'nofilter']);
    echo '<p class="muted" style="margin-top:.6rem">Overpayments are held as a credit liability. Apply a credit to a loan from that loan\'s payment screen.</p>';
}

if ($tab === 'requests') {
    $rq = rows("SELECT a.*, r.receipt_no, p.method, p.status AS pay_status, l.loan_no, c.full_name, u.full_name AS req, d.full_name AS dec FROM approval_requests a
                JOIN payments p ON p.id = a.ref_id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id LEFT JOIN receipts r ON r.payment_id = p.id
                JOIN users u ON u.id = a.requested_by LEFT JOIN users d ON d.id = a.decided_by
                WHERE a.type = 'payment_reversal' AND " . branch_scope('a.branch_id') . ' ORDER BY a.id DESC LIMIT 200');
    echo '<p class="muted">A reversal needs an authorized reason from the requester and an <b>independent</b> approver; requests above the Branch Manager limit go to the COO.</p>';
    data_table(['requested_at' => ['Requested', 'dt'], 'receipt_no' => 'Receipt', 'loan_no' => 'Loan', 'full_name' => 'Client', 'amount' => ['Amount', 'money'], 'reason' => 'Reason', 'req' => 'Requested by',
        'required_authority' => 'Approver level', 'status' => ['Status', 'badge'],
        'act' => ['', function ($r) {
            if ($r['status'] !== 'Pending') return e(trim(($r['dec'] ?? '') . ' ' . ($r['decision_notes'] ?? '')));
            if (!can('payment.reverse')) return '<span class="muted">awaiting approver</span>';
            return '<form method="post" action="' . e(url('payments', ['a' => 'approve_reversal'])) . '" style="display:flex;gap:.3rem" data-confirm="Approve and post this reversal?">' . csrf_field() . '<input type="hidden" name="request_id" value="' . (int)$r['id'] . '"><input type="text" name="notes" placeholder="notes" style="width:110px"><button class="btn sm primary">Approve</button></form> '
                . '<form method="post" action="' . e(url('payments', ['a' => 'reject_reversal'])) . '" style="display:flex;gap:.3rem">' . csrf_field() . '<input type="hidden" name="request_id" value="' . (int)$r['id'] . '"><input type="text" name="notes" placeholder="reason" required style="width:110px"><button class="btn sm danger">Reject</button></form>';
        }]], $rq, ['empty' => 'No reversal requests.', 'class' => 'nofilter']);
}
