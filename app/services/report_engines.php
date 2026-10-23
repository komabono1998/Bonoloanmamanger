<?php
/**
 * Report engines. Every engine returns ['cols' => data_table columns, 'rows' => list, 'totals' => [keys], 'note' => text].
 * All queries are limited to the user's branches (optionally narrowed by the Branch filter) and, for loan originators without
 * portfolio.all, to their own portfolio. Legacy engines live in Reports::run; the rest are here.
 */
final class ReportEngines
{
    private static function R(array $cols, array $rows, array $totals = [], string $note = ''): array
    {
        return ['cols' => $cols, 'rows' => $rows, 'totals' => $totals, 'note' => $note];
    }

    /** A teller (till user without reconciliation or portfolio rights) only ever sees their own transactions. */
    public static function ownCashierOnly(): bool
    {
        return can('till.use') && !can('till.reconcile');
    }

    public static function run(string $engine, array $f): array
    {
        if (self::ownCashierOnly()) $f['cashier'] = (int)uid();
        if (method_exists(self::class, $engine) && $engine !== 'run' && $engine !== 'R') return self::$engine($f);
        $r = Reports::run($engine, $f);
        return $r;
    }

    /** Common context. */
    private static function x(array $f): array
    {
        $only = branch_filter_ids((int)($f['branch'] ?? 0));
        $o = ['f' => $f, 'from' => $f['from'], 'to' => $f['to'], 'only' => $only, 'asOf' => reporting_date()];
        $o['multi'] = count(accessible_branch_ids()) > 1;
        $o['B'] = fn(string $col) => branch_scope($col, $only);
        $o['LB'] = branch_scope('l.branch_id', $only) . loan_scope('l');
        $o['off'] = (int)($f['officer'] ?? 0);
        $o['offW'] = $o['off'] ? ' AND l.officer_id = ' . $o['off'] : '';
        $o['brCol'] = $o['multi'] ? ['branch' => 'Branch'] : [];
        $o['lb'] = 'FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id';
        $o['loanNo'] = trim((string)($f['loan'] ?? ''));
        $o['days'] = max(0, (int)($f['days'] ?? 0));
        return $o;
    }

    private static function months(string $from, string $to): array
    {
        $o = []; $t = strtotime(date('Y-m-01', strtotime($from))); $end = strtotime($to);
        while ($t <= $end && count($o) < 36) { $o[] = date('Y-m', $t); $t = strtotime('+1 month', $t); }
        return $o ?: [date('Y-m', strtotime($from))];
    }

