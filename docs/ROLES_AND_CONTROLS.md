# Roles, access control and separation of duties

Implements *Bono Loan Manager — Cloud-Based Loan Management System, Architecture, User Roles and Access Control Specification v3.0*.
All permissions are enforced **on the server** for every action; hiding a menu item is never the control. Every record also carries a **branch**; users only see records of the branches
assigned to them (the COO sees all). Cross-branch access by guessing an ID is refused (`You do not have access to records of that branch.`).

## The five principal roles (+ one optional profile)

| Role | Purpose | Scope |
|---|---|---|
| **Loan Officer** | Register clients, applications, guarantors, security, credit assessment, follow-up notes | assigned branch(es) |
| **Finance Officer** | Opens/prepares the business day and EOD, reconciles till counts, reviews collections, cashbook & expenses, requests payment reversals, financial reports | assigned branch(es) |
| **Branch Manager** | Approves loans within limit, authorizes disbursement, approves reversals / expenses / EOD within limit, reconciles variances, branch reports | assigned branch(es) |
| **COO** | Institution-wide oversight; approves everything above Branch Manager limits (escalations), approves policy, sets approval limits, authorizes system recovery | all branches |
| **System Administrator** | Every user right, all reports and all configuration (users, branches, settings, backups, health, approvals, finance). Actions are audited.| system |
| *Cashier* (optional) | Teller: receives payments, releases approved disbursements, runs a till. The spec lets a Finance Officer be granted collection rights instead (add `payment.record` + `till.use` to that user) | assigned branch(es) |

## Default access matrix (summary — full matrix: Users & access → Access matrix)

| Module | Loan Officer | Finance Officer | Branch Manager | COO | System Admin |
|---|---|---|---|---|---|
| Dashboard | Limited | Financial | Branch | Institution-wide (+ branch comparison) | System health only |
| Clients / guarantors | Full (branch) | View | Full (branch) | View all | none |
| Loan applications | Full | View | Review / approve | Escalated review | none |
| Credit assessment | Full | View | Review | Escalated review | none |
| Loan approval | none | none | Within limit | Escalations | none |
| Disbursement | View | Financial review | Authorize within limit | Escalated authorization | none |
| Payments / receipts | View | View, **request** reversal | View, **approve** reversal | Approve reversal / oversight | none |
| Cashbook / expenses | none | Record & review | Approve within limit | Approve above limit | none |
| Cashier sessions | none | Reconcile | Reconcile / approve variance | Approve large variance | none |
| End of Day | View | **Prepare & submit** | **Approve** (or return) | Approve escalated / reopen | none |
| Arrears, penalties, closure | Assigned follow-up | View | Full, within limit | Escalated | none |
| Reports | Limited | Full (branch) | Branch | Institution-wide | none |
| Audit trail | own activity | financial scope | branch scope | institution-wide | technical & security scope |
| Users, roles, branches | none | none | none | none | **Full, within policy** |
| Settings | none | none | own-branch business-day settings | policy: lending, penalties, payments, EOD, **approval limits** | organization & security settings |
| Backup & restore | none | none | request a backup | **authorize** recovery | execute & verify |

## Mandatory separation-of-duties controls (enforced in code, tested)

