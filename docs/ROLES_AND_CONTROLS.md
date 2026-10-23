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
| **System Administrator** | Users, branch access, role permissions, organization & security settings, backups, health. **No financial authority.** No access to client or loan records by default | system |
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
| System Administrator can never hold financial authority; technical rights stay with that role | permission boundaries (`assert_permission_boundaries`) on every role / user permission change |
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
