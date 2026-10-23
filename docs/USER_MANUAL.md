# User manual — daily procedures by role

Sign in with your own account (and your authenticator code if your role requires it). The top bar shows your **branch** (use the selector if you have several), the branch's **business date** and its **EOD status**.
You only ever see the branches assigned to you. **My Approvals** lists everything waiting for your decision.

## The day in one picture (per branch)

```
Finance Officer: Start business day
Cashier: Open till → receive payments / release disbursements / expenses → Count & close till
Finance Officer (or Branch Manager if there is a variance): Reconcile each till
Finance Officer: Submit EOD
Branch Manager: Approve & lock (or return)        ← COO instead, if a cash variance is above the limit
```

## Loan Officer
1. **Clients → Register client** (name, phone, NIN). Duplicate NINs are blocked; shared phones need confirmation.
2. **Loan Applications → New application**; add guarantors and security from the loan file; **Credit Assessment** (income, expenses, history, recommended amount).
3. After approval: print the **Offer letter**, get it signed, **record the client's acceptance** (or refusal).
4. **Arrears**: record contact attempts and promises to pay.
You cannot approve loans, take payments or change approved terms.

## Finance Officer
* **End of Day → Start business day**, review the checklist, **Submit End-of-Day**. You prepare; you can never approve your own submission.
* **Cashier & Till → All sessions**: reconcile counts. A count with a **variance** needs a manager with approval authority (Branch Manager, or the COO above the configured limit).
* **Payments → Payment history → Request reversal** (reason required). A different, authorized person approves it.
* **Expenses & Cashbook**: record expenses (above the routine limit they wait for approval), review the cashbook; **Reports** for collections, disbursements, expenses and reconciliation.
* If you were also given collection rights, those duties are separate: you cannot reconcile your own till.

## Cashier (optional teller profile)
1. **Cashier & Till → Open your till**: count the float and confirm.
2. **Payments**: find the loan (loan no., client ID, name or phone) → amount, method, reference → *Post payment*. Receipts print automatically (reprints are marked COPY).
3. **Loan Disbursement**: choose an approved & accepted loan, tick the document / acknowledgement boxes, select the **authorizing manager**, release the **net** amount.
4. **Count & close till**; closing your till does not close the day.

## Branch Manager
* **Loan Approval**: review the application and assessment; the live calculator shows interest, fees and net amount. You approve **within your configured limit**; above it use **Escalate to COO** (the system also routes it). You can return a loan for correction or reject it with a reason. You cannot approve your own application.
* **Disbursement authorization**: you are the named authorizing manager on the voucher.
* **My Approvals**: reversal requests, expenses, till reconciliations, End-of-Day reviews, reopen requests within your authority.
* **End of Day → Approve & lock**: the preparer cannot approve; if the branch cash variance exceeds the limit the item is escalated to the COO.
* **Settings → My branch**: working days / closing time for your branch; **Backup → Request a backup**.

## COO
* **Dashboard** (institution-wide, with branch comparison and EOD status), **Branch Compliance** (which branches are behind, unreconciled counts, escalations), **Reports → Branch comparison**.
* **My Approvals**: everything above Branch Manager limits — escalated loans, large expenses, escalated End-of-Day, reversals above the limit, **recovery (restore) requests**.
* **Settings & limits**: lending terms, penalties, payment rules and the **approval limits** (the institution decides the amounts).
* Use the branch selector to act in a branch (approvals are recorded in that branch).

## Everyone
* **My Account & Security**: change password, set up / manage MFA, see your recent sign-ins.
* You are signed out after the idle timeout. If a transaction fails (e.g. connection lost) the system tells you — a transaction is only saved when you see the confirmation and the receipt / reference.
