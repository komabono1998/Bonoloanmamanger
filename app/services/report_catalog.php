<?php
/**
 * Report catalogue (Bono reporting specification): 12 categories, 104 reports with stable IDs, plus who may open which report.
 * Access = module permission (report.view) AND the report is granted to the user's role (Report access screen) or to the user,
 * and not blocked for the user. Data scope (branches, portfolio) is applied by the engines, independent of access.
 */
final class ReportCatalog
{
    public const CATEGORIES = [
        'A' => ['Loan Portfolio Reports', 'Portfolio overview, active loans, outstanding balances, disbursements, loan maturity, closed loans and portfolio quality.'],
        'B' => ['Client Reports', 'Client register, new registrations, borrower history, repeat borrowers, inactive clients and client exposure.'],
        'C' => ['Loan Application and Approval Reports', 'Applications received, approval rates, rejected applications, pending decisions, processing times and approval-limit exceptions.'],
        'D' => ['Collections and Repayment Reports', 'Daily collections, payment receipts, expected versus actual repayments, repayment schedules, payment channels and reversals.'],
        'E' => ['Arrears and Recovery Reports', 'Overdue loans, arrears aging, defaulting clients, promises to pay, recovery activities, penalties and restructuring.'],
        'F' => ['Finance and Accounting Reports', 'Cashbook, income, expenses, fees, interest, cash movements, financial adjustments and reconciliation summaries.'],
        'G' => ['End-of-Day Reports', 'Cashier closing, cash variances, daily transaction summaries, EOD approvals and reopened business dates.'],
        'H' => ['Branch and Staff Performance', 'Branch comparisons, loan officer productivity, cashier performance, collection targets and staff activity.'],
        'I' => ['Guarantor and Collateral Reports', 'Guarantor register, security register, collateral valuations, expiring documents and released security.'],
        'J' => ['Management and Risk Analytics', 'Portfolio at risk, concentration, trends, growth, collection efficiency and management performance indicators.'],
        'K' => ['Audit, Compliance and Security', 'Audit trails, user access, approval history, unusual activity, transaction corrections and security events.'],
        'L' => ['Documents and Operational Reports', 'Missing documentation, incomplete profiles, generated statements, receipt registers, backup status and report-export history.'],
    ];

