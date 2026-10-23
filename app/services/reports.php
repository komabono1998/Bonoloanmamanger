<?php
/**
 * Report catalogue. Every report returns ['cols' => data_table columns, 'rows' => list, 'totals' => [col keys]].
 * Reports keep accrued vs collected, assessed vs received, gross vs net and cash vs non-cash apart (SRS 27.7).
 */
final class Reports
{
    private static function R(array $cols, array $rows, array $totals = [], string $note = ''): array
    {
        return ['cols' => $cols, 'rows' => $rows, 'totals' => $totals, 'note' => $note];
    }

    /** key => [group, title, description, uses (filters shown)] */
    public static function catalogue(): array
    {
        $dr = ['from', 'to']; $none = [];
        return [
            // clients
            'client_register' => ['Clients', 'Client register', 'All registered clients with status and exposure.', $none],
            'new_clients' => ['Clients', 'New clients', 'Clients registered in the period.', $dr],
            'client_loan_history' => ['Clients', 'Client loan history', 'Every loan by client with outcome.', ['client']],
            'guarantor_register' => ['Clients', 'Guarantor register', 'Guarantors and the loans they back.', $none],
            'security_register' => ['Clients', 'Security register', 'Collateral held, with values and status.', $none],
            // loans
            'loan_applications' => ['Loans', 'Loan applications', 'Applications received in the period.', ['from', 'to', 'officer']],
            'approved_loans' => ['Loans', 'Approved loans', 'Loans approved in the period (terms snapshot).', ['from', 'to', 'officer']],
            'rejected_loans' => ['Loans', 'Rejected loans', 'Rejected applications with reasons.', ['from', 'to', 'officer']],
            'active_loans' => ['Loans', 'Active loans', 'Open loans and balances.', ['officer']],
            'closed_loans' => ['Loans', 'Closed loans', 'Fully settled loans.', ['from', 'to', 'officer']],
            'due_today' => ['Loans', 'Loans due today', 'Maturing on the business date.', ['officer']],
            'due_week' => ['Loans', 'Loans due this week', 'Maturing within 7 days.', ['officer']],
            'due_month' => ['Loans', 'Loans due this month', 'Maturing within 30 days.', ['officer']],
            'overdue_loans' => ['Loans', 'Overdue loans', 'Past maturity with balances separated.', ['officer']],
            'defaulted_loans' => ['Loans', 'Defaulted loans', 'Overdue beyond the default threshold.', ['officer']],
            'portfolio_summary' => ['Loans', 'Loan portfolio summary', 'Counts and balances by status.', $none],
            'outstanding_balances' => ['Loans', 'Outstanding balances', 'Principal, interest, penalty and fee outstanding per loan.', ['officer']],
            'officer_performance' => ['Loans', 'Loan officer performance', 'Disbursed, collected and overdue by officer.', $dr],
            // collections
            'daily_collections' => ['Collections', 'Daily collections', 'Collections by business date, split by component.', ['from', 'to', 'cashier', 'method']],
            'monthly_collections' => ['Collections', 'Monthly collections', 'Collections by month.', ['from', 'to']],
            'collection_by_cashier' => ['Collections', 'Collections by cashier', '', ['from', 'to']],
            'collection_by_officer' => ['Collections', 'Collections by loan officer', '', ['from', 'to']],
            'collection_by_method' => ['Collections', 'Collections by payment method', 'Cash vs non-cash.', ['from', 'to']],
            'partial_payments' => ['Collections', 'Partial payments', 'Payments that left a balance.', ['from', 'to']],
            'reversed_payments' => ['Collections', 'Reversed payments', 'Reversals with reasons.', ['from', 'to']],
            'outstanding_amounts' => ['Collections', 'Outstanding amounts by client', '', $none],
            // financial
            'principal_disbursed' => ['Financial', 'Principal disbursed', 'Gross principal, fees deducted and net released.', ['from', 'to']],
            'principal_outstanding' => ['Financial', 'Principal outstanding', '', ['officer']],
            'principal_recovered' => ['Financial', 'Principal recovered', 'Principal collected in the period.', ['from', 'to']],
            'interest_receivable' => ['Financial', 'Contractual interest receivable', 'ACCRUED, not yet received.', ['officer']],
            'interest_collected' => ['Financial', 'Interest collected', 'Cash actually received.', ['from', 'to']],
            'fees_report' => ['Financial', 'Fees: assessed vs received', 'Processing, application and inspection fees.', ['from', 'to']],
            'penalties_report' => ['Financial', 'Penalties: accrued vs collected', '', ['from', 'to']],
            'operating_expenses' => ['Financial', 'Operating expenses', 'By category.', ['from', 'to']],
            'cashbook_summary' => ['Financial', 'Cashbook summary', 'Money in and out by type and method.', ['from', 'to']],
            'daily_cash_movement' => ['Financial', 'Daily cash movement', 'Cash only, per business date.', ['from', 'to']],
            'cashier_variances' => ['Financial', 'Cashier variances', 'Counted vs expected cash.', ['from', 'to']],
            // end of day
            'eod_history' => ['End of Day', 'EOD approval history', 'Every EOD version with approver and backup status.', ['from', 'to']],
            'daily_transactions' => ['End of Day', 'Daily transactions', 'All payments, disbursements and expenses per day.', ['from', 'to']],
        ];
    }

