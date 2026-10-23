# Bono Loan Manager — cloud edition (v3)

A secure, **cloud-based, multi-branch** loan management system for a microfinance institution, built from the
*System Requirements Specification v2.0* (loan features) and the *Cloud-Based Loan Management System — Architecture, User Roles and Access Control Specification v3.0*.

* **Centralized data** for all branches in one PostgreSQL database; each record belongs to a branch and users only see their branches.
* **Five principal roles** (Loan Officer, Finance Officer, Branch Manager, COO, System Administrator) with server-side permission checks, separation of duties and configurable **approval limits with escalation**.
* **Multi-factor authentication**, HTTPS, append-only audit and ledger, verified backups, controlled restores, health checks and a ready-to-deploy container stack.
* Everything from the offline edition is kept: client / guarantor / loan lifecycle, flat-interest engine, receipts, cashier tills, End-of-Day, arrears, penalties, 41 reports, import / export.

> **Stack decision.** The spec suggests e.g. React + Django/FastAPI + PostgreSQL but allows an equivalent stack. This project evolves the existing, fully-tested **PHP 8.2** application
> (server-rendered, no external assets) and adds a **PostgreSQL** mode. The same code also runs on **SQLite** for local evaluation. See `docs/ARCHITECTURE.md`.

## Quick start (local evaluation, XAMPP, SQLite)

1. Start **Apache** in XAMPP, open <http://localhost/Bonoloanmamanger/> → **Setup** (institution, first branch, System Administrator).
2. Sign in → *Users & access*: create staff and tick their branches → *Settings & limits* → the Finance Officer starts the business day (*End of Day*).
3. Optional demo data (two branches, every role, loans in all stages): `php tools/seed_demo.php` — **evaluation only**.

## Production (cloud)

```bash
cp .env.example .env     # DOMAIN, APP_KEY, DB_PASSWORD (or managed-database settings)
docker compose up -d --build     # HTTPS proxy + app + PostgreSQL
```
Full guide, security checklist, backups / disaster recovery and monitoring: **[docs/CLOUD_DEPLOYMENT.md](docs/CLOUD_DEPLOYMENT.md)**.

## Tests

```bash
php tests/run.php                                           # SQLite   — 172 checks
BONO_TEST_DRIVER=pgsql DB_HOST=… DB_USER=… PG_BIN=…/bin php -d extension=pdo_pgsql tests/run.php    # PostgreSQL — same 172 checks
```
They cover calculations, the whole loan workflow, branch isolation (every list page and report is rendered as a user of another branch and must show nothing), roles and permission boundaries,
approval limits and escalation, maker-checker reversals, till / EOD separation of duties, penalties, TOTP (RFC 6238 vectors), AWS Signature V4 (documentation vector), controlled
backup / restore (SQLite `VACUUM INTO`, PostgreSQL `pg_dump`/`pg_restore`), the append-only triggers, shared sessions and the health probe.

## Where things are

| Need | Page / file |
|---|---|
| Who may do what, separation of duties, limits, MFA | **[docs/ROLES_AND_CONTROLS.md](docs/ROLES_AND_CONTROLS.md)** · *Users & access → Access matrix* |
| Hosting, TLS, backups, DR drill, monitoring | [docs/CLOUD_DEPLOYMENT.md](docs/CLOUD_DEPLOYMENT.md) |
| Daily procedures by role | [docs/USER_MANUAL.md](docs/USER_MANUAL.md) |
| Administration (branches, users, settings, recovery) | [docs/ADMIN_MANUAL.md](docs/ADMIN_MANUAL.md) |
| Architecture, ledger model, state machines | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Tables & relationships | [docs/DATABASE.md](docs/DATABASE.md) |

## What's new in v3 (vs. the offline edition)