    /**
     * id => [title, key information, engine, filters, calculation basis note].
     * Filters: f = period, b = branch, o = loan officer, c = cashier, m = payment channel, k = client, l = loan number, s = status, d = days.
     */
    private static function defs(): array
    {
        return [
            'LP-01' => ['Portfolio Summary', 'Active loans, principal outstanding, arrears and loan counts', 'portfolio_summary', 'b', 'Balances come from the loan ledger as at the reporting date.'],
            'LP-02' => ['Active Loans Register', 'Loan ID, client, principal, balance, due date and status', 'active_loans', 'ob', ''],
            'LP-03' => ['Disbursement Register', 'Approved and disbursed amounts, dates and authorizers', 'disbursement_register', 'fb', 'Gross principal, deducted fees and net released are shown separately.'],
            'LP-04' => ['Outstanding Principal Report', 'Remaining principal by loan, client and branch', 'principal_outstanding', 'ob', ''],
            'LP-05' => ['Loan Maturity Report', 'Loans approaching maturity or due for closure', 'loan_maturity', 'fb', 'Loans whose due date falls inside the period (open loans only).'],
            'LP-06' => ['Closed Loans Report', 'Fully repaid loans, closure dates and final balances', 'closed_loans', 'fob', ''],
            'LP-07' => ['Loan Product Performance', 'Loan volumes and performance by product', 'product_performance', 'b', 'Bono has one configurable loan product; products are therefore shown by repayment frequency.'],
            'LP-08' => ['Loan Balance Reconciliation', 'Loan subledger balances against the relevant financial control accounts, if configured', 'loan_balance_recon', 'b', 'Each loan balance is recomputed from disbursements, charges and posted payments and compared with the ledger. No general-ledger control account is configured.'],
            'LP-09' => ['Loan Renewal Report', 'Repeat applications and renewals', 'loan_renewals', 'fb', 'A renewal is a new loan for a client who had an earlier loan.'],
            'LP-10' => ['Disbursement Pipeline', 'Approved loans awaiting disbursement', 'disbursement_pipeline', 'ob', ''],
            'LP-11' => ['Loan Tenure Analysis', 'Portfolio distribution by repayment term', 'tenure_analysis', 'b', ''],
            'LP-12' => ['Portfolio Movement Report', 'Opening portfolio, additions, repayments, adjustments and closing portfolio', 'portfolio_movement', 'fb', 'Principal only: opening + disbursed − principal repaid ± adjustments = closing.'],
            'CL-01' => ['Complete Client Register', 'Client number, name, contact and registration date', 'client_register', 'b', ''],
            'CL-02' => ['New Clients Report', 'Clients registered within the selected period', 'new_clients', 'fb', ''],
            'CL-03' => ['Client Loan History', 'Applications, loans, repayments and closures', 'client_loan_history', 'k', ''],
            'CL-04' => ['Repeat Borrowers Report', 'Clients with previous or multiple loans', 'repeat_borrowers', 'b', ''],
            'CL-05' => ['Inactive Clients Report', 'Clients without recent activity', 'inactive_clients', 'bd', 'No loan application, disbursement or payment in the last N days (default 180).'],
            'CL-06' => ['Multiple Loan Exposure', 'Clients with multiple active accounts', 'multi_exposure', 'b', ''],
            'CL-07' => ['Incomplete Client Profiles', 'Missing required information or documents', 'incomplete_profiles', 'b', 'Required: national ID, date of birth, address, income, photograph.'],
            'CL-08' => ['Client Statement', 'Client-specific transactions and outstanding obligations', 'client_statement', 'fk', 'Choose a client. Chronological ledger entries with a running balance.'],
            'LA-01' => ['Applications Register', 'All applications and their current status', 'loan_applications', 'fob', ''],
            'LA-02' => ['Pending Applications', 'Applications awaiting review or decision', 'pending_applications', 'ob', ''],
            'LA-03' => ['Approved Loans Report', 'Approved amounts, approvers and dates', 'approved_loans', 'fob', ''],
            'LA-04' => ['Rejected Applications', 'Rejection reasons and decision history', 'rejected_loans', 'fob', ''],
            'LA-05' => ['Application Processing Time', 'Time between application, assessment and decision', 'processing_time', 'fb', 'Calendar days.'],
            'LA-06' => ['Loan Officer Recommendations', 'Recommended amounts versus approved amounts', 'officer_recommendations', 'fob', ''],
            'LA-07' => ['Approval Limit Exceptions', 'Exceptions, delegated authority and approval evidence', 'approval_exceptions', 'fb', 'Escalations to a higher authority and approvals recorded as exceptions.'],
            'LA-08' => ['Returned Applications', 'Applications requiring corrections or additional documents', 'returned_applications', 'fob', ''],
            'CR-01' => ['Daily Collections Report', 'Total payments received by date and cashier', 'daily_collections', 'fcmb', 'Posted payments by business date, split by allocation component.'],
            'CR-02' => ['Collections by Loan Officer', 'Collections attributable to assigned portfolios', 'collection_by_officer', 'fb', 'Collections on loans assigned to each officer.'],
            'CR-03' => ['Expected vs Actual Collections', 'Scheduled repayments compared with received payments', 'expected_vs_actual', 'fb', 'Expected = scheduled instalments falling due in the month; actual = posted payments received in the month.'],
            'CR-04' => ['Repayment Schedule Report', 'Due dates, installments, amounts due and balances', 'repayment_schedule', 'fbl', 'Current schedule version of each loan. Leave the loan number empty for all loans due in the period.'],
            'CR-05' => ['Missed Installments Report', 'Installments due but unpaid or underpaid', 'missed_installments', 'b', 'Instalments past their due date where payments to date are less than scheduled to date.'],
            'CR-06' => ['Payment Receipt Register', 'Receipt numbers, payment references and amounts', 'receipt_register', 'fcmb', ''],
            'CR-07' => ['Payment Channel Report', 'Cash, mobile money, bank and other configured channels', 'collection_by_method', 'fb', ''],
            'CR-08' => ['Partial Payments Report', 'Accounts with incomplete installment payments', 'partial_payments', 'fb', ''],
            'CR-09' => ['Reversed Payments Report', 'Reversals, reasons, approvers and linked transactions', 'reversed_payments', 'fb', ''],
            'CR-10' => ['Loan Repayment History', 'Chronological payment history per loan', 'repayment_history', 'l', 'Enter the loan number.'],
            'AR-01' => ['Overdue Loans Register', 'Overdue accounts and amounts', 'overdue_loans', 'ob', ''],
            'AR-02' => ['Arrears Aging Report', 'Overdue balances grouped by days past due', 'arrears_aging', 'b', 'Basis: TOTAL outstanding balance of loans past maturity, grouped by days since the loan became overdue. This is not the same as overdue instalment amounts.'],
            'AR-03' => ['Defaulting Borrowers', 'Accounts meeting the configured default criteria', 'defaulted_loans', 'ob', ''],
            'AR-04' => ['Collection Follow-up Report', 'Calls, visits, notes and follow-up outcomes', 'followup_report', 'fob', ''],
            'AR-05' => ['Promises-to-Pay Report', 'Promised dates, amounts and whether promises were met', 'promises_report', 'fob', 'A promise is met when payments of at least the promised amount were received between the action date and the promised date.'],
            'AR-06' => ['Penalties Report', 'Penalties assessed, paid, outstanding or waived', 'penalties_report', 'fb', 'Penalties follow the configured and approved loan policy.'],
            'AR-07' => ['Recovery Performance', 'Recoveries against overdue balances', 'recovery_performance', 'fb', 'Payments received in the period on loans that are overdue or were overdue.'],
            'AR-08' => ['Restructured Loans', 'Restructuring approvals and revised terms', 'restructured_loans', 'fb', 'Lists recorded restructuring adjustments. Restructuring is not enabled by default.'],
            'AR-09' => ['Write-off Register', 'Approved write-offs and amounts', 'write_offs', 'fb', 'Lists recorded write-off adjustments. Write-off is not enabled by default.'],
            'AR-10' => ['High-Risk Accounts', 'Accounts meeting configurable risk indicators', 'high_risk_accounts', 'ob', 'Indicators: overdue more than 30 days, a broken promise to pay, or more than one overdue loan for the client.'],
            'FN-01' => ['Cashbook', 'Opening balance, receipts, payments and closing balance', 'cashbook', 'fb', 'Physical cash movements only (mobile money and bank are separate channels).'],
            'FN-02' => ['Income Report', 'Recorded interest income, fees and other configured income', 'income_report', 'fb', 'CASH BASIS: interest, fees and penalties actually received in the period.'],
            'FN-03' => ['Expense Report', 'Operating expenses by category and branch', 'expense_report', 'fb', ''],
            'FN-04' => ['Fee Collection Report', 'Processing, application, inspection and other configured fees', 'fees_report', 'fb', ''],
            'FN-05' => ['Interest Report', 'Interest charged, collected and outstanding, by accounting treatment', 'interest_report', 'fb', 'Charged = contractual interest of loans disbursed in the period; collected = cash received; outstanding = receivable (accrued, not received).'],
            'FN-06' => ['Cash Movement Report', 'Cash transfers in, transfers out and adjustments', 'daily_cash_movement', 'fb', ''],
            'FN-07' => ['Disbursement Reconciliation', 'Disbursement records compared with payment-channel records', 'disbursement_recon', 'fb', 'Each disbursement voucher is compared with the cash/channel movement recorded for it.'],
            'FN-08' => ['Financial Adjustments Register', 'Authorized corrections and adjustment reasons', 'financial_adjustments', 'fb', ''],
            'FN-09' => ['Cashier Transaction Summary', 'Financial transactions by cashier', 'cashier_summary', 'fcb', ''],
            'FN-10' => ['Payment Channel Reconciliation', 'Recorded payments compared with available external settlement records', 'channel_recon', 'fb', 'Internal reconciliation of payments to cash/channel movements. External settlement statements are not imported.'],
            'FN-11' => ['Financial Control Summary', 'Configured financial control balances and exceptions', 'control_summary', 'b', ''],
            'FN-12' => ['Trial Balance / Financial Statements', 'Trial balance, income statement, balance sheet, general ledger and journal register', 'gl_statements', 'fbv', 'Built from the General Ledger. Interest, fees and penalties are income when received (cash basis); amounts still owed are held as receivables against unearned income.'],
            'EOD-01' => ['Daily Operations Summary', 'Applications, disbursements, collections and expenses', 'daily_ops_summary', 'fb', ''],
            'EOD-02' => ['Cashier Closing Report', 'Opening float, cash receipts, payments and counted cash', 'cashier_closing', 'fcb', ''],
            'EOD-03' => ['Cash Variance Report', 'Expected versus actual closing cash and explanations', 'cashier_variances', 'fcb', ''],
            'EOD-04' => ['Branch EOD Status', 'Open, submitted, approved or reopened business dates', 'branch_eod_status', 'fb', ''],
            'EOD-05' => ['EOD Approval Register', 'Preparers, reviewers, approvals and timestamps', 'eod_history', 'fb', ''],
            'EOD-06' => ['Reopened Business Dates', 'Reasons, authorizers and subsequent adjustments', 'reopened_dates', 'fb', ''],
            'EOD-07' => ['Daily Transaction Register', 'Transactions posted during the business date', 'daily_transactions', 'fb', ''],
            'EOD-08' => ['EOD Exceptions Report', 'Missing reconciliations, unresolved variances and failed closure attempts', 'eod_exceptions', 'fb', ''],
            'BP-01' => ['Branch Performance Summary', 'Portfolio, collections, disbursements and arrears by branch', 'branch_comparison', 'f', 'PAR = principal of overdue loans ÷ total outstanding principal.'],
            'BP-02' => ['Branch Comparison Report', 'Comparable branch metrics over the same period', 'branch_comparison', 'f', 'PAR = principal of overdue loans ÷ total outstanding principal.'],
            'BP-03' => ['Loan Officer Productivity', 'Applications, approvals, portfolio and collections', 'officer_performance', 'fb', 'Counts follow each officer\'s assigned portfolio in the period; raw collection totals alone can mislead.'],
            'BP-04' => ['Cashier Performance', 'Transactions, till variances and reconciliation status', 'cashier_performance', 'fb', ''],
            'BP-05' => ['Collection Target Report', 'Actual collections against assigned targets', 'collection_targets', 'fb', 'Monthly targets are set on the branch and user records; the target for the period is the monthly target × the months covered.'],
            'BP-06' => ['Staff Activity Report', 'Recorded work actions and transaction activity', 'staff_activity', 'fb', ''],
            'BP-07' => ['Branch Arrears Report', 'Overdue accounts and balances per branch', 'branch_arrears', 'b', ''],
            'BP-08' => ['Branch EOD Compliance', 'Completion, delays and outstanding reconciliation issues', 'branch_eod_compliance', 'fb', ''],
            'GC-01' => ['Guarantor Register', 'Guarantor details and linked loans', 'guarantor_register', 'b', ''],
            'GC-02' => ['Collateral Register', 'Assets pledged, descriptions and linked accounts', 'security_register', 'b', ''],
            'GC-03' => ['Collateral Valuation Report', 'Recorded values, valuation dates and valuers', 'collateral_valuation', 'b', ''],
            'GC-04' => ['Missing Security Documents', 'Required collateral records or documents not supplied', 'missing_security_docs', 'b', 'Securities without any uploaded document or without a valuation date.'],
            'GC-05' => ['Expiring Documents Report', 'Documents requiring renewal or review', 'expiring_documents', 'bd', 'Valuations older than 12 months and loan offers that expire within N days (default 30).'],
            'GC-06' => ['Released Collateral Register', 'Assets released, dates and authorization details', 'released_collateral', 'fb', ''],
            'MR-01' => ['Executive Portfolio Dashboard', 'Consolidated portfolio and key performance indicators', 'exec_dashboard', 'b', ''],
            'MR-02' => ['Portfolio at Risk (PAR)', 'At-risk portfolio by defined days-past-due threshold', 'par', 'b', 'PARn = outstanding PRINCIPAL of loans more than n days past maturity ÷ gross outstanding principal of all open loans.'],
            'MR-03' => ['Portfolio Concentration', 'Exposure by branch, product or borrower segment', 'concentration', 'b', ''],
            'MR-04' => ['Collection Efficiency', 'Collections compared with scheduled amounts due', 'collection_efficiency', 'fb', 'Efficiency = payments received ÷ scheduled amounts falling due in the same month.'],
            'MR-05' => ['Loan Book Growth', 'Portfolio changes across reporting periods', 'loan_book_growth', 'fb', ''],
            'MR-06' => ['Arrears Trend', 'Movement in overdue balances and account counts', 'arrears_trend', 'fb', 'Month-end reconstruction from the dates loans became overdue; balances shown are current balances of those loans.'],
            'MR-07' => ['Loan Approval Trend', 'Application volumes and decisions over time', 'approval_trend', 'fb', ''],
            'MR-08' => ['Branch Risk Ranking', 'Comparable risk indicators across branches', 'branch_risk_ranking', '', 'Ranked by PAR30 (highest risk first).'],
            'AU-01' => ['Audit Trail Report', 'Who performed which action, when and on which record', 'audit_trail', 'fb', 'Limited to the audit scope the user has been given.'],
            'AU-02' => ['User Access Report', 'Active users, assigned roles and branch access', 'user_access', '', ''],
            'AU-03' => ['Permission Changes Report', 'Role and permission changes, actors and timestamps', 'permission_changes', 'f', ''],
            'AU-04' => ['Approval History Report', 'Loan and financial approval decisions', 'approvals_log', 'fb', ''],
            'AU-05' => ['Financial Exception Report', 'Reversals, adjustments, overrides and exceptions', 'financial_exceptions', 'fb', ''],
            'AU-06' => ['Login and Security Events', 'Login failures, account lockouts and security events', 'security_events', 'f', ''],
            'AU-07' => ['Sensitive Record Access', 'Access to protected documents and sensitive records', 'sensitive_access', 'f', 'Recorded when a protected document, statement or sensitive client profile is opened.'],
            'AU-08' => ['Unauthorized Access Attempts', 'Denied operations and relevant security details', 'denied_attempts', 'f', ''],
            'DO-01' => ['Missing Documents Report', 'Applications or clients with incomplete documentation', 'missing_documents', 'b', ''],
            'DO-02' => ['Loan Agreement Register', 'Agreements generated, signed or pending', 'agreement_register', 'fb', 'Offer accepted = agreement signed; approved but not accepted = pending.'],
            'DO-03' => ['Receipt Register', 'Generated receipts and receipt status', 'receipt_register', 'fcmb', ''],
            'DO-04' => ['Statement Generation Log', 'Statements generated and their authorized recipients', 'statement_log', 'f', ''],
            'DO-05' => ['Report Export History', 'User, report, time and export outcome', 'export_history', 'f', ''],
            'DO-06' => ['Backup and System Health', 'Backup completion, failures, last successful backup and system alerts', 'backup_health', '', ''],
        ];
    }