    public static function run(string $key, array $f): array
    {
        $from = $f['from']; $to = $f['to']; $off = (int)($f['officer'] ?? 0); $cash = (int)($f['cashier'] ?? 0); $meth = $f['method'] ?? '';
        $asOf = reporting_date();
        $offW = $off ? ' AND l.officer_id = ' . $off : '';
        $lb = "FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id JOIN users u ON u.id = l.officer_id";
        $loanCols = ['loan_no' => 'Loan', 'client_no' => 'Client ID', 'full_name' => 'Client', 'approved_principal' => ['Principal', 'money'], 'due_date' => ['Due', 'date'], 'principal_out' => ['Principal out', 'money'],
            'interest_out' => ['Interest out', 'money'], 'penalty_out' => ['Penalty out', 'money'], 'total_out' => ['Total out', 'money'], 'officer' => 'Officer', 'status' => ['Status', 'badge']];
        $loanSel = "SELECT l.id, l.loan_no, c.client_no, c.full_name, l.approved_principal, l.due_date, b.principal_out, b.interest_out, b.penalty_out, b.fee_out, b.total_out, u.full_name AS officer, l.status";
        $loanTot = ['approved_principal', 'principal_out', 'interest_out', 'penalty_out', 'total_out'];
        $pay = ["p.status = 'Posted'", 'p.business_date BETWEEN ? AND ?']; $pp = [$from, $to];
        if ($cash) { $pay[] = 'p.cashier_id = ?'; $pp[] = $cash; }
        if ($meth !== '') { $pay[] = 'p.method = ?'; $pp[] = $meth; }
        $PW = implode(' AND ', $pay);
        $alloc = fn(string $c) => "COALESCE(SUM(CASE WHEN a.component = '$c' THEN a.amount END),0)";

        switch ($key) {
            case 'client_register':
                return self::R(['client_no' => 'Client ID', 'full_name' => 'Name', 'gender' => 'Gender', 'phone' => 'Phone', 'district' => 'District', 'status' => ['Status', 'badge'], 'registered_at' => ['Registered', 'date'], 'loans' => ['Loans', 'num'], 'owed' => ['Owed', 'money']],
                    rows("SELECT c.*, (SELECT COUNT(*) FROM loans l WHERE l.client_id = c.id AND l.disbursed_at IS NOT NULL) loans,
                          COALESCE((SELECT SUM(b.total_out) FROM v_loan_balances b JOIN loans l ON l.id = b.loan_id WHERE l.client_id = c.id AND l.status IN ('Active','Overdue')),0) owed FROM clients c ORDER BY c.client_no"), ['owed']);
            case 'new_clients':
                return self::R(['client_no' => 'Client ID', 'full_name' => 'Name', 'phone' => 'Phone', 'district' => 'District', 'registered_at' => ['Registered', 'dt'], 'reg_by' => 'Registered by'],
                    rows("SELECT c.*, u.full_name AS reg_by FROM clients c LEFT JOIN users u ON u.id = c.registered_by WHERE date(c.registered_at) BETWEEN ? AND ? ORDER BY c.id", [$from, $to]));
            case 'client_loan_history':
                $cw = !empty($f['client']) ? ' AND l.client_id = ' . (int)$f['client'] : '';
                return self::R(['client_no' => 'Client ID', 'full_name' => 'Client', 'loan_no' => 'Loan', 'application_date' => ['Applied', 'date'], 'approved_principal' => ['Principal', 'money'], 'disbursement_date' => ['Disbursed', 'date'],
                    'due_date' => ['Due', 'date'], 'closed_date' => ['Closed', 'date'], 'paid_total' => ['Paid', 'money'], 'total_out' => ['Outstanding', 'money'], 'status' => ['Status', 'badge']],
                    rows("SELECT c.client_no, c.full_name, l.loan_no, l.application_date, l.approved_principal, l.disbursement_date, l.due_date, l.closed_date, b.paid_total, b.total_out, l.status $lb WHERE 1=1 $cw ORDER BY c.client_no, l.id"), ['approved_principal', 'paid_total', 'total_out']);
            case 'guarantor_register':
                return self::R(['guarantor_no' => 'Guarantor', 'name' => 'Name', 'phone' => 'Phone', 'loan_no' => 'Loan', 'borrower' => 'Borrower', 'guarantee_amount' => ['Guarantee limit', 'money'], 'loan_status' => ['Loan status', 'badge'], 'declaration' => 'Declaration'],
                    rows("SELECT g.guarantor_no, g.surname || ' ' || g.first_name name, g.phone, l.loan_no, c.full_name borrower, lg.guarantee_amount, l.status loan_status, CASE lg.declaration_accepted WHEN 1 THEN 'Accepted' ELSE 'Pending' END declaration
                          FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id JOIN loans l ON l.id = lg.loan_id JOIN clients c ON c.id = l.client_id ORDER BY g.guarantor_no"));
            case 'security_register':
                return self::R(['security_no' => 'Security', 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => 'Type', 'description' => 'Description', 'serial_no' => 'Serial', 'owner_type' => 'Owner', 'est_value' => ['Est. value', 'money'], 'storage_location' => 'Stored at', 'status' => ['Status', 'badge']],
                    rows("SELECT s.*, l.loan_no, c.full_name FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id ORDER BY s.security_no"), ['est_value']);
            case 'loan_applications':
                return self::R(['loan_no' => 'Loan', 'application_date' => ['Applied', 'date'], 'full_name' => 'Client', 'amount_requested' => ['Requested', 'money'], 'purpose' => 'Purpose', 'officer' => 'Officer', 'status' => ['Status', 'badge']],
                    rows("SELECT l.*, c.full_name, u.full_name officer FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id WHERE l.application_date BETWEEN ? AND ? $offW ORDER BY l.id", [$from, $to]), ['amount_requested']);
            case 'approved_loans':
                return self::R(['loan_no' => 'Loan', 'approval_date' => ['Approved', 'date'], 'full_name' => 'Client', 'approved_principal' => ['Principal', 'money'], 'rate' => 'Rate', 'interest_amount' => ['Interest', 'money'], 'total_fees' => ['Fees', 'money'],
                    'net_disbursement' => ['Net', 'money'], 'acceptance_status' => ['Acceptance', 'badge'], 'status' => ['Status', 'badge']],
                    array_map(fn($r) => $r + ['rate' => bp_to_pct((int)$r['rate_bp']) . '%'], rows("SELECT l.*, c.full_name FROM loans l JOIN clients c ON c.id = l.client_id WHERE l.approval_date BETWEEN ? AND ? $offW ORDER BY l.id", [$from, $to])),
                    ['approved_principal', 'interest_amount', 'total_fees', 'net_disbursement']);
            case 'rejected_loans':
                return self::R(['loan_no' => 'Loan', 'application_date' => ['Applied', 'date'], 'full_name' => 'Client', 'amount_requested' => ['Requested', 'money'], 'rejection_reason' => 'Reason', 'officer' => 'Officer'],
                    rows("SELECT l.*, c.full_name, u.full_name officer FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id WHERE l.status = 'Rejected' AND l.application_date BETWEEN ? AND ? $offW ORDER BY l.id", [$from, $to]), ['amount_requested']);
            case 'active_loans':
                return self::R($loanCols, rows("$loanSel $lb WHERE l.status IN ('Active','Overdue') $offW ORDER BY l.due_date"), $loanTot);
            case 'closed_loans':
                return self::R($loanCols + ['closed_date' => ['Closed', 'date']], rows("$loanSel, l.closed_date $lb WHERE l.status = 'Closed' AND l.closed_date BETWEEN ? AND ? $offW ORDER BY l.closed_date", [$from, $to]), $loanTot);
            case 'due_today':
                return self::R($loanCols, rows("$loanSel $lb WHERE l.status IN ('Active','Overdue') AND l.due_date = ? $offW ORDER BY l.id", [$asOf]), $loanTot);
            case 'due_week':
                return self::R($loanCols, rows("$loanSel $lb WHERE l.status IN ('Active','Overdue') AND l.due_date BETWEEN ? AND ? $offW ORDER BY l.due_date", [$asOf, Calc::addDays($asOf, 7)]), $loanTot);
            case 'due_month':
                return self::R($loanCols, rows("$loanSel $lb WHERE l.status IN ('Active','Overdue') AND l.due_date BETWEEN ? AND ? $offW ORDER BY l.due_date", [$asOf, Calc::addDays($asOf, 30)]), $loanTot);
            case 'overdue_loans':
                return self::R($loanCols + ['days' => ['Days overdue', 'num']], rows("$loanSel, CAST(julianday(?) - julianday(l.overdue_since) AS INTEGER) days $lb WHERE l.status = 'Overdue' $offW ORDER BY l.overdue_since", [$asOf]), $loanTot);
            case 'defaulted_loans':
                return self::R($loanCols + ['days' => ['Days overdue', 'num']], rows("$loanSel, CAST(julianday(?) - julianday(l.overdue_since) AS INTEGER) days $lb WHERE l.status = 'Overdue' AND julianday(?) - julianday(l.overdue_since) > ? $offW ORDER BY l.overdue_since",
                    [$asOf, $asOf, setting_int('default_after_days')]), $loanTot, 'Defaulted = overdue by more than ' . setting_int('default_after_days') . ' days. A promise to pay or contact does not change the classification.');
            case 'portfolio_summary':
                return self::R(['status' => ['Status', 'badge'], 'n' => ['Loans', 'num'], 'principal' => ['Approved principal', 'money'], 'principal_out' => ['Principal out', 'money'], 'interest_out' => ['Interest out', 'money'], 'penalty_out' => ['Penalty out', 'money'], 'total_out' => ['Total out', 'money']],
                    rows("SELECT l.status, COUNT(*) n, COALESCE(SUM(l.approved_principal),0) principal, SUM(b.principal_out) principal_out, SUM(b.interest_out) interest_out, SUM(b.penalty_out) penalty_out, SUM(b.total_out) total_out
                          FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id GROUP BY l.status ORDER BY l.status"), ['n', 'principal', 'principal_out', 'interest_out', 'penalty_out', 'total_out']);
            case 'outstanding_balances':
                return self::R($loanCols + ['fee_out' => ['Fees out', 'money']], rows("$loanSel $lb WHERE l.status IN ('Active','Overdue') AND b.total_out > 0 $offW ORDER BY b.total_out DESC"), $loanTot + ['fee_out']);
            case 'officer_performance':
                return self::R(['officer' => 'Officer', 'applications' => ['Applications', 'num'], 'approved' => ['Approved', 'num'], 'disbursed' => ['Principal disbursed', 'money'], 'collected' => ['Collected (period)', 'money'], 'overdue_n' => ['Overdue loans', 'num'], 'overdue_amt' => ['Overdue exposure', 'money']],
                    rows("SELECT u.full_name officer,
                          (SELECT COUNT(*) FROM loans l WHERE l.officer_id = u.id AND l.application_date BETWEEN :f AND :t) applications,
                          (SELECT COUNT(*) FROM loans l WHERE l.officer_id = u.id AND l.approval_date BETWEEN :f AND :t) approved,
                          (SELECT COALESCE(SUM(d.approved_principal),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE l.officer_id = u.id AND d.business_date BETWEEN :f AND :t) disbursed,
                          (SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN loans l ON l.id = p.loan_id WHERE l.officer_id = u.id AND p.status='Posted' AND p.business_date BETWEEN :f AND :t) collected,
                          (SELECT COUNT(*) FROM loans l WHERE l.officer_id = u.id AND l.status = 'Overdue') overdue_n,
                          (SELECT COALESCE(SUM(b.total_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.officer_id = u.id AND l.status = 'Overdue') overdue_amt
                          FROM users u WHERE u.id IN (SELECT officer_id FROM loans) ORDER BY u.full_name", ['f' => $from, 't' => $to]), ['applications', 'approved', 'disbursed', 'collected', 'overdue_n', 'overdue_amt']);
            case 'daily_collections':
                return self::R(['d' => ['Business date', 'date'], 'n' => ['Payments', 'num'], 'principal' => ['Principal', 'money'], 'interest' => ['Interest', 'money'], 'penalty' => ['Penalties', 'money'], 'fees' => ['Fees', 'money'], 'credit' => ['Overpayment credit', 'money'], 'total' => ['Total received', 'money']],
                    rows("SELECT p.business_date d, COUNT(DISTINCT p.id) n, {$alloc('principal')} principal, {$alloc('interest')} interest, {$alloc('penalty')} penalty, {$alloc('fee')} fees, {$alloc('credit')} credit, SUM(a.amount) total
                          FROM payments p JOIN payment_allocations a ON a.payment_id = p.id WHERE $PW GROUP BY p.business_date ORDER BY p.business_date", $pp), ['n', 'principal', 'interest', 'penalty', 'fees', 'credit', 'total']);
            case 'monthly_collections':
                return self::R(['m' => 'Month', 'n' => ['Payments', 'num'], 'principal' => ['Principal', 'money'], 'interest' => ['Interest', 'money'], 'penalty' => ['Penalties', 'money'], 'fees' => ['Fees', 'money'], 'total' => ['Total received', 'money']],
                    rows("SELECT strftime('%Y-%m', p.business_date) m, COUNT(DISTINCT p.id) n, {$alloc('principal')} principal, {$alloc('interest')} interest, {$alloc('penalty')} penalty, {$alloc('fee')} fees, SUM(a.amount) total
                          FROM payments p JOIN payment_allocations a ON a.payment_id = p.id WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? GROUP BY m ORDER BY m", [$from, $to]), ['n', 'principal', 'interest', 'penalty', 'fees', 'total']);
            case 'collection_by_cashier':
                return self::R(['name' => 'Cashier', 'n' => ['Payments', 'num'], 'cash' => ['Cash', 'money'], 'noncash' => ['Non-cash', 'money'], 'total' => ['Total', 'money']],
                    rows("SELECT u.full_name name, COUNT(*) n, SUM(CASE WHEN p.method='Cash' THEN p.amount ELSE 0 END) cash, SUM(CASE WHEN p.method<>'Cash' THEN p.amount ELSE 0 END) noncash, SUM(p.amount) total
                          FROM payments p JOIN users u ON u.id = p.cashier_id WHERE p.status='Posted' AND p.business_date BETWEEN ? AND ? GROUP BY u.id ORDER BY total DESC", [$from, $to]), ['n', 'cash', 'noncash', 'total']);
            case 'collection_by_officer':
                return self::R(['name' => 'Loan officer', 'n' => ['Payments', 'num'], 'total' => ['Total collected', 'money']],
                    rows("SELECT u.full_name name, COUNT(*) n, SUM(p.amount) total FROM payments p JOIN loans l ON l.id = p.loan_id JOIN users u ON u.id = l.officer_id WHERE p.status='Posted' AND p.business_date BETWEEN ? AND ? GROUP BY u.id ORDER BY total DESC", [$from, $to]), ['n', 'total']);
            case 'collection_by_method':
                return self::R(['method' => 'Payment method', 'kind' => 'Cash / non-cash', 'n' => ['Payments', 'num'], 'total' => ['Total', 'money']],
                    rows("SELECT method, CASE WHEN method='Cash' THEN 'Cash' ELSE 'Non-cash' END kind, COUNT(*) n, SUM(amount) total FROM payments p WHERE p.status='Posted' AND p.business_date BETWEEN ? AND ? GROUP BY method ORDER BY total DESC", [$from, $to]), ['n', 'total']);
            case 'partial_payments':
                return self::R(['receipt_no' => 'Receipt', 'business_date' => ['Date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'prev_balance' => ['Balance before', 'money'], 'amount' => ['Paid', 'money'], 'bal_after' => ['Balance after', 'money']],
                    rows("SELECT r.receipt_no, p.business_date, l.loan_no, c.full_name, p.prev_balance, p.amount, (p.prev_balance - p.amount) bal_after FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id
                          WHERE p.status='Posted' AND p.amount < p.prev_balance AND p.business_date BETWEEN ? AND ? ORDER BY p.id", [$from, $to]), ['amount']);
            case 'reversed_payments':
                return self::R(['receipt_no' => 'Receipt', 'business_date' => ['Original date', 'date'], 'reversal_business_date' => ['Reversed on', 'date'], 'loan_no' => 'Loan', 'amount' => ['Amount', 'money'], 'who' => 'Reversed by', 'reversal_reason' => 'Reason'],
                    rows("SELECT r.receipt_no, p.business_date, p.reversal_business_date, l.loan_no, p.amount, u.full_name who, p.reversal_reason FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN loans l ON l.id = p.loan_id LEFT JOIN users u ON u.id = p.reversed_by
                          WHERE p.status='Reversed' AND p.reversal_business_date BETWEEN ? AND ? ORDER BY p.id", [$from, $to]), ['amount']);
            case 'outstanding_amounts':
                return self::R(['client_no' => 'Client ID', 'full_name' => 'Client', 'phone' => 'Phone', 'loans' => ['Open loans', 'num'], 'principal' => ['Principal', 'money'], 'interest' => ['Interest', 'money'], 'penalty' => ['Penalty', 'money'], 'total' => ['Total owed', 'money']],
                    rows("SELECT c.client_no, c.full_name, c.phone, COUNT(*) loans, SUM(b.principal_out) principal, SUM(b.interest_out) interest, SUM(b.penalty_out) penalty, SUM(b.total_out) total FROM clients c JOIN loans l ON l.client_id = c.id
                          JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') GROUP BY c.id HAVING total > 0 ORDER BY total DESC"), ['loans', 'principal', 'interest', 'penalty', 'total']);
            case 'principal_disbursed':
                return self::R(['disb_no' => 'Voucher', 'business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'approved_principal' => ['Gross principal', 'money'], 'fees' => ['Fees deducted', 'money'], 'net_amount' => ['Net released', 'money'], 'method' => 'Method'],
                    rows("SELECT d.disb_no, d.business_date, l.loan_no, c.full_name, d.approved_principal, (d.processing_fee+d.application_fee+d.inspection_fee+d.other_deductions) fees, d.net_amount, d.method FROM disbursements d JOIN loans l ON l.id = d.loan_id JOIN clients c ON c.id = d.client_id
                          WHERE d.business_date BETWEEN ? AND ? ORDER BY d.id", [$from, $to]), ['approved_principal', 'fees', 'net_amount']);
            case 'principal_outstanding':
                return self::R($loanCols, rows("$loanSel $lb WHERE l.status IN ('Active','Overdue') AND b.principal_out > 0 $offW ORDER BY b.principal_out DESC"), $loanTot);
            case 'principal_recovered':
                return self::R(['business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'amount' => ['Principal collected', 'money']],
                    rows("SELECT p.business_date, l.loan_no, c.full_name, SUM(a.amount) amount FROM payment_allocations a JOIN payments p ON p.id = a.payment_id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id
                          WHERE a.component = 'principal' AND p.status = 'Posted' AND p.business_date BETWEEN ? AND ? GROUP BY p.id ORDER BY p.business_date", [$from, $to]), ['amount']);
            case 'interest_receivable':
                return self::R(['loan_no' => 'Loan', 'full_name' => 'Client', 'interest_amount' => ['Contractual interest', 'money'], 'paid_interest' => ['Collected', 'money'], 'interest_out' => ['Receivable (accrued, not received)', 'money'], 'due_date' => ['Due', 'date'], 'status' => ['Status', 'badge']],
                    rows("SELECT l.loan_no, c.full_name, l.interest_amount, b.paid_interest, b.interest_out, l.due_date, l.status $lb WHERE l.status IN ('Active','Overdue') AND b.interest_out > 0 $offW ORDER BY l.due_date"), ['interest_amount', 'paid_interest', 'interest_out'],
                    'Accrued interest is a receivable. It is NOT cash received.');
            case 'interest_collected':
                return self::R(['business_date' => ['Business date', 'date'], 'loan_no' => 'Loan', 'full_name' => 'Client', 'amount' => ['Interest collected', 'money']],
                    rows("SELECT p.business_date, l.loan_no, c.full_name, SUM(a.amount) amount FROM payment_allocations a JOIN payments p ON p.id = a.payment_id JOIN loans l ON l.id = p.loan_id JOIN clients c ON c.id = p.client_id
                          WHERE a.component = 'interest' AND p.status = 'Posted' AND p.business_date BETWEEN ? AND ? GROUP BY p.id ORDER BY p.business_date", [$from, $to]), ['amount']);
            case 'fees_report':
                $d = row("SELECT COALESCE(SUM(l.processing_fee),0) pf, COALESCE(SUM(l.application_fee),0) af, COALESCE(SUM(l.inspection_fee),0) inf, COALESCE(SUM(l.other_fees),0) ofs
                          FROM loans l JOIN disbursements d ON d.loan_id = l.id WHERE d.business_date BETWEEN ? AND ?", [$from, $to]);
                $r = row("SELECT COALESCE(SUM(processing_fee),0) pf, COALESCE(SUM(application_fee),0) af, COALESCE(SUM(inspection_fee),0) inf, COALESCE(SUM(other_deductions),0) od FROM disbursements WHERE business_date BETWEEN ? AND ?", [$from, $to]);
                $sep = (int)val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.component='fee' AND p.status='Posted' AND p.business_date BETWEEN ? AND ?", [$from, $to]);
                return self::R(['type' => 'Fee', 'assessed' => ['Assessed (loans disbursed)', 'money'], 'deducted' => ['Received: deducted at disbursement', 'money'], 'diff' => ['Assessed but paid separately', 'money']], [
                    ['type' => 'Processing fee', 'assessed' => $d['pf'], 'deducted' => $r['pf'], 'diff' => $d['pf'] - $r['pf']],
                    ['type' => 'Application fee', 'assessed' => $d['af'], 'deducted' => $r['af'], 'diff' => $d['af'] - $r['af']],
                    ['type' => 'Inspection fee', 'assessed' => $d['inf'], 'deducted' => $r['inf'], 'diff' => $d['inf'] - $r['inf']],
                    ['type' => 'Other authorized deductions', 'assessed' => $d['ofs'], 'deducted' => $r['od'], 'diff' => $d['ofs'] - $r['od']],
                ], ['assessed', 'deducted', 'diff'], 'Fees later received through the fee allocation of repayments in this period: ' . ugx($sep));
            case 'penalties_report':
                return self::R(['loan_no' => 'Loan', 'full_name' => 'Client', 'accrued' => ['Penalty accrued (period)', 'money'], 'collected' => ['Penalty collected (period)', 'money'], 'outstanding' => ['Penalty outstanding now', 'money']],
                    rows("SELECT l.loan_no, c.full_name, COALESCE((SELECT SUM(amount) FROM penalties pe WHERE pe.loan_id = l.id AND pe.business_date BETWEEN :f AND :t),0) accrued,
                          COALESCE((SELECT SUM(a.amount) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.loan_id = l.id AND a.component='penalty' AND p.status='Posted' AND p.business_date BETWEEN :f AND :t),0) collected, b.penalty_out outstanding
                          FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.id IN (SELECT loan_id FROM penalties) ORDER BY l.id", ['f' => $from, 't' => $to]), ['accrued', 'collected', 'outstanding']);
            case 'operating_expenses':
                return self::R(['category' => 'Category', 'n' => ['Count', 'num'], 'cash' => ['Cash', 'money'], 'noncash' => ['Non-cash', 'money'], 'total' => ['Total paid', 'money']],
                    rows("SELECT c.name category, COUNT(*) n, SUM(CASE WHEN e.method='Cash' THEN e.amount ELSE 0 END) cash, SUM(CASE WHEN e.method<>'Cash' THEN e.amount ELSE 0 END) noncash, SUM(e.amount) total
                          FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.status = 'Paid' AND e.paid_business_date BETWEEN ? AND ? GROUP BY c.id ORDER BY total DESC", [$from, $to]), ['n', 'cash', 'noncash', 'total']);
            case 'cashbook_summary':
                $labels = ['collection' => 'Loan repayments received', 'disbursement' => 'Loan disbursements (net)', 'expense' => 'Operating expenses', 'float' => 'Opening floats', 'transfer' => 'Transfers', 'reversal' => 'Reversals', 'adjustment' => 'Other / refunds'];
                $rs = rows("SELECT category, method, SUM(CASE WHEN direction=1 THEN amount ELSE 0 END) money_in, SUM(CASE WHEN direction=-1 THEN amount ELSE 0 END) money_out FROM cash_movements WHERE business_date BETWEEN ? AND ? GROUP BY category, method ORDER BY category, method", [$from, $to]);
                foreach ($rs as &$x) $x['label'] = $labels[$x['category']] ?? $x['category'];
                return self::R(['label' => 'Type', 'method' => 'Method', 'money_in' => ['Money in', 'money'], 'money_out' => ['Money out', 'money']], $rs, ['money_in', 'money_out'], 'Loan principal disbursed is a lending outflow, not an operating expense; repayments are split into principal/interest/fees/penalties in the collection reports.');
            case 'daily_cash_movement':
                return self::R(['d' => ['Business date', 'date'], 'opening' => ['Opening floats', 'money'], 'coll' => ['Cash collections', 'money'], 'disb' => ['Cash disbursed', 'money'], 'exp' => ['Cash expenses', 'money'], 'tin' => ['Transfers in', 'money'], 'tout' => ['Transfers out', 'money'], 'net' => ['Net cash movement', 'money']],
                    rows("SELECT business_date d, SUM(CASE WHEN category='float' THEN amount ELSE 0 END) opening, SUM(CASE WHEN category='collection' THEN amount ELSE 0 END) coll, SUM(CASE WHEN category='disbursement' THEN amount ELSE 0 END) disb,
                          SUM(CASE WHEN category='expense' THEN amount ELSE 0 END) exp, SUM(CASE WHEN category='transfer' AND direction=1 THEN amount ELSE 0 END) tin, SUM(CASE WHEN category='transfer' AND direction=-1 THEN amount ELSE 0 END) tout,
                          SUM(direction*amount) net FROM cash_movements WHERE is_cash = 1 AND business_date BETWEEN ? AND ? GROUP BY business_date ORDER BY business_date", [$from, $to]), ['coll', 'disb', 'exp', 'tin', 'tout', 'net']);
            case 'cashier_variances':
                return self::R(['business_date' => ['Business date', 'date'], 'session_no' => 'Session', 'cashier' => 'Cashier', 'expected_cash' => ['Expected', 'money'], 'counted_cash' => ['Counted', 'money'], 'variance' => ['Variance', 'money'], 'variance_reason' => 'Explanation', 'status' => ['Status', 'badge']],
                    rows("SELECT s.*, u.full_name cashier FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.business_date BETWEEN ? AND ? AND s.counted_cash IS NOT NULL ORDER BY s.business_date, s.id", [$from, $to]), ['variance']);
            case 'eod_history':
                return self::R(['business_date' => ['Business date', 'date'], 'version' => ['Ver.', 'num'], 'status' => ['Status', 'badge'], 'sub' => 'Submitted by', 'submitted_at' => ['Submitted', 'dt'], 'rev' => 'Reviewed by', 'reviewed_at' => ['Reviewed', 'dt'], 'review_notes' => 'Notes', 'backup_status' => 'Backup'],
                    rows("SELECT e.*, s.full_name sub, r.full_name rev FROM eod_records e LEFT JOIN users s ON s.id = e.submitted_by LEFT JOIN users r ON r.id = e.reviewed_by WHERE e.business_date BETWEEN ? AND ? ORDER BY e.business_date, e.version", [$from, $to]));
            case 'daily_transactions':
                return self::R(['d' => ['Business date', 'date'], 'kind' => 'Type', 'ref' => 'Reference', 'who' => 'Party', 'amount' => ['Amount', 'money'], 'method' => 'Method', 'status' => ['Status', 'badge']],
                    rows("SELECT * FROM (
                          SELECT p.business_date d, 'Payment' kind, r.receipt_no ref, c.full_name who, p.amount, p.method, p.status FROM payments p JOIN receipts r ON r.payment_id = p.id JOIN clients c ON c.id = p.client_id
                          UNION ALL SELECT d.business_date, 'Disbursement', d.disb_no, c.full_name, d.net_amount, d.method, 'Posted' FROM disbursements d JOIN clients c ON c.id = d.client_id
                          UNION ALL SELECT e.paid_business_date, 'Expense', e.expense_no, e.description, e.amount, e.method, e.status FROM expenses e WHERE e.paid_business_date IS NOT NULL
                          ) WHERE d BETWEEN ? AND ? ORDER BY d, kind", [$from, $to]));
        }
        throw new AppError('Unknown report.');
    }

    /** Flatten report rows into plain scalar arrays for CSV / Excel export. */
    public static function plain(array $rep): array
    {
        $head = []; $keys = [];
        foreach ($rep['cols'] as $k => $c) {
            $c = is_array($c) ? $c : [$c];
            if (isset($c[1]) && !is_string($c[1])) continue;     // skip computed columns
            $head[] = $c[0]; $keys[] = $k;
        }
        $out = [];
        foreach ($rep['rows'] as $r) $out[] = array_map(fn($k) => $r[$k] ?? '', $keys);
        return [$head, $out];
    }
}