| Area | Change |
|---|---|
| Deployment | Cloud web app: HTTPS (HSTS, secure cookies, CSP), `.env` / secret-manager configuration, Docker image + Compose (Caddy TLS, PostgreSQL), `/health` probe, stderr logging, shared DB sessions for several app servers |
| Database | PostgreSQL (managed) **and** SQLite from one portable schema; append-only triggers generated for both; concurrent-safe numbering and duplicate-posting protection |
| Branches | Branch master data, per-branch cash accounts, **business date and EOD per branch** (a branch closing never blocks another), branch switcher, branch-scoped lists / reports / downloads / audit, institution-wide COO view |
| Roles | Loan Officer, Finance Officer, Branch Manager, COO, System Administrator (+ optional Cashier profile); the System Administrator holds every right, technical rights are reserved to that role |
| Approvals | Configurable limits per category and branch, automatic routing to the COO, *My Approvals* inbox, voluntary escalation, approver level recorded |
| Separation of duties | No self-approval anywhere; EOD preparer ≠ approver; reversals are request + independent approval; recovery needs COO authorization; reopening by policy |
| Security | TOTP MFA (mandatory roles configurable) with encrypted seeds and recovery codes; login history, lock-out, IP throttling, new-IP detection, password policy / expiry; security monitoring page |
| Files | Private storage abstraction: local volume or S3-compatible bucket (SigV4); served only after permission + branch checks |
| Operations | System health page, recovery objectives (RTO / RPO) recorded, verified `pg_dump` backups + documents archive, controlled restore with safety backup |

## Decisions management still has to confirm

All are **configurable** — nothing is hard-coded.

| Decision | Default |
|---|---|
| Approval limits (loan, disbursement, expense, waiver, reversal, EOD variance) | **None configured** — the institution sets them (COO); the system warns until it does |
| 3.5 % daily penalty — rate, basis, compounding, cap | **Off**; configure in *Settings → Penalties* |
| Maturity basis, repayment frequency meaning, allocation order, fee deduction | disbursement + 1 month; collection method only; penalty→fees→interest→principal; fees deducted |
| Reopening a closed day: Branch Manager or COO | Branch Manager |
| Backup frequency, RTO / RPO, retention | every 24 h and at each EOD; **RTO / RPO not recorded** until approved; 60 backups |
| MFA roles | System Administrator, COO, Branch Manager |
| Reversals need independent approval | Yes |

## Known limitations

* The Docker / cloud-provider steps were **not executed** here (no Docker or cloud account); PostgreSQL itself, `pg_dump` / `pg_restore`, MFA, branch isolation and all workflows were exercised on a real PostgreSQL 16 server and on SQLite.
* PDF = browser *Print → Save as PDF*; Excel = CSV and Excel XML (no zip extension is assumed). The S3 storage driver is verified against the AWS signing test vector but not against a live bucket.
* The authenticator setup shows the secret key and an `otpauth://` link (no QR image is generated, to avoid bundling a library).
* Offline posting is deliberately not supported: if connectivity is lost the user sees an error and nothing is reported as saved.
* Mobile-money / bank / SMS integrations are future optional modules (the services layer is ready for them).
* Legal enforcement of guarantees, penalties and collateral follows the signed agreements and Ugandan law — outside the software.

## Automated checks

* `php tests/run.php` — unit and rule tests (SQLite; set `BONO_TEST_DRIVER=pgsql` and the `DB_*` variables for PostgreSQL).
* `php tests/crawl.php <base-url> accounts.json [--post=1]` — signs in as each account and follows every menu, tab and link (and, with `--post`, sends every form with its default values), reporting pages that error or that the interface offers but the user is not allowed to open.
* `php tests/flows.php <base-url>` — end-to-end flows over HTTP: an administrator creates new profiles, the new people sign in (forced password change) and carry out loan, cash, End-of-Day, reporting and administration work through the real screens.

Run the last two only against a throw-away database (for example `php -S 127.0.0.1:8097` with `BONO_DB` pointing to a copy seeded with `php tools/seed_demo.php`).