    public static function all(): array
    {
        static $c = null;
        if ($c !== null) return $c;
        $c = [];
        foreach (self::defs() as $id => [$title, $info, $engine, $filters, $note]) {
            $cat = match (substr($id, 0, 2)) { 'LP' => 'A', 'CL' => 'B', 'LA' => 'C', 'CR' => 'D', 'AR' => 'E', 'FN' => 'F', 'EO' => 'G', 'BP' => 'H', 'GC' => 'I', 'MR' => 'J', 'AU' => 'K', 'DO' => 'L' };
            $c[$id] = ['id' => $id, 'cat' => $cat, 'title' => $title, 'info' => $info, 'engine' => $engine, 'filters' => $filters, 'note' => $note, 'available' => $engine !== null];
        }
        return $c;
    }

    public static function get(string $id): ?array { return self::all()[strtoupper($id)] ?? null; }

    public static function byCategory(): array
    {
        $o = [];
        foreach (self::all() as $r) $o[$r['cat']][] = $r;
        return $o;
    }

    // ------------------------------------------------------------------ default access by role (the specification's role table)
    /** role => list of report IDs, category letters ("A") or "A*" (whole category). */
    private static function defaultGrants(): array
    {
        $kall = ['AU-01', 'AU-02', 'AU-03', 'AU-04', 'AU-05'];
        return [
            'Loan Officer' => ['LP-01', 'LP-02', 'LP-04', 'LP-05', 'LP-06', 'LP-10', 'LP-11', 'B*', 'C*', 'CR-02', 'CR-03', 'CR-04', 'CR-05', 'CR-08', 'CR-10', 'AR-01', 'AR-02', 'AR-03', 'AR-04', 'AR-05', 'AR-10', 'BP-03', 'I*', 'AU-01', 'DO-01', 'DO-02'],
            'Finance Officer' => ['LP-01', 'LP-03', 'LP-04', 'LP-08', 'LP-12', 'CL-01', 'CL-02', 'CL-08', 'LA-01', 'LA-03', 'D*', 'AR-01', 'AR-02', 'AR-06', 'AR-07', 'F*', 'G*', 'BP-04', 'MR-04', 'AU-01', 'AU-05', 'DO-03', 'DO-05'],
            'Branch Manager' => ['A*', 'B*', 'C*', 'D*', 'E*', 'FN-01', 'FN-02', 'FN-03', 'FN-04', 'FN-05', 'FN-09', 'G*', 'H*', 'I*', 'MR-02', 'MR-03', 'MR-04', 'MR-06', 'MR-07', 'AU-01', 'AU-04', 'AU-05', 'DO-01', 'DO-02', 'DO-03', 'DO-04'],
            'Finance Manager' => ['LP-01', 'LP-03', 'LP-04', 'LP-08', 'LP-12', 'CL-01', 'CL-02', 'CL-08', 'LA-03', 'LA-07', 'D*', 'AR-01', 'AR-02', 'AR-06', 'AR-07', 'AR-09', 'F*', 'G*', 'BP-01', 'BP-02', 'BP-04', 'BP-05', 'MR-01', 'MR-02', 'MR-03', 'MR-04', 'MR-05', 'MR-06', 'AU-01', 'AU-04', 'AU-05', 'DO-03', 'DO-05'],
            'COO' => ['A*', 'B*', 'C*', 'D*', 'E*', 'F*', 'G*', 'H*', 'I*', 'J*', 'AU-01', 'AU-02', 'AU-03', 'AU-04', 'AU-05', 'DO-01', 'DO-02', 'DO-03', 'DO-04', 'DO-05'],
            'System Administrator' => ['AU-01', 'AU-02', 'AU-03', 'AU-06', 'AU-07', 'AU-08', 'DO-05', 'DO-06'],
            'Cashier' => ['CR-06', 'EOD-02', 'DO-03'],
            'Auditor' => ['LP-01', 'LP-02', 'LP-03', 'LP-08', 'CR-01', 'CR-03', 'CR-06', 'CR-09', 'AR-06', 'AR-09', 'F*', 'G*', 'MR-01', 'MR-02', 'AU-01', 'AU-02', 'AU-03', 'AU-04', 'AU-05', 'AU-06', 'AU-07', 'AU-08', 'DO-03', 'DO-04', 'DO-05'],
        ];
    }

