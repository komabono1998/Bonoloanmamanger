<?php
/**
 * Append-only protection for financial history, defined once and generated for SQLite or PostgreSQL.
 * Even a database administrator running a stray UPDATE/DELETE gets an error (superusers can still drop triggers,
 * which is why production should use a limited application role - see docs/CLOUD_DEPLOYMENT.md).
 */
const TRIGGERS_VERSION = 'v3.4';

/** [table, event (UPDATE|DELETE), columns|null, message] */
function protection_rules(): array
{
    return [
        ['audit_logs', 'UPDATE', null, 'Audit records cannot be modified.'],
        ['audit_logs', 'DELETE', null, 'Audit records cannot be deleted.'],
        ['login_events', 'UPDATE', null, 'Login history cannot be modified.'],
        ['login_events', 'DELETE', null, 'Login history cannot be deleted.'],
        ['loan_ledger', 'UPDATE', null, 'Ledger entries cannot be modified. Post a reversal instead.'],
        ['loan_ledger', 'DELETE', null, 'Ledger entries cannot be deleted. Post a reversal instead.'],
        ['cash_movements', 'UPDATE', null, 'Cash movements cannot be modified. Post a reversal instead.'],
        ['cash_movements', 'DELETE', null, 'Cash movements cannot be deleted. Post a reversal instead.'],
        ['payments', 'DELETE', null, 'Payments cannot be deleted. Reverse the payment instead.'],
        ['payments', 'UPDATE', 'loan_id, client_id, branch_id, amount, method, payment_date, business_date, cashier_id', 'Posted payments cannot be altered. Reverse the payment instead.'],
        ['payment_allocations', 'UPDATE', null, 'Payment allocations cannot be modified.'],
        ['payment_allocations', 'DELETE', null, 'Payment allocations cannot be deleted.'],
        ['receipts', 'DELETE', null, 'Receipts cannot be deleted.'],
        ['disbursements', 'DELETE', null, 'Disbursements cannot be deleted.'],
        ['eod_records', 'DELETE', null, 'EOD records cannot be deleted.'],
        ['loans', 'DELETE', null, 'Loans cannot be deleted. Cancel or close the loan instead.'],
        ['clients', 'DELETE', null, 'Clients cannot be deleted. Archive the client instead.'],
        ['users', 'DELETE', null, 'Users cannot be deleted. Deactivate the account instead.'],
        ['branches', 'DELETE', null, 'Branches cannot be deleted. Deactivate the branch instead.'],
        ['approval_requests', 'DELETE', null, 'Approval requests cannot be deleted.'],
        ['loan_workflow_log', 'UPDATE', null, 'The workflow log cannot be modified.'],
        ['loan_workflow_log', 'DELETE', null, 'The workflow log cannot be deleted.'],
        ['eod_resets', 'UPDATE', null, 'End-of-Day reset records cannot be modified.'],
        ['eod_resets', 'DELETE', null, 'End-of-Day reset records cannot be deleted.'],
        ['gl_journals', 'UPDATE', null, 'Journals cannot be modified. Post a reversing journal instead.'],
        ['gl_journals', 'DELETE', null, 'Journals cannot be deleted. Post a reversing journal instead.'],
        ['gl_journal_lines', 'UPDATE', null, 'Journal lines cannot be modified. Post a reversing journal instead.'],
        ['gl_journal_lines', 'DELETE', null, 'Journal lines cannot be deleted. Post a reversing journal instead.'],
        ['gl_accounts', 'DELETE', null, 'Accounts cannot be deleted. Deactivate the account instead.'],
        ['gl_accounts', 'UPDATE', 'code, type', 'An account code and type cannot be changed once created. Deactivate it and create a new one.'],
    ];
}

function install_triggers(): void
{
    $pdo = db();
    foreach (protection_rules() as $i => [$table, $event, $cols, $msg]) {
        $name = 'trg_' . $table . '_' . strtolower($event) . ($cols ? '_cols' : '');
        $of = $cols ? " OF $cols" : '';
        if (db_is_pg()) {
            $fn = 'fn_' . $name;
            $pdo->exec("CREATE OR REPLACE FUNCTION $fn() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '" . str_replace("'", "''", $msg) . "'; END; \$\$ LANGUAGE plpgsql");
            $pdo->exec("DROP TRIGGER IF EXISTS $name ON $table");
            $pdo->exec("CREATE TRIGGER $name BEFORE $event$of ON $table FOR EACH ROW EXECUTE FUNCTION $fn()");
        } else {
            $pdo->exec("DROP TRIGGER IF EXISTS $name");
            $pdo->exec("CREATE TRIGGER $name BEFORE $event$of ON $table BEGIN SELECT RAISE(ABORT, '" . str_replace("'", "''", $msg) . "'); END");
        }
    }
    if (db_is_pg()) {   // TRUNCATE would bypass row triggers
        $pdo->exec("CREATE OR REPLACE FUNCTION fn_no_truncate() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'Financial history cannot be truncated.'; END; \$\$ LANGUAGE plpgsql");
        foreach (['audit_logs', 'loan_ledger', 'cash_movements', 'payments', 'login_events', 'gl_journals', 'gl_journal_lines'] as $t) {
            $pdo->exec("DROP TRIGGER IF EXISTS trg_{$t}_truncate ON $t");
            $pdo->exec("CREATE TRIGGER trg_{$t}_truncate BEFORE TRUNCATE ON $t FOR EACH STATEMENT EXECUTE FUNCTION fn_no_truncate()");
        }
    }
}
