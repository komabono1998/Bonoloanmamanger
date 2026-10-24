# User acceptance testing guide

## 1. Set up before the first test day (System Administrator)

1. **Institution profile** (Settings > Institution): name, address, phone, e-mail, logo, brand colour. Reports, receipts and downloads use it.
2. **Branches and users** (Administration > Branches, Users & access). The loan process runs between the **Loan Officer** and the **Branch Manager** (Administration > Loan Process Roles). Give every person their own login; passwords are temporary until changed.
3. **Loan policy** (Settings): interest rate and method, fees, period and frequency, penalty rule (off until management confirms), approval limits (not invented by the system), required KYC documents, number of guarantors, collateral policy (Settings > Loan workflow).
4. **Opening balances**: Cashier & Till > Safe, bank & mobile accounts: record each safe, bank and mobile-money balance as an "Opening balance". A safe cannot be drawn from until it holds money.
5. **Start the business day** for every branch (End of Day page > Start business day).
6. **Backups**: take and verify one (Backup & Recovery), and set the backup folder on a separate disk.
7. **Locations**: the address drop-downs ship with national lists; add missing units on Administration > Uganda Locations.

## 2. Scenarios to walk through

| # | Scenario | Who | Expected |
|---|----------|-----|----------|
| 1 | Register a client (profession and address from the drop-downs), upload the national ID | Loan Officer | Client saved; KYC "Pending" |
| 2 | Verify the ID and the client KYC profile | Branch Manager | KYC "Verified"; the officer cannot verify |
| 3 | New application: search and attach the existing client, save as draft | Loan Officer | Draft saved; Guarantors tab opens |
| 4 | Add a guarantor (consent), register security, upload documents, submit | Loan Officer | Submit is refused until every required item is verified; the tracker shows what is missing |
| 5 | Review: check Documents, Guarantors and Security tabs, approve (or return to a stage) | Branch Manager | Terms locked; officer cannot approve own application |
| 6 | Record the client's acceptance, open a till, disburse | Loan Officer / Branch Manager | Disbursement only after acceptance; voucher; loan Active with schedule |
| 7 | Receive repayments (cash, mobile, bank), reverse one with approval | Cashier / Branch Manager | Receipts; balances and ledger agree |
| 8 | Close and count the till | Cashier, Branch Manager | Variance needs a reason |
| 9 | End of Day for all offices (one click, confirm, progress bar) | System Administrator | All offices close together; system date moves everywhere; completion notice |
| 10 | Reset the End-of-Day position after a deliberate mistake | System Administrator | Last day reopens (refused when the next day has transactions) |
| 11 | Reports: trial balance, income statement, balance sheet, loan and finance reports; download PDF, Excel, CSV, Word | Finance / COO | Figures agree with the screens; file named after the report |
| 12 | Switch the loan process to "Separated duties" and back | System Administrator | Menus and permissions follow immediately |

## 3. What to check on every screen
Dates (system date under the profile and in the top bar must be the same after End of Day), amounts in UGX with thousands separators, the audit trail entry for each action, and that a user only sees their own branch.

## 4. Known limits to keep in mind
* Payment gateways are not connected: mobile-money and bank payments are recorded by reference, not confirmed by an external service.
* The national location lists predate the newest districts and cities; correct them by CSV upload or by typing.
* There is no separate "Credit Reviewer" role; verification is done with the Branch Manager (or any role given the verify permission).
* Large-volume performance has not been load-tested; try realistic volumes in the test environment first.
* Run the system over HTTPS with a limited database account in the test environment (see docs/CLOUD_DEPLOYMENT.md).