    /** The technical administrator may only ever open technical / security reports, whatever the access matrix says. */
    public const ADMIN_ALLOWED = ['AU-01', 'AU-02', 'AU-03', 'AU-06', 'AU-07', 'AU-08', 'DO-05', 'DO-06'];

    public static function defaultAllows(string $role, string $id): bool
    {
        $g = self::defaultGrants()[$role] ?? [];
        $cat = self::get($id)['cat'] ?? '';
        foreach ($g as $p) {
            if ($p === $id) return true;
            if (strlen($p) === 2 && $p[1] === '*' && $p[0] === $cat) return true;
        }
        return false;
    }

    /** Explicit role decisions: [role][id] => 0|1 (overrides the defaults). */
    public static function roleRows(): array
    {
        $o = [];
        foreach (rows('SELECT role, report_id, allowed FROM report_role_access') as $r) $o[$r['role']][$r['report_id']] = (int)$r['allowed'];
        return $o;
    }

    public static function roleAllows(string $role, string $id, ?array $rows = null): bool
    {
        $rows = $rows ?? self::roleRows();
        if ($role === 'System Administrator' && !in_array($id, self::ADMIN_ALLOWED, true)) return false;
        return isset($rows[$role][$id]) ? $rows[$role][$id] === 1 : self::defaultAllows($role, $id);
    }