    // ================================================================= A. loan portfolio
    private static function disbursement_register(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['disb_no' => 'Voucher', 'business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'approved_principal' => ['Gross principal', 'money'], 'fees' => ['Fees deducted', 'money'], 'net_amount' => ['Net released', 'money'],
            'method' => 'Channel', 'authorizer' => 'Authorized by', 'releaser' => 'Released by'],
            rows("SELECT br.name AS branch, d.disb_no, d.business_date, l.loan_no, c.full_name, d.approved_principal, (d.processing_fee + d.application_fee + d.inspection_fee + d.other_deductions) AS fees, d.net_amount, d.method, ua.full_name AS authorizer, ud.full_name AS releaser
                  FROM disbursements d JOIN loans l ON l.id = d.loan_id JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = d.branch_id LEFT JOIN users ua ON ua.id = d.authorized_by LEFT JOIN users ud ON ud.id = d.disbursed_by
                  WHERE d.business_date BETWEEN ? AND ? AND " . $x['B']('d.branch_id') . loan_scope('l') . ' ORDER BY d.id', [$x['from'], $x['to']]), ['approved_principal', 'fees', 'net_amount']);
    }

    private static function loan_maturity(array $f): array
    {
        $x = self::x($f);
        $rows = rows("SELECT br.name AS branch, l.loan_no, c.full_name, l.due_date, " . sql_daydiff('l.due_date', '?') . " AS days_left, b.total_out, u.full_name AS officer, l.status
                      {$x['lb']} WHERE l.status IN ('Active','Overdue') AND l.due_date BETWEEN ? AND ? AND {$x['LB']} ORDER BY l.due_date", [$x['asOf'], $x['from'], $x['to']]);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'due_date' => ['Maturity', 'date'], 'days_left' => ['Days to maturity', 'num'], 'total_out' => ['Balance', 'money'], 'officer' => 'Officer', 'status' => ['Status', 'badge']], $rows, ['total_out']);
    }

    private static function product_performance(array $f): array
    {
        $x = self::x($f);
        return self::R(['frequency' => 'Repayment frequency (product)', 'n' => ['Loans', 'num'], 'principal' => ['Principal disbursed', 'money'], 'principal_out' => ['Principal outstanding', 'money'], 'overdue_n' => ['Overdue loans', 'num'], 'par' => ['Overdue share', 'pct']],
            array_map(fn($r) => $r + ['par' => $r['n'] ? round($r['overdue_n'] / $r['n'] * 100, 1) . '%' : '0%'],
                rows("SELECT l.frequency, COUNT(*) AS n, COALESCE(SUM(l.approved_principal),0) AS principal, COALESCE(SUM(b.principal_out),0) AS principal_out, SUM(CASE WHEN l.status = 'Overdue' THEN 1 ELSE 0 END) AS overdue_n
                      FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.disbursed_at IS NOT NULL AND {$x['LB']} GROUP BY l.frequency ORDER BY l.frequency")), ['n', 'principal', 'principal_out', 'overdue_n']);
    }

    private static function loan_balance_recon(array $f): array
    {
        $x = self::x($f);
        $rs = rows("SELECT br.name AS branch, l.loan_no, c.full_name, b.principal_out, b.interest_out, b.penalty_out, b.fee_out, b.total_out,
                    COALESCE((SELECT SUM(d.approved_principal) FROM disbursements d WHERE d.loan_id = l.id),0) AS disbursed,
                    COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE pa.loan_id = l.id AND pa.component = 'principal' AND p.status = 'Posted'),0) AS repaid_principal,
                    COALESCE((SELECT SUM(a.amount) FROM loan_ledger a WHERE a.loan_id = l.id AND a.type = 'waiver' AND a.component = 'principal'),0) AS waived_principal
                    FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN branches br ON br.id = l.branch_id WHERE l.disbursed_at IS NOT NULL AND {$x['LB']} ORDER BY l.id");
        $out = [];
        foreach ($rs as $r) {
            $calc = (int)$r['disbursed'] - (int)$r['repaid_principal'] + (int)$r['waived_principal'];
            $out[] = $r + ['calc' => $calc, 'diff' => (int)$r['principal_out'] - $calc, 'ok' => (int)$r['principal_out'] === $calc ? 'Reconciled' : 'DIFFERENCE'];
        }
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'disbursed' => ['Disbursed', 'money'], 'repaid_principal' => ['Principal repaid', 'money'], 'calc' => ['Recomputed principal', 'money'], 'principal_out' => ['Ledger principal', 'money'], 'diff' => ['Difference', 'money'], 'ok' => ['Result', 'badge']],
            $out, ['disbursed', 'repaid_principal', 'calc', 'principal_out', 'diff'], 'Difference = ledger principal âˆ’ (disbursed âˆ’ principal repaid + principal waived). Any non-zero line needs investigation.');
    }

    private static function loan_renewals(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['client_no' => 'Client ID', 'full_name' => 'Client', 'loan_no' => 'New loan', 'application_date' => ['Applied', 'date'], 'amount_requested' => ['Requested', 'money'], 'prev_loan' => 'Previous loan', 'prev_status' => ['Previous status', 'badge'], 'status' => ['Status', 'badge']],
            rows("SELECT br.name AS branch, c.client_no, c.full_name, l.loan_no, l.application_date, l.amount_requested, l.status,
                  (SELECT p.loan_no FROM loans p WHERE p.client_id = l.client_id AND p.id < l.id AND p.status NOT IN ('Rejected','Cancelled','Draft') ORDER BY p.id DESC LIMIT 1) AS prev_loan,
                  (SELECT p.status FROM loans p WHERE p.client_id = l.client_id AND p.id < l.id AND p.status NOT IN ('Rejected','Cancelled','Draft') ORDER BY p.id DESC LIMIT 1) AS prev_status
                  FROM loans l JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id
                  WHERE l.application_date BETWEEN ? AND ? AND {$x['LB']} AND EXISTS (SELECT 1 FROM loans p WHERE p.client_id = l.client_id AND p.id < l.id AND p.status NOT IN ('Rejected','Cancelled','Draft')) ORDER BY l.id", [$x['from'], $x['to']]), ['amount_requested']);
    }

    private static function disbursement_pipeline(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'approval_date' => ['Approved', 'date'], 'approved_principal' => ['Approved principal', 'money'], 'net_disbursement' => ['Net to release', 'money'], 'offer_expiry' => ['Offer expires', 'date'], 'acceptance_status' => ['Offer', 'badge'], 'officer' => 'Officer'],
            rows("SELECT br.name AS branch, l.loan_no, c.full_name, l.approval_date, l.approved_principal, l.net_disbursement, l.offer_expiry, l.acceptance_status, u.full_name AS officer
                  FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id WHERE l.status = 'Approved' AND {$x['LB']}{$x['offW']} ORDER BY l.approval_date"), ['approved_principal', 'net_disbursement']);
    }

    private static function tenure_analysis(array $f): array
    {
        $x = self::x($f);
        return self::R(['period_months' => ['Term (months)', 'num'], 'n' => ['Loans', 'num'], 'principal' => ['Principal disbursed', 'money'], 'principal_out' => ['Principal outstanding', 'money'], 'share' => ['Share of loans', 'pct']],
            self::withShare(rows("SELECT l.period_months, COUNT(*) AS n, COALESCE(SUM(l.approved_principal),0) AS principal, COALESCE(SUM(b.principal_out),0) AS principal_out FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id
                  WHERE l.disbursed_at IS NOT NULL AND {$x['LB']} GROUP BY l.period_months ORDER BY l.period_months"), 'n'), ['n', 'principal', 'principal_out']);
    }

    private static function withShare(array $rows, string $key): array
    {
        $t = array_sum(array_column($rows, $key));
        foreach ($rows as &$r) $r['share'] = $t > 0 ? round($r[$key] / $t * 100, 1) . '%' : '0%';
        return $rows;
    }

    private static function portfolio_movement(array $f): array
    {
        $x = self::x($f);
        $one = fn(string $sql, array $p = []) => (int)val($sql, $p);
        $scope = $x['LB'];
        $openBal = $one("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE g.component = 'principal' AND g.business_date < ? AND $scope", [$x['from']]);
        $disb = $one("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE g.component = 'principal' AND g.type = 'disbursement' AND g.business_date BETWEEN ? AND ? AND $scope", [$x['from'], $x['to']]);
        $rep = $one("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE g.component = 'principal' AND g.type IN ('payment','payment_reversal') AND g.business_date BETWEEN ? AND ? AND $scope", [$x['from'], $x['to']]);
        $adj = $one("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE g.component = 'principal' AND g.type IN ('waiver','adjustment') AND g.business_date BETWEEN ? AND ? AND $scope", [$x['from'], $x['to']]);
        $close = $openBal + $disb + $rep + $adj;
        return self::R(['label' => 'Movement', 'amount' => ['Principal (UGX)', 'money']], [
            ['label' => 'Opening portfolio (principal outstanding at ' . fmt_date($x['from']) . ')', 'amount' => $openBal], ['label' => '+ Additions: loans disbursed', 'amount' => $disb],
            ['label' => 'âˆ’ Repayments of principal (net of reversals)', 'amount' => $rep], ['label' => 'Â± Adjustments and waivers', 'amount' => $adj], ['label' => 'Closing portfolio (at ' . fmt_date($x['to']) . ')', 'amount' => $close]], [], 'Repayments are shown as negative amounts. Opening + additions + repayments + adjustments = closing.');
    }

    // ================================================================= B. clients
    private static function repeat_borrowers(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['client_no' => 'Client ID', 'full_name' => 'Client', 'phone' => 'Phone', 'loans' => ['Loans taken', 'num'], 'closed' => ['Settled', 'num'], 'last_loan' => ['Latest application', 'date'], 'principal' => ['Total principal', 'money']],
            rows("SELECT br.name AS branch, c.client_no, c.full_name, c.phone, COUNT(*) AS loans, SUM(CASE WHEN l.status = 'Closed' THEN 1 ELSE 0 END) AS closed, MAX(l.application_date) AS last_loan, COALESCE(SUM(l.approved_principal),0) AS principal
                  FROM clients c JOIN loans l ON l.client_id = c.id JOIN branches br ON br.id = c.branch_id WHERE l.disbursed_at IS NOT NULL AND {$x['LB']} GROUP BY br.name, c.id, c.client_no, c.full_name, c.phone HAVING COUNT(*) > 1 ORDER BY loans DESC, c.full_name"), ['principal']);
    }

    private static function inactive_clients(array $f): array
    {
        $x = self::x($f); $days = $x['days'] ?: 180; $cut = Calc::addDays($x['asOf'], -$days);
        return self::R($x['brCol'] + ['client_no' => 'Client ID', 'full_name' => 'Client', 'phone' => 'Phone', 'last_activity' => ['Last activity', 'date'], 'registered' => ['Registered', 'date']],
            rows("SELECT br.name AS branch, c.client_no, c.full_name, c.phone, substr(c.registered_at,1,10) AS registered,
                  (SELECT MAX(d) FROM (SELECT l.application_date AS d FROM loans l WHERE l.client_id = c.id UNION ALL SELECT p.business_date FROM payments p WHERE p.client_id = c.id) t) AS last_activity
                  FROM clients c JOIN branches br ON br.id = c.branch_id WHERE c.status = 'Active' AND " . $x['B']('c.branch_id') . client_scope('c') . "
                  AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.client_id = c.id AND l.status IN ('Draft','Submitted','Under Review','Approved','Active','Overdue'))
                  AND COALESCE((SELECT MAX(d) FROM (SELECT l.application_date AS d FROM loans l WHERE l.client_id = c.id UNION ALL SELECT p.business_date FROM payments p WHERE p.client_id = c.id) t), substr(c.registered_at,1,10)) < ? ORDER BY last_activity", [$cut]),
            [], 'Active clients with no open loan and no activity since ' . fmt_date($cut) . ' (' . $days . ' days).');
    }

    private static function multi_exposure(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['client_no' => 'Client ID', 'full_name' => 'Client', 'n' => ['Active loans', 'num'], 'principal' => ['Principal outstanding', 'money'], 'total' => ['Total owed', 'money'], 'overdue' => ['Overdue loans', 'num']],
            rows("SELECT br.name AS branch, c.client_no, c.full_name, COUNT(*) AS n, SUM(b.principal_out) AS principal, SUM(b.total_out) AS total, SUM(CASE WHEN l.status = 'Overdue' THEN 1 ELSE 0 END) AS overdue
                  FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN branches br ON br.id = l.branch_id WHERE l.status IN ('Active','Overdue') AND {$x['LB']}
                  GROUP BY br.name, c.id, c.client_no, c.full_name HAVING COUNT(*) > 1 ORDER BY total DESC"), ['n', 'principal', 'total', 'overdue']);
    }

    private static function incomplete_profiles(array $f): array
    {
        $x = self::x($f);
        $rs = rows("SELECT br.name AS branch, c.id, c.client_no, c.full_name, c.nin, c.dob, c.address, c.village, c.monthly_income, c.phone,
                    (SELECT COUNT(*) FROM attachments a WHERE a.entity_type = 'client' AND a.entity_id = c.id AND a.kind = 'photo') AS photos
                    FROM clients c JOIN branches br ON br.id = c.branch_id WHERE c.status = 'Active' AND " . $x['B']('c.branch_id') . client_scope('c') . ' ORDER BY c.client_no');
        $out = [];
        foreach ($rs as $r) {
            $m = [];
            if (!$r['nin']) $m[] = 'national ID'; if (!$r['dob']) $m[] = 'date of birth'; if (!$r['address'] && !$r['village']) $m[] = 'address'; if ((int)$r['monthly_income'] <= 0) $m[] = 'income'; if (!(int)$r['photos']) $m[] = 'photograph';
            if ($m) $out[] = ['branch' => $r['branch'], 'client_no' => $r['client_no'], 'full_name' => $r['full_name'], 'phone' => $r['phone'], 'missing' => implode(', ', $m), 'n' => count($m)];
        }
        return self::R($x['brCol'] + ['client_no' => 'Client ID', 'full_name' => 'Client', 'phone' => 'Phone', 'missing' => 'Missing', 'n' => ['Items missing', 'num']], $out, ['n']);
    }

    private static function client_statement(array $f): array
    {
        $x = self::x($f); $cid = (int)($f['client'] ?? 0);
        if (!$cid) return self::R(['info' => 'Client'], [['info' => 'Choose a client in the filters above to generate a statement.']], [], 'A client statement is generated for one client at a time and recorded in the statement generation log.');
        $c = row('SELECT c.* FROM clients c WHERE c.id = ? AND ' . $x['B']('c.branch_id') . client_scope('c'), [$cid]);
        must((bool)$c, 'That client is not in your authorized records.');
        audit('Statement generated', 'Documents', 'client', $cid, null, 'client statement ' . $x['from'] . ' to ' . $x['to']);
        $rs = rows("SELECT g.business_date, l.loan_no, g.type, g.component, g.amount, g.description FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE l.client_id = ? AND g.business_date BETWEEN ? AND ? AND {$x['LB']} ORDER BY g.business_date, g.id", [$cid, $x['from'], $x['to']]);
        $bal = (int)val("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE l.client_id = ? AND g.business_date < ? AND {$x['LB']}", [$cid, $x['from']]);
        $out = [['business_date' => '', 'loan_no' => '', 'what' => 'Balance brought forward', 'debit' => 0, 'credit' => 0, 'balance' => $bal]];
        foreach ($rs as $r) { $a = (int)$r['amount']; $bal += $a; $out[] = ['business_date' => $r['business_date'], 'loan_no' => $r['loan_no'], 'what' => ucfirst(str_replace('_', ' ', $r['type'])) . ' â€” ' . $r['component'] . ($r['description'] ? ' (' . $r['description'] . ')' : ''), 'debit' => $a > 0 ? $a : 0, 'credit' => $a < 0 ? -$a : 0, 'balance' => $bal]; }
        return self::R(['business_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'what' => 'Transaction', 'debit' => ['Charged / disbursed', 'money'], 'credit' => ['Paid / waived', 'money'], 'balance' => ['Balance owed', 'money']], $out, ['debit', 'credit'], 'Statement for ' . $c['full_name'] . ' (' . $c['client_no'] . '). Balance = amounts owed to the institution after each entry.');
    }

    // ================================================================= C. applications
    private static function pending_applications(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['loan_no' => 'Application', 'application_date' => ['Applied', 'date'], 'full_name' => 'Client', 'amount_requested' => ['Requested', 'money'], 'status' => ['Status', 'badge'], 'waiting' => ['Days waiting', 'num'], 'officer' => 'Officer', 'assessed' => 'Assessed'],
            rows("SELECT br.name AS branch, l.loan_no, l.application_date, c.full_name, l.amount_requested, l.status, " . sql_daydiff('?', 'l.application_date') . " AS waiting, u.full_name AS officer,
                  CASE WHEN EXISTS (SELECT 1 FROM credit_assessments a WHERE a.loan_id = l.id) THEN 'Yes' ELSE 'No' END AS assessed
                  FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id WHERE l.status IN ('Submitted','Under Review') AND {$x['LB']}{$x['offW']} ORDER BY l.application_date", [$x['asOf']]), ['amount_requested']);
    }

    private static function processing_time(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'application_date' => ['Applied', 'date'], 'assessed' => ['Assessed', 'date'], 'decided' => ['Decided', 'date'], 'd_assess' => ['Days to assessment', 'num'], 'd_decide' => ['Days to decision', 'num'], 'status' => ['Status', 'badge']],
            rows("SELECT br.name AS branch, l.loan_no, c.full_name, l.application_date, substr(MIN(a.assessed_at),1,10) AS assessed, l.approval_date AS decided,
                  CASE WHEN MIN(a.assessed_at) IS NULL THEN NULL ELSE " . sql_daydiff('substr(MIN(a.assessed_at),1,10)', 'l.application_date') . " END AS d_assess,
                  CASE WHEN l.approval_date IS NULL THEN NULL ELSE " . sql_daydiff('l.approval_date', 'l.application_date') . " END AS d_decide, l.status
                  FROM loans l JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id LEFT JOIN credit_assessments a ON a.loan_id = l.id
                  WHERE l.application_date BETWEEN ? AND ? AND {$x['LB']} GROUP BY br.name, l.id, l.loan_no, c.full_name, l.application_date, l.approval_date, l.status ORDER BY l.id", [$x['from'], $x['to']]));
    }

    private static function officer_recommendations(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'officer' => 'Recommended by', 'recommendation' => 'Recommendation', 'recommended_amount' => ['Recommended', 'money'], 'approved_principal' => ['Approved', 'money'], 'diff' => ['Approved âˆ’ recommended', 'money'], 'status' => ['Status', 'badge']],
            rows("SELECT br.name AS branch, l.loan_no, c.full_name, u.full_name AS officer, a.recommendation, a.recommended_amount, COALESCE(l.approved_principal, 0) AS approved_principal,
                  CASE WHEN l.approved_principal IS NULL THEN NULL ELSE l.approved_principal - a.recommended_amount END AS diff, l.status
                  FROM credit_assessments a JOIN loans l ON l.id = a.loan_id JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = a.assessed_by JOIN branches br ON br.id = l.branch_id
                  WHERE substr(a.assessed_at,1,10) BETWEEN ? AND ? AND a.id = (SELECT MAX(a2.id) FROM credit_assessments a2 WHERE a2.loan_id = l.id) AND {$x['LB']}{$x['offW']} ORDER BY l.id", [$x['from'], $x['to']]), ['recommended_amount', 'approved_principal']);
    }

    private static function approval_exceptions(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['decided_at' => ['When', 'dt'], 'loan_no' => 'Loan', 'decision' => ['Decision', 'badge'], 'approved_amount' => ['Amount', 'money'], 'approver' => 'Decided by', 'approver_role' => 'Authority level', 'reason' => 'Exception reason / evidence'],
            rows("SELECT br.name AS branch, a.decided_at, l.loan_no, a.decision, a.approved_amount, u.full_name AS approver, a.approver_role, COALESCE(a.reason, a.comments) AS reason
                  FROM loan_approvals a JOIN loans l ON l.id = a.loan_id JOIN users u ON u.id = a.approver_id JOIN branches br ON br.id = l.branch_id
                  WHERE substr(a.decided_at,1,10) BETWEEN ? AND ? AND (a.decision = 'Escalated' OR a.approver_role = 'COO' OR (a.reason IS NOT NULL AND a.reason <> '')) AND {$x['LB']} ORDER BY a.id DESC", [$x['from'], $x['to']]), ['approved_amount']);
    }

    private static function returned_applications(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['decided_at' => ['Returned', 'dt'], 'loan_no' => 'Application', 'full_name' => 'Client', 'who' => 'Returned by', 'comments' => 'What must be corrected', 'officer' => 'Officer', 'status' => ['Current status', 'badge']],
            rows("SELECT br.name AS branch, a.decided_at, l.loan_no, c.full_name, u.full_name AS who, a.comments, o.full_name AS officer, l.status
                  FROM loan_approvals a JOIN loans l ON l.id = a.loan_id JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = a.approver_id JOIN users o ON o.id = l.officer_id JOIN branches br ON br.id = l.branch_id
                  WHERE a.decision = 'Returned' AND substr(a.decided_at,1,10) BETWEEN ? AND ? AND {$x['LB']}{$x['offW']} ORDER BY a.id DESC", [$x['from'], $x['to']]));
    }

    // ================================================================= D. collections
    private static function expected_vs_actual(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (self::months($x['from'], $x['to']) as $m) {
            $a = $m . '-01'; $b = date('Y-m-t', strtotime($a));
            $e = (int)val("SELECT COALESCE(SUM(s.total_due),0) FROM repayment_schedules s JOIN loans l ON l.id = s.loan_id WHERE s.status = 'Current' AND s.due_date BETWEEN ? AND ? AND {$x['LB']}", [$a, $b]);
            $p = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN loans l ON l.id = p.loan_id WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND {$x['LB']}", [$a, $b]);
            $out[] = ['m' => date('M Y', strtotime($a)), 'expected' => $e, 'actual' => $p, 'gap' => $p - $e, 'rate' => $e > 0 ? round($p / $e * 100, 1) . '%' : 'â€“'];
        }
        return self::R(['m' => 'Month', 'expected' => ['Expected (scheduled)', 'money'], 'actual' => ['Actual (received)', 'money'], 'gap' => ['Actual âˆ’ expected', 'money'], 'rate' => ['Repayment rate', 'pct']], $out, ['expected', 'actual', 'gap']);
    }

    private static function repayment_schedule(array $f): array
    {
        $x = self::x($f); $w = ''; $p = [];
        if ($x['loanNo'] !== '') { $w = ' AND l.loan_no = ?'; $p[] = $x['loanNo']; } else { $w = ' AND s.due_date BETWEEN ? AND ?'; $p[] = $x['from']; $p[] = $x['to']; }
        $rs = rows("SELECT br.name AS branch, l.loan_no, c.full_name, s.installment_no, s.due_date, s.principal_due, s.interest_due, s.total_due, l.status,
                    (SELECT COALESCE(SUM(s2.total_due),0) FROM repayment_schedules s2 WHERE s2.loan_id = s.loan_id AND s2.status = 'Current' AND s2.installment_no <= s.installment_no) AS cum_due,
                    (SELECT COALESCE(-SUM(CASE WHEN g.type IN ('payment','payment_reversal') THEN g.amount END),0) FROM loan_ledger g WHERE g.loan_id = s.loan_id) AS paid_total
                    FROM repayment_schedules s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id WHERE s.status = 'Current' AND l.disbursed_at IS NOT NULL AND {$x['LB']}$w ORDER BY l.id, s.installment_no", $p);
        foreach ($rs as &$r) { $r['state'] = (int)$r['paid_total'] >= (int)$r['cum_due'] ? 'Paid' : ($r['due_date'] < $x['asOf'] ? 'Missed' : 'Upcoming'); $r['balance'] = max(0, (int)$r['cum_due'] - (int)$r['paid_total']); }
        unset($r);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'installment_no' => ['#', 'num'], 'due_date' => ['Due', 'date'], 'principal_due' => ['Principal', 'money'], 'interest_due' => ['Interest', 'money'], 'total_due' => ['Instalment', 'money'], 'balance' => ['Unpaid to date', 'money'], 'state' => ['State', 'badge']], $rs, ['principal_due', 'interest_due', 'total_due'],
            'Payments are applied to the schedule in order; "Unpaid to date" = scheduled up to this instalment âˆ’ paid to date.');
    }

    private static function missed_installments(array $f): array
    {
        $x = self::x($f); $out = [];
        $rs = rows("SELECT br.name AS branch, l.id AS lid, l.loan_no, c.full_name, c.phone, u.full_name AS officer, s.installment_no, s.due_date, s.total_due,
                    (SELECT COALESCE(SUM(s2.total_due),0) FROM repayment_schedules s2 WHERE s2.loan_id = s.loan_id AND s2.status = 'Current' AND s2.installment_no <= s.installment_no) AS cum_due,
                    (SELECT COALESCE(-SUM(CASE WHEN g.type IN ('payment','payment_reversal') THEN g.amount END),0) FROM loan_ledger g WHERE g.loan_id = s.loan_id) AS paid_total
                    FROM repayment_schedules s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id
                    WHERE s.status = 'Current' AND s.due_date < ? AND l.status IN ('Active','Overdue') AND {$x['LB']} ORDER BY l.id, s.installment_no", [$x['asOf']]);
        foreach ($rs as $r) {
            $unpaid = (int)$r['cum_due'] - (int)$r['paid_total'];
            if ($unpaid <= 0) continue;
            $short = min($unpaid, (int)$r['total_due']);
            $out[] = $r + ['short' => $short, 'late' => days_between($r['due_date'], $x['asOf']), 'kind' => $short >= (int)$r['total_due'] ? 'Unpaid' : 'Underpaid'];
        }
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'phone' => 'Phone', 'installment_no' => ['#', 'num'], 'due_date' => ['Due', 'date'], 'total_due' => ['Instalment', 'money'], 'short' => ['Shortfall', 'money'], 'late' => ['Days late', 'num'], 'kind' => ['Kind', 'badge'], 'officer' => 'Officer'], $out, ['total_due', 'short']);
    }

    private static function receipt_register(array $f): array
    {
        $x = self::x($f);
        $w = ['p.business_date BETWEEN ? AND ?', $x['B']('p.branch_id') . loan_scope('l')]; $p = [$x['from'], $x['to']];
        if (!empty($f['cashier'])) { $w[] = 'p.cashier_id = ?'; $p[] = (int)$f['cashier']; }
        if (($f['method'] ?? '') !== '') { $w[] = 'p.method = ?'; $p[] = $f['method']; }
        return self::R($x['brCol'] + ['receipt_no' => 'Receipt', 'business_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'amount' => ['Amount', 'money'], 'method' => 'Channel', 'reference' => 'Reference', 'cashier' => 'Cashier', 'rstatus' => ['Receipt', 'badge'], 'status' => ['Payment', 'badge']],
            rows("SELECT br.name AS branch, r.receipt_no, p.business_date, l.loan_no, c.full_name, p.amount, p.method, p.reference, u.full_name AS cashier, r.status AS rstatus, p.status
                  FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id JOIN users u ON u.id = p.cashier_id JOIN branches br ON br.id = p.branch_id WHERE " . implode(' AND ', $w) . ' ORDER BY r.id', $p), ['amount']);
    }

    private static function repayment_history(array $f): array
    {
        $x = self::x($f);
        if ($x['loanNo'] === '') return self::R(['info' => 'Loan'], [['info' => 'Enter the loan number in the filters above.']]);
        $l = row("SELECT l.* FROM loans l WHERE l.loan_no = ? AND {$x['LB']}", [$x['loanNo']]);
        must((bool)$l, 'That loan is not in your authorized records.');
        return self::R(['business_date' => ['Business date', 'date'], 'receipt_no' => 'Receipt', 'method' => 'Channel', 'amount' => ['Amount', 'money'], 'principal' => ['Principal', 'money'], 'interest' => ['Interest', 'money'], 'penalty' => ['Penalty', 'money'], 'fee' => ['Fees', 'money'], 'cashier' => 'Cashier', 'status' => ['Status', 'badge']],
            rows("SELECT p.business_date, r.receipt_no, p.method, p.amount, p.status, u.full_name AS cashier,
                  COALESCE(SUM(CASE WHEN a.component = 'principal' THEN a.amount END),0) AS principal, COALESCE(SUM(CASE WHEN a.component = 'interest' THEN a.amount END),0) AS interest,
                  COALESCE(SUM(CASE WHEN a.component = 'penalty' THEN a.amount END),0) AS penalty, COALESCE(SUM(CASE WHEN a.component = 'fee' THEN a.amount END),0) AS fee
                  FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id LEFT JOIN payment_allocations a ON a.payment_id = p.id JOIN users u ON u.id = p.cashier_id WHERE p.loan_id = ?
                  GROUP BY p.id, p.business_date, r.receipt_no, p.method, p.amount, p.status, u.full_name ORDER BY p.id", [$l['id']]), ['amount', 'principal', 'interest', 'penalty', 'fee'], 'Loan ' . $l['loan_no'] . '. Reversed payments are shown with their status and are not part of the balance.');
    }

    // ================================================================= E. arrears
    private static function arrears_aging(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (['1â€“7 days' => [1, 7], '8â€“30 days' => [8, 30], '31â€“60 days' => [31, 60], '61â€“90 days' => [61, 90], 'Over 90 days' => [91, 99999]] as $label => [$lo, $hi]) {
            $r = row("SELECT COUNT(*) AS n, COALESCE(SUM(b.principal_out),0) AS pr, COALESCE(SUM(b.interest_out),0) AS it, COALESCE(SUM(b.penalty_out + b.fee_out),0) AS pf, COALESCE(SUM(b.total_out),0) AS tot
                      FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status = 'Overdue' AND l.overdue_since IS NOT NULL AND {$x['LB']} AND " . sql_daydiff('?', 'l.overdue_since') . ' BETWEEN ? AND ?', [$x['asOf'], $lo, $hi]);
            $out[] = ['bucket' => $label, 'n' => (int)$r['n'], 'pr' => (int)$r['pr'], 'it' => (int)$r['it'], 'pf' => (int)$r['pf'], 'tot' => (int)$r['tot']];
        }
        $tot = array_sum(array_column($out, 'tot'));
        foreach ($out as &$o) $o['share'] = $tot > 0 ? round($o['tot'] / $tot * 100, 1) . '%' : '0%';
        return self::R(['bucket' => 'Days past due', 'n' => ['Loans', 'num'], 'pr' => ['Principal', 'money'], 'it' => ['Interest', 'money'], 'pf' => ['Penalty + fees', 'money'], 'tot' => ['Total outstanding', 'money'], 'share' => ['Share', 'pct']], $out, ['n', 'pr', 'it', 'pf', 'tot']);
    }

    private static function followup_report(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['action_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => 'Action', 'notes' => 'Notes / outcome', 'promise_date' => ['Promised date', 'date'], 'promise_amount' => ['Promised', 'money'], 'who' => 'Recorded by', 'collection_status' => ['Collection status', 'badge']],
            rows("SELECT br.name AS branch, a.action_date, l.loan_no, c.full_name, a.type, a.notes, a.promise_date, a.promise_amount, u.full_name AS who, l.collection_status
                  FROM collection_actions a JOIN loans l ON l.id = a.loan_id JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = a.user_id JOIN branches br ON br.id = l.branch_id
                  WHERE a.action_date BETWEEN ? AND ? AND {$x['LB']}{$x['offW']} ORDER BY a.id DESC", [$x['from'], $x['to']]), ['promise_amount']);
    }

    private static function promises_report(array $f): array
    {
        $x = self::x($f);
        $rs = rows("SELECT br.name AS branch, a.action_date, l.id AS lid, l.loan_no, c.full_name, a.promise_date, a.promise_amount, u.full_name AS who FROM collection_actions a JOIN loans l ON l.id = a.loan_id JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id
                    WHERE a.type = 'Promise to Pay' AND a.promise_date BETWEEN ? AND ? AND {$x['LB']}{$x['offW']} ORDER BY a.promise_date", [$x['from'], $x['to']]);
        foreach ($rs as &$r) {
            $paid = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.loan_id = ? AND p.status = 'Posted' AND p.business_date BETWEEN ? AND ?", [$r['lid'], $r['action_date'], $r['promise_date']]);
            $r['received'] = $paid;
            $r['outcome'] = $r['promise_date'] >= $x['asOf'] && $paid < (int)$r['promise_amount'] ? 'Pending' : ($paid >= (int)$r['promise_amount'] ? 'Kept' : 'Broken');
        }
        unset($r);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'action_date' => ['Promise made', 'date'], 'promise_date' => ['Promised date', 'date'], 'promise_amount' => ['Promised', 'money'], 'received' => ['Received by then', 'money'], 'outcome' => ['Outcome', 'badge'], 'who' => 'Officer'], $rs, ['promise_amount', 'received']);
    }

    private static function recovery_performance(array $f): array
    {
        $x = self::x($f);
        $rs = rows("SELECT br.name AS branch, u.full_name AS officer, COUNT(DISTINCT l.id) AS loans,
                    COALESCE((SELECT SUM(b2.total_out) FROM loans l2 JOIN v_loan_balances b2 ON b2.loan_id = l2.id WHERE l2.officer_id = u.id AND l2.branch_id = br.id AND l2.status = 'Overdue'),0) AS overdue_bal,
                    COALESCE(SUM(p.amount),0) AS recovered
                    FROM payments p JOIN loans l ON l.id = p.loan_id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id
                    WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND (l.status = 'Overdue' OR l.overdue_since IS NOT NULL) AND {$x['LB']}{$x['offW']} GROUP BY br.id, br.name, u.id, u.full_name ORDER BY recovered DESC", [$x['from'], $x['to']]);
        foreach ($rs as &$r) $r['rate'] = ((int)$r['overdue_bal'] + (int)$r['recovered']) > 0 ? round($r['recovered'] / ((int)$r['overdue_bal'] + (int)$r['recovered']) * 100, 1) . '%' : 'â€“';
        unset($r);
        return self::R($x['brCol'] + ['officer' => 'Loan officer', 'loans' => ['Loans recovered on', 'num'], 'recovered' => ['Recovered (period)', 'money'], 'overdue_bal' => ['Still overdue', 'money'], 'rate' => ['Recovery rate', 'pct']], $rs, ['loans', 'recovered', 'overdue_bal'],
            'Recovery rate = recovered Ã· (recovered + still overdue).');
    }

    private static function adjustments_like(array $f, string $like, string $what): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['business_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => 'Type', 'amount' => ['Amount', 'money'], 'reason' => 'Reason', 'approver' => 'Approved by', 'creator' => 'Recorded by'],
            rows("SELECT br.name AS branch, a.business_date, l.loan_no, c.full_name, a.type, a.amount, a.reason, ua.full_name AS approver, uc.full_name AS creator
                  FROM loan_adjustments a JOIN loans l ON l.id = a.loan_id JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id LEFT JOIN users ua ON ua.id = a.approved_by LEFT JOIN users uc ON uc.id = a.created_by
                  WHERE a.type LIKE ? AND a.business_date BETWEEN ? AND ? AND {$x['LB']} ORDER BY a.id", [$like, $x['from'], $x['to']]), ['amount'], $what);
    }

    private static function restructured_loans(array $f): array { return self::adjustments_like($f, '%Restructur%', 'Recorded restructuring adjustments. Nothing is listed until restructuring is authorized and recorded.'); }
    private static function write_offs(array $f): array { return self::adjustments_like($f, '%Write%', 'Recorded write-off adjustments. Nothing is listed until a write-off is authorized and recorded.'); }

    private static function high_risk_accounts(array $f): array
    {
        $x = self::x($f); $out = [];
        $rs = rows("SELECT br.name AS branch, l.id AS lid, l.loan_no, c.id AS cid, c.full_name, c.phone, b.total_out, u.full_name AS officer, l.status, " . sql_daydiff('?', 'l.overdue_since') . " AS days
                    FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id
                    WHERE l.status IN ('Active','Overdue') AND {$x['LB']}{$x['offW']} ORDER BY b.total_out DESC", [$x['asOf']]);
        foreach ($rs as $r) {
            $why = [];
            if ($r['status'] === 'Overdue' && (int)$r['days'] > 30) $why[] = 'overdue ' . (int)$r['days'] . ' days';
            if ((int)val("SELECT COUNT(*) FROM collection_actions a WHERE a.loan_id = ? AND a.type = 'Promise to Pay' AND a.promise_date < ? AND COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = a.loan_id AND p.status = 'Posted' AND p.business_date BETWEEN a.action_date AND a.promise_date),0) < COALESCE(a.promise_amount,1)", [$r['lid'], $x['asOf']])) $why[] = 'broken promise to pay';
            if ((int)val("SELECT COUNT(*) FROM loans o WHERE o.client_id = ? AND o.status = 'Overdue'", [$r['cid']]) > 1) $why[] = 'several overdue loans';
            if ($why) $out[] = $r + ['why' => implode('; ', $why)];
        }
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'phone' => 'Phone', 'days' => ['Days overdue', 'num'], 'total_out' => ['Total owed', 'money'], 'why' => 'Risk indicators', 'officer' => 'Officer'], $out, ['total_out']);
    }

    // ================================================================= F. finance
    private static function cashbook(array $f): array
    {
        $x = self::x($f); $B = $x['B']('branch_id');
        $bal = (int)val("SELECT COALESCE(SUM(direction * amount),0) FROM cash_movements WHERE is_cash = 1 AND business_date < ? AND $B", [$x['from']]);
        $out = [['d' => '', 'what' => 'Opening cash balance', 'money_in' => 0, 'money_out' => 0, 'bal' => $bal]];
        foreach (rows("SELECT business_date AS d, SUM(CASE WHEN direction = 1 THEN amount ELSE 0 END) AS money_in, SUM(CASE WHEN direction = -1 THEN amount ELSE 0 END) AS money_out FROM cash_movements
                       WHERE is_cash = 1 AND business_date BETWEEN ? AND ? AND $B GROUP BY business_date ORDER BY business_date", [$x['from'], $x['to']]) as $r) {
            $bal += (int)$r['money_in'] - (int)$r['money_out'];
            $out[] = ['d' => $r['d'], 'what' => 'Day total', 'money_in' => (int)$r['money_in'], 'money_out' => (int)$r['money_out'], 'bal' => $bal];
        }
        $out[] = ['d' => '', 'what' => 'Closing cash balance', 'money_in' => 0, 'money_out' => 0, 'bal' => $bal];
        return self::R(['d' => ['Business date', 'date'], 'what' => 'Entry', 'money_in' => ['Receipts', 'money'], 'money_out' => ['Payments', 'money'], 'bal' => ['Cash balance', 'money']], $out, ['money_in', 'money_out'], 'Physical cash only. Mobile money and bank movements are reported separately (Cash Movement Report).');
    }

    private static function income_report(array $f): array
    {
        $x = self::x($f);
        $c = fn(string $comp) => (int)val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id JOIN loans l ON l.id = a.loan_id WHERE a.component = ? AND p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND {$x['LB']}", [$comp, $x['from'], $x['to']]);
        $fees = (int)val("SELECT COALESCE(SUM(d.processing_fee + d.application_fee + d.inspection_fee + d.other_deductions),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE d.business_date BETWEEN ? AND ? AND {$x['LB']}", [$x['from'], $x['to']]);
        $rows = [['label' => 'Interest received on repayments', 'amount' => $c('interest')], ['label' => 'Fees deducted at disbursement', 'amount' => $fees], ['label' => 'Fees paid through repayments', 'amount' => $c('fee')], ['label' => 'Penalties received', 'amount' => $c('penalty')]];
        $rows[] = ['label' => 'Total income received', 'amount' => array_sum(array_column($rows, 'amount'))];
        return self::R(['label' => 'Income line', 'amount' => ['Received (UGX)', 'money']], $rows, [], 'Cash basis: income actually received in the period. Accrued but unpaid interest is in the Interest Report. This is not a profit-and-loss statement.');
    }

    private static function expense_report(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['category' => 'Category', 'n' => ['Count', 'num'], 'total' => ['Total paid', 'money']],
            rows("SELECT br.name AS branch, c.name AS category, COUNT(*) AS n, SUM(e.amount) AS total FROM expenses e JOIN expense_categories c ON c.id = e.category_id JOIN branches br ON br.id = e.branch_id
                  WHERE e.status = 'Paid' AND e.paid_business_date BETWEEN ? AND ? AND " . $x['B']('e.branch_id') . ' GROUP BY br.name, c.id, c.name ORDER BY br.name, total DESC', [$x['from'], $x['to']]), ['n', 'total']);
    }

    private static function interest_report(array $f): array
    {
        $x = self::x($f);
        $charged = (int)val("SELECT COALESCE(SUM(l.interest_amount),0) FROM loans l WHERE l.disbursement_date BETWEEN ? AND ? AND l.disbursed_at IS NOT NULL AND {$x['LB']}", [$x['from'], $x['to']]);
        $coll = (int)val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id JOIN loans l ON l.id = a.loan_id WHERE a.component = 'interest' AND p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND {$x['LB']}", [$x['from'], $x['to']]);
        $out = (int)val("SELECT COALESCE(SUM(b.interest_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$x['LB']}");
        return self::R(['label' => 'Interest', 'amount' => ['UGX', 'money'], 'treatment' => 'Accounting treatment'], [
            ['label' => 'Charged: contractual interest on loans disbursed in the period', 'amount' => $charged, 'treatment' => 'Contractual (accrual basis)'],
            ['label' => 'Collected: interest received in the period', 'amount' => $coll, 'treatment' => 'Cash received'],
            ['label' => 'Outstanding: interest receivable on open loans (today)', 'amount' => $out, 'treatment' => 'Receivable â€” NOT cash']]);
    }

    private static function disbursement_recon(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['disb_no' => 'Voucher', 'business_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'method' => 'Channel', 'net_amount' => ['Net released (voucher)', 'money'], 'moved' => ['Recorded movement', 'money'], 'diff' => ['Difference', 'money'], 'result' => ['Result', 'badge']],
            array_map(function ($r) { $r['diff'] = (int)$r['net_amount'] - (int)$r['moved']; $r['result'] = $r['diff'] === 0 ? 'Reconciled' : 'DIFFERENCE'; return $r; },
                rows("SELECT br.name AS branch, d.disb_no, d.business_date, l.loan_no, d.method, d.net_amount,
                      COALESCE((SELECT SUM(m.amount) FROM cash_movements m WHERE m.ref_type = 'disbursement' AND m.ref_id = d.id AND m.direction = -1),0) AS moved
                      FROM disbursements d JOIN loans l ON l.id = d.loan_id JOIN branches br ON br.id = d.branch_id WHERE d.business_date BETWEEN ? AND ? AND " . $x['B']('d.branch_id') . loan_scope('l') . ' ORDER BY d.id', [$x['from'], $x['to']])),
            ['net_amount', 'moved', 'diff']);
    }

    private static function financial_adjustments(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['business_date' => ['Date', 'date'], 'kind' => 'Kind', 'ref' => 'Reference', 'amount' => ['Amount', 'money'], 'reason' => 'Reason', 'actor' => 'Recorded by', 'approver' => 'Approved by'],
            rows("SELECT t.* FROM (
                  SELECT br.name AS branch, a.business_date, a.type AS kind, l.loan_no AS ref, a.amount, a.reason, uc.full_name AS actor, ua.full_name AS approver, l.branch_id AS bid FROM loan_adjustments a JOIN loans l ON l.id = a.loan_id JOIN branches br ON br.id = l.branch_id LEFT JOIN users uc ON uc.id = a.created_by LEFT JOIN users ua ON ua.id = a.approved_by
                  UNION ALL SELECT br.name, p.reversal_business_date, 'Payment reversal', r.receipt_no, p.amount, p.reversal_reason, ur.full_name, NULL, p.branch_id FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN branches br ON br.id = p.branch_id LEFT JOIN users ur ON ur.id = p.reversed_by WHERE p.status = 'Reversed'
                  UNION ALL SELECT br.name, e.paid_business_date, 'Expense reversal', e.expense_no, e.amount, e.reversal_reason, ue.full_name, NULL, e.branch_id FROM expenses e JOIN branches br ON br.id = e.branch_id LEFT JOIN users ue ON ue.id = e.entered_by WHERE e.reversal_reason IS NOT NULL
                  ) t WHERE t.business_date BETWEEN ? AND ? AND " . $x['B']('t.bid') . ' ORDER BY t.business_date', [$x['from'], $x['to']]), ['amount']);
    }

    private static function cashier_summary(array $f): array
    {
        $x = self::x($f); $cw = !empty($f['cashier']) ? ' AND p.cashier_id = ' . (int)$f['cashier'] : '';
        return self::R($x['brCol'] + ['cashier' => 'Cashier', 'n' => ['Payments', 'num'], 'cash' => ['Cash', 'money'], 'noncash' => ['Non-cash', 'money'], 'reversed' => ['Reversed', 'money'], 'disbursed' => ['Disbursements released', 'money']],
            rows("SELECT br.name AS branch, u.full_name AS cashier, SUM(CASE WHEN p.status = 'Posted' THEN 1 ELSE 0 END) AS n, SUM(CASE WHEN p.status = 'Posted' AND p.method = 'Cash' THEN p.amount ELSE 0 END) AS cash,
                  SUM(CASE WHEN p.status = 'Posted' AND p.method <> 'Cash' THEN p.amount ELSE 0 END) AS noncash, SUM(CASE WHEN p.status = 'Reversed' THEN p.amount ELSE 0 END) AS reversed,
                  COALESCE((SELECT SUM(d.net_amount) FROM disbursements d WHERE d.disbursed_by = u.id AND d.business_date BETWEEN ? AND ? AND d.branch_id = br.id),0) AS disbursed
                  FROM payments p JOIN users u ON u.id = p.cashier_id JOIN branches br ON br.id = p.branch_id WHERE p.business_date BETWEEN ? AND ? AND " . $x['B']('p.branch_id') . "$cw GROUP BY br.id, br.name, u.id, u.full_name ORDER BY u.full_name",
                [$x['from'], $x['to'], $x['from'], $x['to']]), ['n', 'cash', 'noncash', 'reversed', 'disbursed']);
    }

    private static function channel_recon(array $f): array
    {
        $x = self::x($f);
        return self::R(['method' => 'Channel', 'pay_n' => ['Payments', 'num'], 'payments' => ['Payments recorded', 'money'], 'moved' => ['Cash/channel movements', 'money'], 'diff' => ['Difference', 'money'], 'result' => ['Result', 'badge']],
            array_map(function ($r) { $r['diff'] = (int)$r['payments'] - (int)$r['moved']; $r['result'] = $r['diff'] === 0 ? 'Reconciled' : 'DIFFERENCE'; return $r; },
                rows("SELECT p.method, COUNT(*) AS pay_n, SUM(p.amount) AS payments,
                      COALESCE((SELECT SUM(m.amount) FROM cash_movements m WHERE m.category = 'collection' AND m.direction = 1 AND m.method = p.method AND m.business_date BETWEEN ? AND ? AND " . $x['B']('m.branch_id') . "),0) AS moved
                      FROM payments p WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND " . $x['B']('p.branch_id') . ' GROUP BY p.method ORDER BY p.method', [$x['from'], $x['to'], $x['from'], $x['to']])), ['pay_n', 'payments', 'moved', 'diff']);
    }

    private static function control_summary(array $f): array
    {
        $x = self::x($f); $B = $x['B']('branch_id');
        $rows = [];
        foreach (rows("SELECT a.name, a.type, COALESCE(SUM(m.direction * m.amount),0) AS bal FROM cash_accounts a LEFT JOIN cash_movements m ON m.account_id = a.id WHERE a.active = 1 AND " . $x['B']('a.branch_id') . ' GROUP BY a.id, a.name, a.type ORDER BY a.name') as $r)
            $rows[] = ['control' => 'Account balance â€” ' . $r['name'], 'value' => (int)$r['bal'], 'status' => 'Info'];
        $um = (int)val("SELECT COUNT(*) FROM cashier_sessions WHERE status IN ('Open','Submitted','Investigation') AND $B");
        $rows[] = ['control' => 'Cashier sessions not yet reconciled', 'value' => $um, 'status' => $um ? 'Attention' : 'OK'];
        $var = (int)val("SELECT COALESCE(SUM(ABS(variance)),0) FROM cashier_sessions WHERE variance IS NOT NULL AND business_date BETWEEN ? AND ? AND $B", [$x['from'], $x['to']]);
        $rows[] = ['control' => 'Total cash variance in the period (absolute)', 'value' => $var, 'status' => $var ? 'Attention' : 'OK'];
        $rv = (int)val("SELECT COUNT(*) FROM payments WHERE status = 'Reversed' AND reversal_business_date BETWEEN ? AND ? AND $B", [$x['from'], $x['to']]);
        $rows[] = ['control' => 'Payments reversed in the period (count)', 'value' => $rv, 'status' => $rv ? 'Attention' : 'OK'];
        $ints = Backup::integrityReport();
        $rows[] = ['control' => 'Ledger integrity exceptions', 'value' => count($ints), 'status' => $ints ? 'DIFFERENCE' : 'OK'];
        return self::R(['control' => 'Control', 'value' => ['Value', 'num'], 'status' => ['Status', 'badge']], $rows, [], 'Account balances are in UGX; other lines are counts or UGX as labelled.');
    }

    private static function gl_statements(array $f): array
    {
        must(can('gl.view'), 'You do not have access to the general ledger.');
        Gl::seedChart();
        $only = ($f['branch'] ?? 0) ? (int)$f['branch'] : null;
        if ($only && !in_array($only, accessible_branch_ids(), true)) $only = null;
        $from = $f['from']; $to = $f['to'];
        $view = isset(Gl::VIEWS[$f['view'] ?? '']) ? $f['view'] : 'tb';
        $unposted = Gl::pendingCount($only);
        $warn = $unposted ? ' ' . $unposted . ' business event(s) are not yet posted to the ledger (they post at End of Day), so figures may be incomplete.' : '';
        $dc = fn(int $v) => $v >= 0 ? [$v, 0] : [0, -$v];
        if ($view === 'tb') {
            $rows = [];
            foreach (Gl::trialBalance($from, $to, $only) as $r) {
                [$cd, $cc] = $dc($r['closing']);
                $rows[] = ['code' => $r['code'], 'name' => $r['name'], 'type' => Gl::TYPES[$r['type']], 'opening' => $r['opening'], 'debit' => $r['debit'], 'credit' => $r['credit'], 'dr' => $cd, 'cr' => $cc];
            }
            $diff = array_sum(array_column($rows, 'dr')) - array_sum(array_column($rows, 'cr'));
            return self::R(['code' => 'Code', 'name' => 'Account', 'type' => 'Type', 'opening' => ['Opening (Dr+/Cr-)', 'money'], 'debit' => ['Period debits', 'money'], 'credit' => ['Period credits', 'money'], 'dr' => ['Closing debit', 'money'], 'cr' => ['Closing credit', 'money']],
                $rows, ['opening', 'debit', 'credit', 'dr', 'cr'], ($diff === 0 ? 'The trial balance agrees: total debits equal total credits.' : 'DIFFERENCE of ' . ugx($diff) . ' between closing debits and credits - investigate.') . $warn);
        }
        if ($view === 'is') {
            $rows = []; $sum = ['income' => 0, 'expense' => 0];
            $tb = Gl::trialBalance($from, $to, $only);
            foreach (['income' => 'Income', 'expense' => 'Expenses'] as $type => $label) {
                $rows[] = ['label' => $label, 'amount' => ''];
                foreach ($tb as $r) {
                    if ($r['type'] !== $type) continue;
                    $amt = $type === 'income' ? $r['credit'] - $r['debit'] : $r['debit'] - $r['credit'];
                    if ($amt === 0) continue;
                    $rows[] = ['label' => '   ' . $r['code'] . ' · ' . $r['name'], 'amount' => $amt]; $sum[$type] += $amt;
                }
                $rows[] = ['label' => 'Total ' . strtolower($label), 'amount' => $sum[$type]];
            }
            $rows[] = ['label' => $sum['income'] - $sum['expense'] >= 0 ? 'Net profit for the period' : 'Net loss for the period', 'amount' => $sum['income'] - $sum['expense']];
            return self::R(['label' => 'Line', 'amount' => ['UGX', 'money']], $rows, [], 'Cash basis: interest, fees and penalties are income when received. Unpaid amounts are in the balance sheet as receivables and unearned income.' . $warn);
        }
        if ($view === 'bs') {
            $bal = Gl::trialBalance(null, $to, $only);
            $rows = []; $tot = ['asset' => 0, 'liability' => 0, 'equity' => 0]; $profit = 0;
            foreach ($bal as $r) { if ($r['type'] === 'income') $profit += -$r['closing']; elseif ($r['type'] === 'expense') $profit -= $r['closing']; }
            foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $type => $label) {
                $rows[] = ['label' => $label, 'amount' => ''];
                foreach ($bal as $r) {
                    if ($r['type'] !== $type) continue;
                    $amt = $type === 'asset' ? $r['closing'] : -$r['closing'];
                    if ($amt === 0) continue;
                    $rows[] = ['label' => '   ' . $r['code'] . ' · ' . $r['name'], 'amount' => $amt]; $tot[$type] += $amt;
                }
                if ($type === 'equity') { $rows[] = ['label' => '   Profit / (loss) not yet closed to retained earnings', 'amount' => $profit]; $tot['equity'] += $profit; }
                $rows[] = ['label' => 'Total ' . strtolower($label), 'amount' => $tot[$type]];
            }
            $d = $tot['asset'] - $tot['liability'] - $tot['equity'];
            return self::R(['label' => 'Line', 'amount' => ['UGX', 'money']], $rows, [], ($d === 0 ? 'The balance sheet balances: assets equal liabilities plus equity.' : 'DIFFERENCE of ' . ugx($d) . ' between assets and liabilities plus equity - investigate.') . ' As at ' . fmt_date($to) . '.' . $warn);
        }
        $sc = Gl::lineScope($only);
        if ($view === 'gl') {
            $rows = rows("SELECT a.code, a.name AS account, j.journal_date, j.journal_no, COALESCE(l.description, j.memo) AS memo, l.debit, l.credit FROM gl_journal_lines l
                          JOIN gl_journals j ON j.id = l.journal_id JOIN gl_accounts a ON a.id = l.account_id
                          WHERE j.journal_date BETWEEN ? AND ? AND $sc ORDER BY a.code, j.journal_date, j.id, l.id LIMIT 5000", [$from, $to]);
            return self::R(['code' => 'Code', 'account' => 'Account', 'journal_date' => ['Date', 'date'], 'journal_no' => 'Journal', 'memo' => 'Description', 'debit' => ['Debit', 'money'], 'credit' => ['Credit', 'money']], $rows, ['debit', 'credit'],
                'Every posting in the period, grouped by account.' . (count($rows) >= 5000 ? ' Showing the first 5,000 lines; narrow the dates.' : '') . $warn);
        }
        $rows = rows("SELECT j.journal_date, j.journal_no, COALESCE(b.name, 'Head office') AS branch, a.code, a.name AS account, COALESCE(l.description, j.memo) AS memo, l.debit, l.credit
                      FROM gl_journal_lines l JOIN gl_journals j ON j.id = l.journal_id JOIN gl_accounts a ON a.id = l.account_id LEFT JOIN branches b ON b.id = l.branch_id
                      WHERE j.journal_date BETWEEN ? AND ? AND $sc ORDER BY j.journal_date, j.id, l.id LIMIT 5000", [$from, $to]);
        return self::R(['journal_date' => ['Date', 'date'], 'journal_no' => 'Journal', 'branch' => 'Branch', 'code' => 'Code', 'account' => 'Account', 'memo' => 'Description', 'debit' => ['Debit', 'money'], 'credit' => ['Credit', 'money']], $rows, ['debit', 'credit'],
            'All journals posted in the period.' . (count($rows) >= 5000 ? ' Showing the first 5,000 lines; narrow the dates.' : '') . $warn);
    }

    // ================================================================= G. end of day
    private static function daily_ops_summary(array $f): array
    {
        $x = self::x($f); $B = fn($c) => $x['B']($c);
        $rs = rows("SELECT d.business_date AS d, br.name AS branch, d.branch_id AS bid FROM business_days d JOIN branches br ON br.id = d.branch_id WHERE d.business_date BETWEEN ? AND ? AND " . $B('d.branch_id') . ' ORDER BY d.business_date, br.name', [$x['from'], $x['to']]);
        foreach ($rs as &$r) {
            $r['apps'] = (int)val("SELECT COUNT(*) FROM loans l WHERE l.application_date = ? AND l.branch_id = ?" . loan_scope('l'), [$r['d'], $r['bid']]);
            $r['disb'] = (int)val("SELECT COALESCE(SUM(d.net_amount),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE d.business_date = ? AND d.branch_id = ?" . loan_scope('l'), [$r['d'], $r['bid']]);
            $r['coll'] = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN loans l ON l.id = p.loan_id WHERE p.status = 'Posted' AND p.business_date = ? AND p.branch_id = ?" . loan_scope('l'), [$r['d'], $r['bid']]);
            $r['exp'] = (int)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'Paid' AND paid_business_date = ? AND branch_id = ?", [$r['d'], $r['bid']]);
        }
        unset($r);
        return self::R($x['brCol'] + ['d' => ['Business date', 'date'], 'apps' => ['Applications', 'num'], 'disb' => ['Disbursed (net)', 'money'], 'coll' => ['Collections', 'money'], 'exp' => ['Expenses paid', 'money']], $rs, ['apps', 'disb', 'coll', 'exp']);
    }

    private static function cashier_closing(array $f): array
    {
        $x = self::x($f); $cw = !empty($f['cashier']) ? ' AND s.cashier_id = ' . (int)$f['cashier'] : '';
        return self::R($x['brCol'] + ['business_date' => ['Business date', 'date'], 'session_no' => 'Session', 'cashier' => 'Cashier', 'opening_float' => ['Opening float', 'money'], 'receipts' => ['Cash receipts', 'money'], 'payments' => ['Cash paid out', 'money'], 'expected_cash' => ['Expected', 'money'], 'counted_cash' => ['Counted', 'money'], 'variance' => ['Variance', 'money'], 'status' => ['Status', 'badge']],
            rows("SELECT br.name AS branch, s.business_date, s.session_no, u.full_name AS cashier, s.opening_float,
                  COALESCE((SELECT SUM(m.amount) FROM cash_movements m WHERE m.session_id = s.id AND m.is_cash = 1 AND m.direction = 1 AND m.category <> 'float'),0) AS receipts,
                  COALESCE((SELECT SUM(m.amount) FROM cash_movements m WHERE m.session_id = s.id AND m.is_cash = 1 AND m.direction = -1),0) AS payments, s.expected_cash, s.counted_cash, s.variance, s.status
                  FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id JOIN branches br ON br.id = s.branch_id WHERE s.business_date BETWEEN ? AND ? AND " . $x['B']('s.branch_id') . "$cw ORDER BY s.business_date, s.id", [$x['from'], $x['to']]), ['opening_float', 'receipts', 'payments', 'variance']);
    }

    private static function branch_eod_status(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['business_date' => ['Business date', 'date'], 'status' => ['Day status', 'badge'], 'eod' => ['EOD', 'badge'], 'opener' => 'Opened by', 'closed_at' => ['Closed', 'dt'], 'closer' => 'Closed by'],
            array_map(function ($r) { $r['eod'] = Eod::status($r['business_date'], (int)$r['bid']); return $r; },
                rows("SELECT br.name AS branch, d.branch_id AS bid, d.business_date, d.status, uo.full_name AS opener, d.closed_at, uc.full_name AS closer FROM business_days d JOIN branches br ON br.id = d.branch_id LEFT JOIN users uo ON uo.id = d.opened_by LEFT JOIN users uc ON uc.id = d.closed_by
                      WHERE d.business_date BETWEEN ? AND ? AND " . $x['B']('d.branch_id') . ' ORDER BY d.business_date, br.name', [$x['from'], $x['to']])));
    }

    private static function reopened_dates(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['business_date' => ['Business date', 'date'], 'requested_at' => ['Requested', 'dt'], 'requester' => 'Requested by', 'reason' => 'Reason', 'status' => ['Decision', 'badge'], 'decider' => 'Authorized by', 'decided_at' => ['Decided', 'dt'], 'decision_notes' => 'Notes'],
            rows("SELECT br.name AS branch, r.business_date, r.requested_at, u.full_name AS requester, r.reason, r.status, d.full_name AS decider, r.decided_at, r.decision_notes FROM eod_reopen_requests r JOIN branches br ON br.id = r.branch_id JOIN users u ON u.id = r.requested_by LEFT JOIN users d ON d.id = r.decided_by
                  WHERE r.business_date BETWEEN ? AND ? AND " . $x['B']('r.branch_id') . ' ORDER BY r.id', [$x['from'], $x['to']]));
    }

    private static function eod_exceptions(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (rows("SELECT br.name AS branch, s.business_date, s.session_no, u.full_name AS who, s.status, s.variance, s.variance_reason FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id JOIN branches br ON br.id = s.branch_id
                       WHERE s.business_date BETWEEN ? AND ? AND (s.status IN ('Open','Submitted','Investigation') OR (s.variance IS NOT NULL AND s.variance <> 0)) AND " . $x['B']('s.branch_id') . ' ORDER BY s.business_date', [$x['from'], $x['to']]) as $r)
            $out[] = ['branch' => $r['branch'], 'business_date' => $r['business_date'], 'kind' => in_array($r['status'], ['Open', 'Submitted', 'Investigation'], true) ? 'Till not reconciled (' . $r['status'] . ')' : 'Cash variance', 'ref' => $r['session_no'], 'who' => $r['who'], 'amount' => (int)$r['variance'], 'detail' => (string)$r['variance_reason']];
        foreach (rows("SELECT br.name AS branch, d.business_date FROM business_days d JOIN branches br ON br.id = d.branch_id WHERE d.status = 'Open' AND d.business_date < ? AND d.business_date BETWEEN ? AND ? AND " . $x['B']('d.branch_id'), [$x['asOf'], $x['from'], $x['to']]) as $r)
            $out[] = ['branch' => $r['branch'], 'business_date' => $r['business_date'], 'kind' => 'Business date still open (End-of-Day not closed)', 'ref' => '', 'who' => '', 'amount' => 0, 'detail' => ''];
        foreach (rows("SELECT br.name AS branch, e.business_date, e.status, e.backup_status FROM eod_records e JOIN branches br ON br.id = e.branch_id WHERE e.business_date BETWEEN ? AND ? AND (e.status = 'Variance Investigation' OR e.backup_status LIKE 'FAILED%') AND " . $x['B']('e.branch_id'), [$x['from'], $x['to']]) as $r)
            $out[] = ['branch' => $r['branch'], 'business_date' => $r['business_date'], 'kind' => $r['status'] === 'Variance Investigation' ? 'Returned for variance investigation' : 'End-of-Day backup failed', 'ref' => '', 'who' => '', 'amount' => 0, 'detail' => (string)$r['backup_status']];
        usort($out, fn($a, $b) => strcmp($a['business_date'], $b['business_date']));
        return self::R($x['brCol'] + ['business_date' => ['Business date', 'date'], 'kind' => 'Exception', 'ref' => 'Reference', 'who' => 'Person', 'amount' => ['Variance', 'money'], 'detail' => 'Detail'], $out, ['amount']);
    }

    // ================================================================= H. branch and staff performance
    private static function cashier_performance(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['cashier' => 'Cashier', 'sessions' => ['Sessions', 'num'], 'tx' => ['Transactions', 'num'], 'variance' => ['Net variance', 'money'], 'abs_var' => ['Absolute variance', 'money'], 'open_n' => ['Not reconciled', 'num']],
            rows("SELECT br.name AS branch, u.full_name AS cashier, COUNT(*) AS sessions,
                  COALESCE((SELECT COUNT(*) FROM payments p WHERE p.cashier_id = u.id AND p.branch_id = br.id AND p.business_date BETWEEN ? AND ?),0) AS tx,
                  COALESCE(SUM(s.variance),0) AS variance, COALESCE(SUM(ABS(s.variance)),0) AS abs_var, SUM(CASE WHEN s.status <> 'Reconciled' THEN 1 ELSE 0 END) AS open_n
                  FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id JOIN branches br ON br.id = s.branch_id WHERE s.business_date BETWEEN ? AND ? AND " . $x['B']('s.branch_id') . ' GROUP BY br.id, br.name, u.id, u.full_name ORDER BY u.full_name',
                [$x['from'], $x['to'], $x['from'], $x['to']]), ['sessions', 'tx', 'variance', 'abs_var', 'open_n']);
    }

    private static function collection_targets(array $f): array
    {
        $x = self::x($f); $months = count(self::months($x['from'], $x['to'])); $out = [];
        foreach (accessible_branch_ids() as $bid) {
            if ($x['only'] !== null && !in_array($bid, $x['only'], true)) continue;
            $t = (int)val('SELECT COALESCE(collection_target, 0) FROM branches WHERE id = ?', [$bid]) * $months;
            $a = (int)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'Posted' AND branch_id = ? AND business_date BETWEEN ? AND ?", [$bid, $x['from'], $x['to']]);
            $out[] = ['level' => 'Branch', 'name' => branch_name($bid), 'target' => $t, 'actual' => $a, 'ach' => $t > 0 ? round($a / $t * 100, 1) . '%' : 'no target'];
        }
        foreach (rows("SELECT u.id, u.full_name, COALESCE(u.collection_target,0) AS t FROM users u WHERE u.collection_target IS NOT NULL AND u.collection_target > 0 AND u.status = 'active' ORDER BY u.full_name") as $u) {
            $a = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN loans l ON l.id = p.loan_id WHERE p.status = 'Posted' AND l.officer_id = ? AND p.business_date BETWEEN ? AND ? AND {$x['LB']}", [$u['id'], $x['from'], $x['to']]);
            $t = (int)$u['t'] * $months;
            $out[] = ['level' => 'Loan officer', 'name' => $u['full_name'], 'target' => $t, 'actual' => $a, 'ach' => round($a / $t * 100, 1) . '%'];
        }
        return self::R(['level' => 'Level', 'name' => 'Name', 'target' => ['Target', 'money'], 'actual' => ['Actual collections', 'money'], 'ach' => ['Achievement', 'pct']], $out, ['target', 'actual'], 'Target for the period = monthly target Ã— ' . $months . ' month(s). Officers are credited with collections on loans assigned to them.');
    }

    private static function staff_activity(array $f): array
    {
        $x = self::x($f);
        return self::R(['name' => 'Staff member', 'role' => 'Role', 'actions' => ['Recorded actions', 'num'], 'logins' => ['Sign-ins', 'num'], 'payments' => ['Payments recorded', 'num'], 'apps' => ['Applications created', 'num'], 'last_at' => ['Last action', 'dt']],
            rows("SELECT u.full_name AS name, u.role, COUNT(a.id) AS actions, COALESCE((SELECT COUNT(*) FROM login_events e WHERE e.user_id = u.id AND e.success = 1 AND substr(e.at,1,10) BETWEEN ? AND ?),0) AS logins,
                  COALESCE((SELECT COUNT(*) FROM payments p WHERE p.cashier_id = u.id AND p.business_date BETWEEN ? AND ? AND " . $x['B']('p.branch_id') . "),0) AS payments,
                  COALESCE((SELECT COUNT(*) FROM loans l WHERE l.officer_id = u.id AND l.application_date BETWEEN ? AND ? AND " . $x['B']('l.branch_id') . "),0) AS apps, MAX(a.at) AS last_at
                  FROM users u LEFT JOIN audit_logs a ON a.user_id = u.id AND substr(a.at,1,10) BETWEEN ? AND ? WHERE u.id IN (SELECT user_id FROM user_branches WHERE " . $x['B']('branch_id') . ") OR u.role IN ('COO') GROUP BY u.id, u.full_name, u.role ORDER BY actions DESC",
                [$x['from'], $x['to'], $x['from'], $x['to'], $x['from'], $x['to'], $x['from'], $x['to']]), ['actions', 'logins', 'payments', 'apps']);
    }

    private static function branch_arrears(array $f): array
    {
        $x = self::x($f);
        return self::R(['branch' => 'Branch', 'n' => ['Overdue loans', 'num'], 'principal' => ['Principal', 'money'], 'total' => ['Total owed', 'money'], 'oldest' => ['Oldest overdue (days)', 'num'], 'par' => ['Share of branch principal', 'pct']],
            array_map(function ($r) { $r['par'] = (int)$r['branch_out'] > 0 ? round($r['principal'] / $r['branch_out'] * 100, 1) . '%' : '0%'; return $r; },
                rows("SELECT br.name AS branch, COUNT(*) AS n, SUM(b.principal_out) AS principal, SUM(b.total_out) AS total, MAX(" . sql_daydiff('?', 'l.overdue_since') . ") AS oldest,
                      (SELECT COALESCE(SUM(b2.principal_out),0) FROM loans l2 JOIN v_loan_balances b2 ON b2.loan_id = l2.id WHERE l2.branch_id = br.id AND l2.status IN ('Active','Overdue')) AS branch_out
                      FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id JOIN branches br ON br.id = l.branch_id WHERE l.status = 'Overdue' AND {$x['LB']} GROUP BY br.id, br.name ORDER BY total DESC", [$x['asOf']])), ['n', 'principal', 'total']);
    }

    private static function branch_eod_compliance(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (accessible_branch_ids() as $bid) {
            if ($x['only'] !== null && !in_array($bid, $x['only'], true)) continue;
            $days = (int)val("SELECT COUNT(*) FROM business_days WHERE branch_id = ? AND business_date BETWEEN ? AND ?", [$bid, $x['from'], $x['to']]);
            $closed = (int)val("SELECT COUNT(*) FROM business_days WHERE branch_id = ? AND status = 'Closed' AND business_date BETWEEN ? AND ?", [$bid, $x['from'], $x['to']]);
            $late = (int)val("SELECT COUNT(*) FROM business_days WHERE branch_id = ? AND status = 'Closed' AND business_date BETWEEN ? AND ? AND substr(closed_at,1,10) > business_date", [$bid, $x['from'], $x['to']]);
            $reopen = (int)val("SELECT COUNT(*) FROM eod_reopen_requests WHERE branch_id = ? AND business_date BETWEEN ? AND ?", [$bid, $x['from'], $x['to']]);
            $unrec = (int)val("SELECT COUNT(*) FROM cashier_sessions WHERE branch_id = ? AND status <> 'Reconciled' AND business_date BETWEEN ? AND ?", [$bid, $x['from'], $x['to']]);
            $out[] = ['branch' => branch_name($bid), 'days' => $days, 'closed' => $closed, 'late' => $late, 'reopen' => $reopen, 'unrec' => $unrec, 'rate' => $days ? round($closed / $days * 100) . '%' : 'â€“'];
        }
        return self::R(['branch' => 'Branch', 'days' => ['Business days', 'num'], 'closed' => ['Closed', 'num'], 'late' => ['Closed after the day', 'num'], 'reopen' => ['Reopen requests', 'num'], 'unrec' => ['Tills not reconciled', 'num'], 'rate' => ['Completion', 'pct']], $out, ['days', 'closed', 'late', 'reopen', 'unrec']);
    }

    // ================================================================= I. guarantors and collateral
    private static function collateral_valuation(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['security_no' => 'Security', 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => 'Type', 'description' => 'Description', 'est_value' => ['Recorded value', 'money'], 'valuation_date' => ['Valuation date', 'date'], 'age' => ['Valuation age (days)', 'num'], 'recorder' => 'Recorded by', 'status' => ['Status', 'badge']],
            rows("SELECT br.name AS branch, s.security_no, l.loan_no, c.full_name, s.type, s.description, s.est_value, s.valuation_date, CASE WHEN s.valuation_date IS NULL THEN NULL ELSE " . sql_daydiff('?', 's.valuation_date') . " END AS age, u.full_name AS recorder, s.status
                  FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id JOIN branches br ON br.id = l.branch_id LEFT JOIN users u ON u.id = s.created_by WHERE {$x['LB']} ORDER BY s.security_no", [$x['asOf']]), ['est_value']);
    }

    private static function missing_security_docs(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['security_no' => 'Security', 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => 'Type', 'missing' => 'Missing', 'status' => ['Status', 'badge']],
            array_values(array_filter(array_map(function ($r) {
                $m = []; if (!(int)$r['docs']) $m[] = 'supporting document'; if (!$r['valuation_date']) $m[] = 'valuation date'; if (!$r['storage_location'] && !in_array($r['type'], ['Guarantee'], true)) $m[] = 'storage location';
                return $m ? $r + ['missing' => implode(', ', $m)] : null;
            }, rows("SELECT br.name AS branch, s.security_no, l.loan_no, c.full_name, s.type, s.status, s.valuation_date, s.storage_location, (SELECT COUNT(*) FROM attachments a WHERE a.entity_type = 'security' AND a.entity_id = s.id) AS docs
                     FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id JOIN branches br ON br.id = l.branch_id WHERE s.status NOT IN ('Released') AND {$x['LB']} ORDER BY s.security_no")))));
    }

    private static function expiring_documents(array $f): array
    {
        $x = self::x($f); $days = $x['days'] ?: 30; $out = [];
        foreach (rows("SELECT br.name AS branch, s.security_no AS ref, l.loan_no, c.full_name, s.valuation_date FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id JOIN branches br ON br.id = l.branch_id
                       WHERE s.status NOT IN ('Released') AND s.valuation_date IS NOT NULL AND s.valuation_date < ? AND {$x['LB']}", [date('Y-m-d', strtotime('-12 months', strtotime($x['asOf'])))]) as $r)
            $out[] = ['branch' => $r['branch'], 'ref' => $r['ref'], 'loan_no' => $r['loan_no'], 'full_name' => $r['full_name'], 'what' => 'Collateral valuation older than 12 months', 'date' => $r['valuation_date']];
        foreach (rows("SELECT br.name AS branch, l.loan_no AS ref, l.loan_no, c.full_name, l.offer_expiry FROM loans l JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id
                       WHERE l.status = 'Approved' AND l.offer_expiry IS NOT NULL AND l.offer_expiry BETWEEN ? AND ? AND {$x['LB']}", [$x['asOf'], Calc::addDays($x['asOf'], $days)]) as $r)
            $out[] = ['branch' => $r['branch'], 'ref' => $r['ref'], 'loan_no' => $r['loan_no'], 'full_name' => $r['full_name'], 'what' => 'Loan offer expires', 'date' => $r['offer_expiry']];
        return self::R($x['brCol'] + ['ref' => 'Reference', 'loan_no' => 'Loan', 'full_name' => 'Client', 'what' => 'Needs review / renewal', 'date' => ['Date', 'date']], $out);
    }

    private static function released_collateral(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['security_no' => 'Security', 'loan_no' => 'Loan', 'full_name' => 'Client', 'description' => 'Asset', 'date_released' => ['Released', 'date'], 'releaser' => 'Released by', 'release_authorization' => 'Authorization / evidence', 'est_value' => ['Value', 'money']],
            rows("SELECT br.name AS branch, s.security_no, l.loan_no, c.full_name, s.description, s.date_released, u.full_name AS releaser, s.release_authorization, s.est_value FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id JOIN branches br ON br.id = l.branch_id LEFT JOIN users u ON u.id = s.released_by
                  WHERE s.status = 'Released' AND s.date_released BETWEEN ? AND ? AND {$x['LB']} ORDER BY s.date_released", [$x['from'], $x['to']]), ['est_value']);
    }

    // ================================================================= J. management and risk analytics
    private static function exec_dashboard(array $f): array
    {
        $x = self::x($f); $s = Dashboard::summary($x['only']);
        $par30 = self::parPct($x, 30);
        $rows = [['kpi' => 'Clients', 'value' => num($s['clients']), 'basis' => 'Registered clients'], ['kpi' => 'Open loans', 'value' => num($s['active']), 'basis' => 'Active + overdue'], ['kpi' => 'Overdue loans', 'value' => num($s['overdue']), 'basis' => 'Past maturity'],
            ['kpi' => 'Total disbursed (cumulative)', 'value' => ugx($s['disbursed_total']), 'basis' => 'All disbursements to date, gross'], ['kpi' => 'Principal outstanding', 'value' => ugx($s['principal_out']), 'basis' => 'Open loans, ledger'],
            ['kpi' => 'Overdue balance', 'value' => ugx($s['overdue_balance']), 'basis' => 'Total owed on overdue loans'], ['kpi' => 'PAR30', 'value' => $par30 . '%', 'basis' => 'Principal >30 days past maturity Ã· gross principal outstanding'],
            ['kpi' => 'Pending applications', 'value' => num($s['pending_apps']), 'basis' => 'Submitted + under review']];
        if (isset($s['coll_today'])) $rows[] = ['kpi' => 'Collections today', 'value' => ugx($s['coll_today']), 'basis' => 'Posted ' . fmt_date($x['asOf'])];
        return self::R(['kpi' => 'Indicator', 'value' => 'Value', 'basis' => 'Calculation basis'], $rows);
    }

    private static function parPct(array $x, int $n): float
    {
        $r = row("SELECT COALESCE(SUM(CASE WHEN l.overdue_since IS NOT NULL AND " . sql_daydiff('?', 'l.overdue_since') . " > ? THEN b.principal_out ELSE 0 END),0) AS par, COALESCE(SUM(b.principal_out),0) AS tot
                  FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$x['LB']}", [$x['asOf'], $n]);
        return (int)$r['tot'] > 0 ? round($r['par'] / $r['tot'] * 100, 2) : 0.0;
    }

    private static function par(array $f): array
    {
        $x = self::x($f); $tot = (int)val("SELECT COALESCE(SUM(b.principal_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$x['LB']}"); $out = [];
        foreach ([1, 30, 60, 90] as $n) {
            $r = row("SELECT COUNT(*) AS n, COALESCE(SUM(b.principal_out),0) AS pr FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status = 'Overdue' AND l.overdue_since IS NOT NULL AND {$x['LB']} AND " . sql_daydiff('?', 'l.overdue_since') . ' > ?', [$x['asOf'], $n]);
            $out[] = ['measure' => 'PAR' . $n . ' (more than ' . $n . ' days past maturity)', 'n' => (int)$r['n'], 'pr' => (int)$r['pr'], 'gross' => $tot, 'pct' => $tot > 0 ? round($r['pr'] / $tot * 100, 2) . '%' : '0%'];
        }
        return self::R(['measure' => 'Measure', 'n' => ['Loans at risk', 'num'], 'pr' => ['Principal at risk', 'money'], 'gross' => ['Gross principal outstanding', 'money'], 'pct' => ['PAR %', 'pct']], $out, [], 'PARn = outstanding principal of loans more than n days past maturity Ã· gross outstanding principal of all open loans.');
    }

    private static function concentration(array $f): array
    {
        $x = self::x($f);
        $rs = rows("SELECT br.name AS segment, 'Branch' AS dim, COUNT(*) AS n, COALESCE(SUM(b.principal_out),0) AS pr FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id JOIN branches br ON br.id = l.branch_id WHERE l.status IN ('Active','Overdue') AND {$x['LB']} GROUP BY br.name
                    UNION ALL SELECT l.frequency, 'Product (frequency)', COUNT(*), COALESCE(SUM(b.principal_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$x['LB']} GROUP BY l.frequency
                    UNION ALL SELECT COALESCE(c.occupation, 'Not recorded'), 'Borrower segment (occupation)', COUNT(*), COALESCE(SUM(b.principal_out),0) FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$x['LB']} GROUP BY COALESCE(c.occupation, 'Not recorded')");
        $tot = (int)val("SELECT COALESCE(SUM(b.principal_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$x['LB']}");
        foreach ($rs as &$r) $r['share'] = $tot > 0 ? round($r['pr'] / $tot * 100, 1) . '%' : '0%';
        usort($rs, fn($a, $b) => [$a['dim'], -$a['pr']] <=> [$b['dim'], -$b['pr']]);
        return self::R(['dim' => 'Dimension', 'segment' => 'Segment', 'n' => ['Loans', 'num'], 'pr' => ['Principal outstanding', 'money'], 'share' => ['Share of portfolio', 'pct']], $rs, [], 'Each dimension sums to the whole open portfolio.');
    }

    private static function collection_efficiency(array $f): array
    {
        $x = self::x($f);
        $r = self::expected_vs_actual($f);
        $r['cols']['rate'] = ['Collection efficiency', 'pct'];
        $r['note'] = 'Efficiency = payments received Ã· scheduled amounts falling due in the same month. Payments may include arrears collected from earlier months.';
        return $r;
    }

    private static function loan_book_growth(array $f): array
    {
        $x = self::x($f); $out = []; $prev = null;
        foreach (self::months($x['from'], $x['to']) as $m) {
            $end = date('Y-m-t', strtotime($m . '-01'));
            $bal = (int)val("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE g.component = 'principal' AND g.business_date <= ? AND {$x['LB']}", [$end]);
            $disb = (int)val("SELECT COALESCE(SUM(d.approved_principal),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE d.business_date BETWEEN ? AND ? AND {$x['LB']}", [$m . '-01', $end]);
            $out[] = ['m' => date('M Y', strtotime($m . '-01')), 'disb' => $disb, 'bal' => $bal, 'chg' => $prev === null ? null : $bal - $prev, 'pct' => $prev ? round(($bal - $prev) / $prev * 100, 1) . '%' : 'â€“'];
            $prev = $bal;
        }
        return self::R(['m' => 'Month', 'disb' => ['Disbursed', 'money'], 'bal' => ['Principal outstanding (month end)', 'money'], 'chg' => ['Change', 'money'], 'pct' => ['Growth', 'pct']], $out, ['disb']);
    }

    private static function arrears_trend(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (self::months($x['from'], $x['to']) as $m) {
            $end = date('Y-m-t', strtotime($m . '-01'));
            $r = row("SELECT COUNT(*) AS n, COALESCE(SUM(b.total_out),0) AS tot FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id
                      WHERE l.overdue_since IS NOT NULL AND l.overdue_since <= ? AND (l.closed_date IS NULL OR l.closed_date > ?) AND {$x['LB']}", [$end, $end]);
            $out[] = ['m' => date('M Y', strtotime($m . '-01')), 'n' => (int)$r['n'], 'tot' => (int)$r['tot']];
        }
        return self::R(['m' => 'Month end', 'n' => ['Accounts in arrears', 'num'], 'tot' => ['Balance owed (current balances)', 'money']], $out);
    }

    private static function approval_trend(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (self::months($x['from'], $x['to']) as $m) {
            $a = $m . '-01'; $b = date('Y-m-t', strtotime($a));
            $apps = (int)val("SELECT COUNT(*) FROM loans l WHERE l.application_date BETWEEN ? AND ? AND l.status <> 'Draft' AND {$x['LB']}", [$a, $b]);
            $d = fn(string $dec) => (int)val("SELECT COUNT(*) FROM loan_approvals a JOIN loans l ON l.id = a.loan_id WHERE a.decision = ? AND substr(a.decided_at,1,10) BETWEEN ? AND ? AND {$x['LB']}", [$dec, $a, $b]);
            $ap = $d('Approved'); $rj = $d('Rejected'); $rt = $d('Returned');
            $out[] = ['m' => date('M Y', strtotime($a)), 'apps' => $apps, 'ap' => $ap, 'rj' => $rj, 'rt' => $rt, 'rate' => ($ap + $rj) > 0 ? round($ap / ($ap + $rj) * 100, 1) . '%' : 'â€“'];
        }
        return self::R(['m' => 'Month', 'apps' => ['Applications received', 'num'], 'ap' => ['Approved', 'num'], 'rj' => ['Rejected', 'num'], 'rt' => ['Returned', 'num'], 'rate' => ['Approval rate', 'pct']], $out, ['apps', 'ap', 'rj', 'rt'], 'Approval rate = approved Ã· (approved + rejected) decisions in the month.');
    }

    private static function branch_risk_ranking(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (accessible_branch_ids() as $bid) {
            $xx = $x; $xx['LB'] = 'l.branch_id = ' . (int)$bid . loan_scope('l');
            $tot = (int)val("SELECT COALESCE(SUM(b.principal_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND {$xx['LB']}");
            $p30 = self::parPct($xx, 30); $p90 = self::parPct($xx, 90);
            $od = (int)val("SELECT COUNT(*) FROM loans l WHERE l.status = 'Overdue' AND {$xx['LB']}");
            $out[] = ['branch' => branch_name($bid), 'tot' => $tot, 'od' => $od, 'p30' => $p30, 'p90' => $p90];
        }
        usort($out, fn($a, $b) => $b['p30'] <=> $a['p30']);
        foreach ($out as $i => &$o) { $o['rank'] = $i + 1; $o['p30'] = $o['p30'] . '%'; $o['p90'] = $o['p90'] . '%'; }
        return self::R(['rank' => ['Rank', 'num'], 'branch' => 'Branch', 'tot' => ['Principal outstanding', 'money'], 'od' => ['Overdue loans', 'num'], 'p30' => ['PAR30', 'pct'], 'p90' => ['PAR90', 'pct']], $out);
    }

    // ================================================================= K. audit, compliance, security
    private static function audit_trail(array $f): array
    {
        $x = self::x($f);
        $scopes = []; $sp = [];
        if (can('audit.all')) $scopes[] = '1=1';
        if (can('audit.branch')) $scopes[] = '(' . branch_scope('a.branch_id') . ')';
        if (can('audit.financial')) $scopes[] = "(a.module IN ('Payments','Disbursements','Cashier','Expenses','End of Day','Penalties','Loans','Export') AND " . branch_scope('a.branch_id') . ')';
        if (can('audit.technical')) $scopes[] = "a.module IN ('Security','Users','Backup','Settings','Branches','Administration','Documents')";
        if (can('audit.own')) { $scopes[] = '(a.user_id = ?)'; $sp[] = uid(); }
        $sc = $scopes ? '(' . implode(' OR ', $scopes) . ')' : '1=0';
        return self::R(['at' => ['When', 'dt'], 'username' => 'User', 'module' => 'Module', 'action' => 'Action', 'record' => 'Record', 'prev_value' => 'Before', 'new_value' => 'After', 'reason' => 'Reason'],
            array_map(fn($r) => $r + ['record' => trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? ''))], rows("SELECT a.* FROM audit_logs a WHERE substr(a.at,1,10) BETWEEN ? AND ? AND $sc ORDER BY a.id DESC LIMIT 2000", array_merge([$x['from'], $x['to']], $sp))), [], 'Limited to the audit scope you hold; the latest 2,000 entries of the period are shown.');
    }

    private static function user_access(array $f): array
    {
        $rs = rows("SELECT u.id, u.full_name, u.username, u.staff_no, u.role, u.status, u.mfa_enabled, u.last_login FROM users u ORDER BY u.status, u.full_name");
        $ub = []; foreach (rows('SELECT ub.user_id, b.name FROM user_branches ub JOIN branches b ON b.id = ub.branch_id ORDER BY b.name') as $r) $ub[$r['user_id']][] = $r['name'];
        foreach ($rs as &$r) { $r['branches'] = $r['role'] === 'COO' || $r['role'] === 'Auditor' ? 'All branches' : implode(', ', $ub[$r['id']] ?? []); $r['mfa'] = $r['mfa_enabled'] ? 'On' : 'Off'; }
        return self::R(['staff_no' => 'Staff ID', 'full_name' => 'Name', 'username' => 'Username', 'role' => 'Role', 'branches' => 'Branch access', 'status' => ['Status', 'badge'], 'mfa' => 'MFA', 'last_login' => ['Last sign-in', 'dt']], $rs);
    }

    private static function permission_changes(array $f): array
    {
        $x = self::x($f);
        return self::R(['at' => ['When', 'dt'], 'username' => 'Changed by', 'action' => 'Action', 'record' => 'Applies to', 'new_value' => 'Change', 'reason' => 'Reason'],
            array_map(fn($r) => $r + ['record' => trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? ''))],
                rows("SELECT a.* FROM audit_logs a WHERE substr(a.at,1,10) BETWEEN ? AND ? AND (a.action IN ('User permissions changed','Role permissions changed','User report access changed','Report access changed','User created','User updated','User activated','User deactivated','Approval limit changed') OR a.action LIKE '%ermission%') ORDER BY a.id DESC", [$x['from'], $x['to']])));
    }

    private static function financial_exceptions(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['business_date' => ['Date', 'date'], 'kind' => 'Exception', 'ref' => 'Reference', 'amount' => ['Amount', 'money'], 'reason' => 'Reason', 'actor' => 'Person'],
            rows("SELECT t.* FROM (
                  SELECT br.name AS branch, p.reversal_business_date AS business_date, 'Payment reversed' AS kind, r.receipt_no AS ref, p.amount, p.reversal_reason AS reason, u.full_name AS actor, p.branch_id AS bid FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN branches br ON br.id = p.branch_id LEFT JOIN users u ON u.id = p.reversed_by WHERE p.status = 'Reversed'
                  UNION ALL SELECT br.name, a.business_date, a.type, l.loan_no, a.amount, a.reason, u.full_name, l.branch_id FROM loan_adjustments a JOIN loans l ON l.id = a.loan_id JOIN branches br ON br.id = l.branch_id LEFT JOIN users u ON u.id = a.created_by
                  UNION ALL SELECT br.name, s.business_date, 'Cash variance', s.session_no, s.variance, s.variance_reason, u.full_name, s.branch_id FROM cashier_sessions s JOIN branches br ON br.id = s.branch_id JOIN users u ON u.id = s.cashier_id WHERE s.variance IS NOT NULL AND s.variance <> 0
                  UNION ALL SELECT br.name, l.approval_date, 'Approved above limit / by COO', l.loan_no, l.approved_principal, a.reason, u.full_name, l.branch_id FROM loan_approvals a JOIN loans l ON l.id = a.loan_id JOIN branches br ON br.id = l.branch_id JOIN users u ON u.id = a.approver_id WHERE a.approver_role = 'COO' AND a.decision = 'Approved'
                  ) t WHERE t.business_date BETWEEN ? AND ? AND " . $x['B']('t.bid') . ' ORDER BY t.business_date', [$x['from'], $x['to']]), ['amount']);
    }

    private static function security_events(array $f): array
    {
        $x = self::x($f);
        return self::R(['at' => ['When', 'dt'], 'username' => 'Username', 'ip' => 'IP address', 'result' => ['Result', 'badge'], 'reason' => 'Detail'],
            array_map(fn($r) => $r + ['result' => $r['success'] ? 'Success' : 'Failed'], rows("SELECT * FROM login_events WHERE substr(at,1,10) BETWEEN ? AND ? ORDER BY id DESC LIMIT 2000", [$x['from'], $x['to']])));
    }

    private static function audit_by_actions(array $f, array $actions, string $title): array
    {
        $x = self::x($f); $in = implode(',', array_fill(0, count($actions), '?'));
        return self::R(['at' => ['When', 'dt'], 'username' => 'User', 'action' => 'Action', 'record' => 'Record', 'new_value' => 'Detail', 'ip' => 'IP address'],
            array_map(fn($r) => $r + ['record' => trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? ''))], rows("SELECT * FROM audit_logs WHERE substr(at,1,10) BETWEEN ? AND ? AND action IN ($in) ORDER BY id DESC LIMIT 2000", array_merge([$x['from'], $x['to']], $actions))), [], $title);
    }

    private static function sensitive_access(array $f): array { return self::audit_by_actions($f, ['Document viewed', 'Statement generated', 'Sensitive client record viewed', 'Document printed'], 'Protected documents, statements and sensitive client profiles that were opened.'); }
    private static function denied_attempts(array $f): array { return self::audit_by_actions($f, ['Access denied'], 'Requests refused because the user lacked the permission or the record belonged to another branch.'); }
    private static function statement_log(array $f): array { return self::audit_by_actions($f, ['Statement generated', 'Document printed'], 'Statements generated (client statements and printed loan statements).'); }

    // ================================================================= L. documents and operations
    private static function missing_documents(array $f): array
    {
        $x = self::x($f); $out = [];
        foreach (rows("SELECT br.name AS branch, c.client_no AS ref, c.full_name, 'Client' AS kind, (SELECT COUNT(*) FROM attachments a WHERE a.entity_type = 'client' AND a.entity_id = c.id AND a.kind <> 'photo') AS docs, (SELECT COUNT(*) FROM attachments a WHERE a.entity_type = 'client' AND a.entity_id = c.id AND a.kind = 'photo') AS photos
                       FROM clients c JOIN branches br ON br.id = c.branch_id WHERE c.status = 'Active' AND " . $x['B']('c.branch_id') . client_scope('c') . ' ORDER BY c.client_no') as $r) {
            $m = []; if (!(int)$r['photos']) $m[] = 'photograph'; if (!(int)$r['docs']) $m[] = 'identity / supporting document';
            if ($m) $out[] = ['branch' => $r['branch'], 'kind' => 'Client', 'ref' => $r['ref'], 'full_name' => $r['full_name'], 'missing' => implode(', ', $m)];
        }
        foreach (rows("SELECT br.name AS branch, l.loan_no AS ref, c.full_name, (SELECT COUNT(*) FROM attachments a WHERE a.entity_type = 'loan' AND a.entity_id = l.id) AS docs, (SELECT COUNT(*) FROM loan_guarantors g WHERE g.loan_id = l.id) AS guarantors, (SELECT COUNT(*) FROM credit_assessments a WHERE a.loan_id = l.id) AS assessed
                       FROM loans l JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id WHERE l.status IN ('Submitted','Under Review','Approved','Active','Overdue') AND {$x['LB']} ORDER BY l.id") as $r) {
            $m = []; if (!(int)$r['docs']) $m[] = 'application documents'; if (!(int)$r['guarantors']) $m[] = 'guarantor'; if (!(int)$r['assessed'] && true) $m[] = 'credit assessment';
            if ($m) $out[] = ['branch' => $r['branch'], 'kind' => 'Loan', 'ref' => $r['ref'], 'full_name' => $r['full_name'], 'missing' => implode(', ', $m)];
        }
        return self::R($x['brCol'] + ['kind' => 'Record', 'ref' => 'Reference', 'full_name' => 'Client', 'missing' => 'Missing'], $out);
    }

    private static function agreement_register(array $f): array
    {
        $x = self::x($f);
        return self::R($x['brCol'] + ['loan_no' => 'Loan', 'full_name' => 'Client', 'approval_date' => ['Offer generated', 'date'], 'offer_expiry' => ['Offer valid until', 'date'], 'acceptance_date' => ['Signed / accepted', 'date'], 'state' => ['Agreement', 'badge'], 'approved_principal' => ['Principal', 'money']],
            array_map(function ($r) { $r['state'] = $r['acceptance_status'] === 'Accepted' ? 'Signed' : ($r['acceptance_status'] === 'Declined' ? 'Declined' : 'Pending'); return $r; },
                rows("SELECT br.name AS branch, l.loan_no, c.full_name, l.approval_date, l.offer_expiry, l.acceptance_date, l.acceptance_status, l.approved_principal FROM loans l JOIN clients c ON c.id = l.client_id JOIN branches br ON br.id = l.branch_id
                      WHERE l.approval_date BETWEEN ? AND ? AND {$x['LB']} ORDER BY l.approval_date", [$x['from'], $x['to']])), ['approved_principal']);
    }

    private static function export_history(array $f): array
    {
        $x = self::x($f);
        $own = can('audit.all') || can('audit.technical') ? '' : ' AND e.user_id = ' . (int)uid();
        return self::R(['at' => ['When', 'dt'], 'uname' => 'User', 'kind' => 'Report / data', 'format' => 'Format', 'rows_total' => ['Rows exported', 'num'], 'outcome' => ['Outcome', 'badge']],
            array_map(fn($r) => $r + ['outcome' => 'Completed'], rows("SELECT e.*, u.full_name AS uname FROM export_history e LEFT JOIN users u ON u.id = e.user_id WHERE substr(e.at,1,10) BETWEEN ? AND ?$own ORDER BY e.id DESC", [$x['from'], $x['to']])), ['rows_total'],
            'Only completed exports are recorded; refused attempts appear under Unauthorized Access Attempts.');
    }

    private static function backup_health(array $f): array
    {
        $h = System::health(); $last = Backup::lastSuccess();
        $rows = [['item' => 'Last verified backup', 'value' => $last ? fmt_dt($last['at']) . ' (' . $last['type'] . ')' : 'never', 'status' => $h['backup']['ok'] ? 'OK' : 'Attention'],
            ['item' => 'Hours since last verified backup', 'value' => $h['backup']['age_hours'] === null ? 'â€”' : (string)$h['backup']['age_hours'], 'status' => $h['backup']['ok'] ? 'OK' : 'Attention'],
            ['item' => 'Failed backups (7 days)', 'value' => (string)$h['backup']['failed_recent'], 'status' => $h['backup']['failed_recent'] ? 'Attention' : 'OK'],
            ['item' => 'Database', 'value' => $h['db']['engine'], 'status' => $h['db']['ok'] ? 'OK' : 'DOWN'], ['item' => 'File storage', 'value' => $h['storage']['driver'], 'status' => $h['storage']['ok'] ? 'OK' : 'PROBLEM'],
            ['item' => 'Pending migrations', 'value' => (string)$h['migrations_pending'], 'status' => $h['migrations_pending'] ? 'Attention' : 'OK'], ['item' => 'Failed sign-ins (24 h)', 'value' => (string)$h['security']['failed_24h'], 'status' => $h['security']['failed_24h'] > 20 ? 'Attention' : 'OK']];
        foreach (rows('SELECT at, type, status, message FROM backup_history ORDER BY id DESC LIMIT 10') as $r) $rows[] = ['item' => 'Backup history: ' . $r['type'] . ' ' . fmt_dt($r['at']), 'value' => (string)$r['message'], 'status' => $r['status'] === 'Success' ? 'OK' : 'Failed'];
        return self::R(['item' => 'Item', 'value' => 'Value', 'status' => ['Status', 'badge']], $rows);
    }
}
