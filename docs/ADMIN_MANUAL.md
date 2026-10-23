# Administrator manual (System Administrator)

The System Administrator runs the platform: accounts, branch set-up, access, security, backups and health. The role holds **every user right**, with access to all reports and all configuration; every action is still recorded in the audit trail.
records by default — it cannot approve loans, post payments, approve End-of-Day or waive penalties, and the system refuses to let anyone grant it those rights.

## 1. First-time setup

1. Deploy (see [CLOUD_DEPLOYMENT.md](CLOUD_DEPLOYMENT.md)) or, for evaluation, open `http://localhost/Bonoloanmamanger/`.
2. **Setup** page: institution name, first branch (name + short code), your administrator account (strong password).
3. At first sign-in you must **set up multi-factor authentication** (*My Account & Security → Manage MFA*): scan/enter the key in an authenticator app, confirm a code, **save the recovery codes**.

## 2. Configure the institution (suggested order)

| Step | Where | Notes |
|---|---|---|
| Branches | *Branches* | code, name, location, manager, working days, closing time. Each new branch gets its Main Safe / Bank / Mobile Money accounts. Deactivate only when no open day or loans |
| Staff | *Users & access → New user* | one account per person, role, **branches ticked**, primary branch, temporary password (forces change at first login). COO sees all branches; the administrator needs none |
| Fine-tuning | *User → Individual permissions* | within the security boundaries (an administrator can't give financial rights; technical rights stay with the System Administrator). A reason is mandatory and audited. To let a Finance Officer collect cash add `payment.record` + `till.use` for that person |
| Role permissions | *Users & access → Role permissions* | edit a role's defaults; the *Access matrix* tab shows who can do what |
| Organization | *Settings → Organization / Numbering* | name, address, logo, receipt footer, prefixes |
| Security | *Settings → Security & recovery* | session timeout, password length / expiry, MFA roles, backup folder & schedule, restore authorization, **RTO / RPO** (values come from the institution) |
| Policy (COO) | *Settings & limits* (COO) | lending terms, penalties, payment rules, approval limits — the administrator does not set these |

## 2a. Institution profile, letterhead and Staff IDs

* **Settings & limits** opens on a home page with a set-up checklist and one card per area. Start with **Institution profile**.
* Name, tagline, address, town, country, telephones, email, website, registration number, TIN, brand colour and **logo** are entered once. They appear automatically on receipts, loan offers and forms, guarantor forms, schedules, statements, vouchers, End-of-Day summaries, reports (screen, print, Excel) and the sign-in page. A live preview is shown on the profile page.
* Every staff account receives a unique **Staff ID** (`CODE-S-0001`, where CODE is the *Institution code*). Staff can sign in with username, email or Staff ID. Change the code or letter in Settings → Institution profile / IDs & numbering (existing IDs are not renumbered).
* Until a COO exists, the System Administrator may do the **initial** interest, fee, penalty and approval-limit configuration; afterwards only the COO can change financial policy.
* The **+ New** menu in the top bar offers role-aware shortcuts (client, loan application, payment, user, branch...).
* One deployment serves one institution. Each institution runs its own installation and creates its own account through the Setup page.

## 2b. Users: profiles, rights, reports and password resets

Open **Users & Access**, then a user:
* Edit every profile field: name, username, **Staff ID**, email, phone, role, status and branches.
* **Individual permissions** and **Reports this user can open** (untick a report to hide it from that person; branch scoping still applies). All changes need a reason and are audited.
* **Password resets are by email**: *Email password reset link* sends a one-time link (2 hours). New users can be created without a password: with an email address they receive an invitation link (72 hours). Staff can also use *Forgot your password?* on the sign-in page.
* Configure outgoing mail under **Settings -> Email** (SMTP host, port, user, password stored encrypted) and send a test message. Set `APP_URL` in `.env` so links point at your public address. Until SMTP is set, messages are written to `storage/logs/mail.log`.

## 3. Daily / periodic duties

* **System health** (also on your dashboard): database, storage, backup age, pending migrations, MFA coverage, production-readiness notes.
* **Security monitoring**: failed sign-ins, locked accounts (unlock with a reason), sign-ins from new IP addresses, MFA status per user. Reset a user's MFA only after verifying identity (reason is mandatory and audited).
* **Backup & recovery**: backups run at every approved EOD and on a schedule; run *Back up now* before upgrades. Fulfil backup requests raised by Branch Managers. Keep an off-site copy.
* **Audit trail** (technical & security scope): user, permission, role, branch, settings, backup and security events.
* Offboarding: deactivate the account the same day (never delete; history stays attributable).

## 4. Controlled recovery (restore)

1. *Backup & recovery* → choose a verified backup → **Request restore** with the reason.
2. The **COO** reviews and authorizes in *My Approvals* / the Backup page (valid 24 h; you cannot authorize your own request).
3. You execute **Restore** (type `RESTORE`). The system verifies the file, takes a *pre-restore safety backup*, restores, verifies again, and writes the audit trail.
4. Run **Data integrity check**; tell branches to re-check the day's work after the backup point.

*Managed-database PITR* is performed through the provider; record it afterwards.

## 5. Policies enforced by the platform

| Topic | Behaviour |
|---|---|
| Passwords | bcrypt; minimum length (default 10) with letters and numbers; optional expiry; admin reset forces a new password |
| Lock-out | 5 failures → 5-minute lock; 25 failures from one IP in 10 minutes → throttle |
| Sessions | idle timeout (default 15 min), secure cookie, `__Host-` prefix over HTTPS, optional shared DB sessions |
| MFA | TOTP; mandatory roles configurable (System Administrator always) |
| Audit | append-only (database triggers) — nobody, including administrators, can edit or delete; login history likewise |
| Files | private storage; served only to users with access to the record's branch |

## 6. Upgrades

Build / deploy the new version; the first request applies new migrations once. Check `/health`, then the *System health* page. Never edit an applied migration file; add a new numbered one.

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| "Multi-factor authentication is required" loop | finish MFA set-up; if the phone is lost ask another administrator to reset your MFA |
| User cannot see a record | branch assignment (Users & access) and the active branch in the top bar |
| "No business day is open" | the Finance Officer starts the day (*End of Day*) for that branch |
| Cannot approve above a limit | by design — it routes to the COO (My Approvals) |
| Backup shows failed | `pg_dump` installed? free space? (*System health → recent errors*) |
| `/health` = degraded | last verified backup is older than twice the backup interval |

## 2c. Organisations (separate workspaces)

The sign-in page has **Set up a new organisation**. Each organisation gets its own private database, branches, users, settings, files and backups, so no organisation can see another's data. Staff sign in with their **organisation code** (leave it blank for the first/main organisation); the code is remembered in the browser. A signed-in session only works in the organisation it was created in.

* SQLite: each organisation is a file in `storage/db/tenants/`. PostgreSQL: each organisation is a database named `bono_t_<code>` (the database user needs `CREATEDB`).
* Self-registration is **on locally and off in production** unless `SELF_REGISTRATION=1`. It is limited to 3 new organisations per IP address per hour; the code cannot be a reserved word.
* Files and backups are stored per organisation (`.../t_<code>/`). Run backups from each organisation's own Backup page.

## 2d. Report Centre and report access

* **Report Centre** (sidebar) is available to every profile that holds the *View reports* permission. Each person sees only the reports their profile has been given, grouped in the 12 categories (A Loan Portfolio … L Documents & Operational) of the reporting specification — 104 reports with stable IDs (LP-01, CR-03, AR-02, EOD-03, MR-02, AU-01 …). Use the search box to find a report by name, ID or content.
* **Who can open which report** — *Administration → Report Access* is a matrix of reports by role. Defaults follow the specification's role table (for example a Loan Officer: portfolio, client, application, collections and arrears for assigned records; no finance or EOD reports; the System Administrator: technical and security reports only). Tick or untick and save; each change is audited, and a role can be reset to the recommended defaults. Add or remove reports for one person on their user page.
* **Data scope is separate from access.** Whatever a user is allowed to open, the report only contains records in their own branches (and, without the *portfolio.all* permission, their own portfolio); a cashier sees only their own transactions. Every report prints its scope, period, currency, who generated it and when, and the basis of any financial indicator (PAR, arrears aging, expected vs actual, …).
* Print/PDF, CSV and Excel need the *Export reports* permission and are recorded in the export history (DO-05). Denied report requests are logged (AU-08).
* Not available: FN-12 Trial Balance / Financial Statements (Bono keeps no general ledger). AR-08 and AR-09 list restructuring / write-off adjustments once those workflows are used. Scheduled reports and a custom report builder (specification phase 3) are not built yet.
* Monthly collection targets (BP-05) are entered on the branch and on loan-officer user records.
* New built-in roles: **Finance Manager** and **Auditor** (read-only, institution-wide).

## 2e. End of Day (one step, run by the System Administrator)

End of Day is a single process in a single place: **Administration → End of Day**. For each branch the page shows the system date and three steps: (0) the branch is ready (every till counted and reconciled by finance/managers), (1) **Back up** — a verified backup of the whole database, (2) **Run End of Day** — enabled only after the backup. Running it records the day's summary, closes and locks the business date, takes the closing backup and opens the next working day, so the **system date** (shown under every profile in the top bar) moves on. Finance and managers no longer submit or approve End of Day; the previous two-person workflow can be switched on in Settings → Cash & End of Day ("Who runs End of Day").

## 2f. Filtering and downloading reports

Every report page has a filter panel: **period presets** (today, yesterday, this week, last 7 days, this month, last month, last 30 days, this quarter, last 90 days, this year) or a custom From/To range; and, where the report has the data, **branch, loan officer, cashier, payment channel, client, loan number, status, amount range and a text search**. The active filters appear as removable chips, with "Showing X of Y rows". **Download** gives the same rows as the screen as **PDF** (paginated, with page numbers), **Excel (.xlsx)**, **CSV**, **Word** or **JSON**; every file starts with the report code, scope, filters, who generated it and when. Downloads need the *Export reports* permission and are logged (DO-05). Filters never widen what a user may see: branch and portfolio scope still apply.

## General Ledger (chart of accounts, journals, trial balance)

* **Where:** Cash > General Ledger (Finance Manager, Finance Officer, COO, Auditor can view; the Finance Manager and the System Administrator can post or change the chart).
* **Automatic posting:** loan disbursements, interest, fees and penalties charged, repayments and their reversals, waivers, customer credits, cash/bank/mobile-money movements, expenses, transfers, floats and cash variances are posted to double-entry journals when End of Day runs. "Post pending now" posts anything outstanding. Every business event is posted exactly once.
* **Basis:** interest, fees and penalties are income when received (cash basis); amounts still owed are shown as receivables against "unearned" liability accounts. Loan receivable accounts always equal the loan sub-ledger; the clearing accounts (1900, 1950, 1960) should be nil.
* **Manual journals:** for capital, opening balances and bank charges. They must balance, cannot be dated in the future, can never be edited or deleted, and are corrected by a reversing journal.
* **Reports:** Report FN-12 offers the Trial Balance, Income Statement, Balance Sheet, General Ledger and Journal Register, with period, branch and download options like any other report.