    /** Effective access of a user to a report (module permission + role grant + user grant/block). */
    public static function userAllows(int $userId, string $role, string $id, ?array $perms = null): bool
    {
        $r = self::get($id);
        if (!$r || !$r['available']) return false;
        $perms = $perms ?? user_permissions($userId);
        if (!in_array('report.view', $perms, true)) return false;
        if ($role === 'System Administrator' && !in_array($id, self::ADMIN_ALLOWED, true)) return false;
        $u = row('SELECT allowed FROM user_report_access WHERE user_id = ? AND report_key = ?', [$userId, $id]);
        if ($u) return (int)$u['allowed'] === 1;
        return self::roleAllows($role, $id);
    }

    /** Reports the signed-in user may open, keyed by ID. Cached per request. */
    public static function mine(): array
    {
        $c = &$GLOBALS['__report_cache'];
        $c = $c ?? [];
        $u = (int)(uid() ?? 0);
        if (!isset($c[$u])) {
            $c[$u] = [];
            if ($u) {
                $role = (string)user()['role']; $perms = user_permissions($u); $rr = self::roleRows();
                $ur = []; foreach (rows('SELECT report_key, allowed FROM user_report_access WHERE user_id = ?', [$u]) as $x) $ur[$x['report_key']] = (int)$x['allowed'];
                foreach (self::all() as $id => $r) {
                    if (!$r['available'] || !in_array('report.view', $perms, true)) continue;
                    $ok = ($role === 'System Administrator' && !in_array($id, self::ADMIN_ALLOWED, true)) ? false : (array_key_exists($id, $ur) ? $ur[$id] === 1 : self::roleAllows($role, $id, $rr));
                    if ($ok) $c[$u][$id] = $r;
                }
            }
        }
        return $c[$u];
    }

    public static function reset(): void { $GLOBALS['__report_cache'] = []; }
}
