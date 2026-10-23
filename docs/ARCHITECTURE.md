# Architecture (v3, cloud)

## Layers

| Layer | Location | Notes |
|---|---|---|
| Presentation | `index.php`, `app/pages/*`, `app/views/*`, `assets/*` | server-rendered pages, vanilla JS, no external assets, strict CSP |
| Business logic | `app/services/*` | `calc` (pure maths), `loans`, `payments`, `penalty`, `cash`, `eod`, `approvals`, `reports`, `backup`, `system` |
| Data access | `app/lib/db.php`, `database/migrations/*.sql`, `app/lib/triggers.php` | one PDO wrapper over **PostgreSQL** or **SQLite**; portable schema, per-engine triggers |
| Security | `app/lib/auth.php`, `perms.php`, `branch.php`, `mfa.php`, `config.php` | sessions, passwords, MFA, RBAC, branch scoping, audit, secrets |
| Files | `app/lib/storage.php` | local private volume or S3-compatible bucket |
| Operations | `pages/health.php`, `services/system.php`, `services/backup.php`, `deploy/*`, `Dockerfile`, `docker-compose.yml` | health, backups, deployment |

```
browser ─HTTPS─► proxy/LB ─► index.php (HTTPS redirect, CSP, MFA gate, route, permission, branch context)
                              └► pages/<module>.php ─► post_action(): CSRF → service → flash → redirect (PRG)
                                                        services: rules + validation + audit + authority checks
                                                        └► tx( SQL ) ─► PostgreSQL / SQLite      files ─► Storage
```

## Multi-branch model

* `branches` master table; `user_branches` (assigned branches, primary). The COO holds `branch.all`; the System Administrator is assigned none and holds no business permissions.
* Every business row has `branch_id` (clients, guarantors, loans, payments, disbursements, customer credits, cash accounts / sessions / movements / transfers, expenses, business days, EOD records, approval requests, audit entries, attachments).
  Loan children (ledger, schedule, penalties, securities, follow-ups) inherit it via `loans`.
* **Context**: `branch_id()` = the branch the user is working in (switcher). **Reads**: list queries are wrapped in `branch_scope('col')`; single records call `assert_branch()`.
  **Writes / postings** call `assert_active_branch()` — a user posts only in the branch they are currently working in, and only if it is active.
* **Business date per branch**: `business_days(branch_id, business_date)`; `current_bd()`, `posting_date()` and the EOD service take the branch. A branch closing its day never blocks another.
* Branch isolation is verified by a test that renders every list page and report as a user of another branch and fails on any leaked record.

## Authority, limits and escalation

`Approvals::required(category, amount, branch)` → *Branch Manager* or *COO* from the institution-configured `approval_limits` (branch override → institution default → *not configured* = Branch Manager).
`Approvals::assert()` runs inside loan approval, disbursement authorization, expenses, waivers, payment reversals and EOD variance / reconciliation. `Approvals::inbox()` builds *My Approvals*.
Maker-checker requests (`approval_requests`) back payment reversals and system recovery.

## Money, ledger and numbering

* UGX as integers; rates as integer basis points; one half-up rounding rule. `loan_ledger` is append-only; balances come from `v_loan_balances` (principal / interest / penalty / fee, paid components).
* Receipts, loans, clients… use `sequences` rows updated inside a transaction (row lock ⇒ concurrent users get distinct numbers). Duplicate posting is prevented by one-time form tokens and unique indexes
  (payments `txn_token`, non-cash references, `disbursements.loan_id`, penalty per loan per date).

## State machines

```
Loan:  Draft → Submitted → Under Review ──(Branch Manager ≤ limit | COO above / escalated)──► Approved → Active ⇄ Overdue → Closed
       Returned for Correction / Rejected / Cancelled
Till:  Open → Submitted → Reconciled   (Investigation / reopen by a reviewer; reconciler ≠ cashier)
Day (per branch): Open → Awaiting Manager Review | Escalated to COO → Closed → Reopened by Authorization → … (version n+1)
Request (reversal / recovery): Pending → Approved | Rejected   (approver ≠ requester)
```

## Database engines

* One portable schema file (`INTEGER PRIMARY KEY AUTOINCREMENT`, `INTEGER`, `TEXT`). For PostgreSQL the runner converts to `BIGINT GENERATED … AS IDENTITY` / `BIGINT`. Dates and timestamps are ISO text on both.
* App SQL is portable; differences live in `db.php`: `LIKE → ILIKE` on PostgreSQL, `RETURNING id` on insert, NUMERIC → int normalization, `sql_daydiff()`, `FOR UPDATE`, `BEGIN` vs `BEGIN IMMEDIATE`.
* Protection triggers (no update / delete of audit, ledger, cash movements, payments, allocations, login history; no delete of loans, clients, users, branches, receipts, disbursements, EOD records; TRUNCATE blocked on PostgreSQL)
  are generated from one list for both engines.
* Lesson learned the hard way: SQLite silently treats an unknown double-quoted identifier as a string, so a broken query can *look* fine; PostgreSQL rejects it. The test-suite therefore runs on both engines and includes a source lint.

## Security design

HTTPS (HSTS, redirect in production, `__Host-` cookie); strict CSP with no inline script; CSRF tokens on every POST; server-side permission and branch checks everywhere; bcrypt; TOTP MFA with AES-256-GCM encrypted seeds
(key = `APP_KEY`); login throttling / lock-out; append-only audit; private file storage behind permission checks; error messages never expose stack traces; secrets only from the environment.

## File map

```
index.php                  front controller: HTTPS, headers, routing, MFA gate, permission gate
app/bootstrap.php          loads libs + services, error logging, sessions
app/lib/                   config, triggers, db, helpers, settings, perms, mfa, storage, branch, auth, install, ui
app/services/              calc, approvals, eod, loans, cash, payments, penalty, backup, reports, system
app/pages/                 one file per screen (+ health, mfa, inbox, compliance, branches, security, system)
database/migrations/       numbered portable SQL
deploy/, Dockerfile, docker-compose.yml, .env.example   cloud deployment
tests/run.php              automated tests (SQLite / PostgreSQL)       tools/seed_demo.php   demo data (evaluation only)
```
