# Database — `database/migrations/001_initial.sql`

One portable schema for **PostgreSQL** (production) and **SQLite** (evaluation). Foreign keys, unique and CHECK constraints on every status column; money = integer UGX (BIGINT on PostgreSQL).
Protection triggers are created separately (`app/lib/triggers.php`) so they are identical in intent on both engines.

| Group | Tables |
|---|---|
| Organization & access | `branches`, `users` (MFA fields), `user_branches`, `roles`, `role_permissions`, `user_permissions`, `settings`, `business_calendar` (institution or branch), `approval_limits`, `sequences`, `app_sessions`, `login_events` |
| Clients & lending | `clients`, `guarantors`, `loan_guarantors`, `securities`, `security_history`, `loans` (approved-terms snapshot, escalation fields), `loan_status_history`, `credit_assessments`, `loan_approvals` (approver level), `disbursements`, `repayment_schedules` |
| Money in / out | `payments`, `payment_allocations`, `receipts`, `customer_credits`, `credit_events`, `loan_ledger`, `penalties`, `loan_adjustments`, `collection_actions`, `loan_closures` |
| Cash management | `cash_accounts` (per branch), `cashier_sessions`, `cash_movements`, `cash_counts`, `cash_transfers`, `expense_categories`, `expenses` |
| End of Day (per branch) | `business_days` (PK `branch_id + business_date`), `eod_records` (versioned, `Escalated to COO`), `eod_reopen_requests` |
| Controls | `audit_logs`, `approval_requests` (reversals, restore, backup requests), `attachments`, `backup_history`, `import_history`, `export_history`, `schema_migrations` |
| View | `v_loan_balances` — outstanding and paid per component, derived from `loan_ledger` |

## Branch ownership

`branch_id` (NOT NULL, FK → `branches`) is on: `clients`, `guarantors`, `loans`, `disbursements`, `payments`, `customer_credits`, `cash_accounts`, `cashier_sessions`, `cash_movements`, `cash_transfers`, `expenses`,
`business_days`, `eod_records`, `eod_reopen_requests`, `approval_requests`; nullable on `audit_logs`, `attachments`. Everything else belongs to a branch through its loan.
`business_calendar.branch_id = 0` means "all branches".

## Key relationships

```
branches 1─* clients 1─* loans 1─* repayment_schedules           loans 1─1 disbursements ─* cash_movements
loans 1─* loan_guarantors *─1 guarantors          loans 1─* securities 1─* security_history
loans 1─* payments 1─* payment_allocations        payments 1─1 receipts      payments 0..1─1 customer_credits
loans 1─* loan_ledger (append-only)               loans 1─* penalties (UNIQUE loan + date)
users 1─* cashier_sessions 1─* cash_movements     branches 1─* business_days 1─* eod_records / eod_reopen_requests
users *─* branches (user_branches)                approval_limits (category × branch)
```

## Integrity rules in the database

* UNIQUE: all business numbers, NIN (partial index), `lower(username)`, non-cash payment reference per method, payment `txn_token`, `disbursements.loan_id`, penalty per loan per date, approval limit per category and branch.
* Triggers: audit, ledger, cash movements, payment allocations, login history — no update / delete; payments, receipts, disbursements, loans, clients, users, branches, EOD records, approval requests — no delete; the amount / loan / client / branch / method / dates of a payment cannot change;
  PostgreSQL additionally blocks `TRUNCATE` on the history tables.

## Reading balances

```sql
SELECT * FROM v_loan_balances WHERE loan_id = ?;   -- principal_out, interest_out, penalty_out, fee_out, total_out, paid_*
```
