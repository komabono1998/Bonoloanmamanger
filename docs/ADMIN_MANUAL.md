# Administrator manual (System Administrator)

The System Administrator runs the platform: accounts, branch set-up, access, security, backups and health. The role has **no financial authority** and no access to client or loan
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