| Control | How it is enforced |
|---|---|
| Loan Officer cannot approve their own application | `Loans::approve` refuses when approver = originating officer |
| Credit assessment required before approval | exception only with an authorized reason |
| Cashier cannot approve their own reconciliation | `Cash::reconcile` refuses when reconciler = cashier |
| Till variance needs a reviewer with authority | `Approvals::assert('eod_variance', …)`; above the limit only the COO |
| EOD preparer cannot be its approver; an approver who acted as cashier that day cannot approve | `Eod::approve` |
| EOD with a variance above the limit → **escalated to the COO** | status *Escalated to COO* |
| Payment reversal = request (maker) + independent approval (checker); requester can never approve | `Payments::requestReversal / approveReversal` (policy switch: *Settings → Payments*) |
| Expense: approver ≠ entering person; above the Branch Manager limit → COO | `Cash::approveExpense` |
| EOD reopening: requester ≠ authorizer; authority Branch Manager or COO by policy | `Eod::decideReopen` |
| Disbursement authorized by a manager with authority for the amount; not by the originating officer | `Loans::disburse` |
| System recovery: administrator requests, **COO authorizes**, administrator executes | `Backup::requestRestore / authorizeRestore / restore` |
| Technical rights (users, settings, backups, security) are reserved for the System Administrator role; the administrator holds every right | permission boundaries (`assert_permission_boundaries`), `user_permissions()` |
| Closed EOD cannot be modified without the reopening process; history is corrected by reversal/adjustment only | `posting_date()` + database triggers |
| Audit log, ledger, cash movements, login history cannot be edited or deleted (even by administrators) | database triggers (SQLite & PostgreSQL) |

## Approval limits and escalation

* Limits are **configured by the institution** (COO: *Settings & limits → Approval limits*), per category — loan, disbursement, expense, waiver, payment reversal, End-of-Day variance —
  for the whole institution and optionally per branch. **The system ships with none**: until a limit is set, nothing is auto-escalated and administrators see a warning.
* Above the Branch Manager limit the item appears only in the **COO's** *My Approvals*; a Branch Manager can also *Escalate* a loan voluntarily. Every decision records approver, level, time, reason and approval reference.

## Multi-factor authentication and sessions

TOTP (RFC 6238) authenticator apps. Mandatory by default for System Administrator, COO and Branch Manager (Settings → Security: choose roles; System Administrator is always included).
Seeds are encrypted at rest; one-time recovery codes; an administrator can reset a user's MFA (audited). Accounts lock for 5 minutes after 5 failures; repeated failures from one IP are throttled;
sessions expire after inactivity; optional password expiry; every sign-in is logged (IP, device, result) and sign-ins from a new address are flagged on *Security monitoring*.


## Loan Officer role template (RBAC specification)

**Authorized modules (menu):** Dashboard, Client Management (Clients), Loan Applications (Loan Applications, Credit Assessment), Loan Accounts (Repayment Schedules, Loan Portfolio), Guarantors & Collateral (Guarantors, Securities), Arrears & Recovery (Arrears & Defaulters), Reports & Analytics (Reports). No payments, receipts, till, expenses, End of Day, users, settings, backup or audit modules.

**No financial authority:** approval limit UGX 0 (cannot approve, authorize disbursement, override fees, waive penalties, restructure, write off, reverse transactions, release security, approve/reopen EOD, or manage users/roles/settings). The officer prepares, assesses and *recommends*; a Branch Manager/COO approves; a separate authorized user disburses.

**Record scope:** branch-restricted by default (derived from the account on the server, never from the browser). Portfolio scope is configurable with the permission `portfolio.all`: remove it (role or individual user) and the officer sees only the clients they registered and the loans assigned to them, in every list, search, dashboard, loan/client page and document download.

**Permission codes** (stable codes; spec name -> implemented code):

| Specification | Implemented |
|---|---|
| dashboard.view | dashboard.view |
| clients.view / create / update | client.view / client.manage (create + edit) |
| loan_applications.create / update_draft / submit | loan.create |
| loan_applications.recommend | loan.assess |
| loan_accounts.view | loan.view |
| guarantors.view / create | client.view / guarantor.manage |
| collateral.update | security.manage (release needs security.release) |
| arrears.view / recovery_notes.create | portfolio.view / arrears.manage |
| reports.view_branch / reports.export | report.view (per-report control per user) / report.export |
| (portfolio scope) | portfolio.all |

**Server enforcement:** every page and service call checks the permission, branch scope and (where applicable) portfolio scope; denied requests are refused (403 for signed-in users, sign-in redirect when unauthenticated); deactivation and permission revocation apply on the next request. Automated acceptance tests for each item of the specification are in `tests/run.php` ("Loan Officer RBAC acceptance").
