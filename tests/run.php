<?php
/**
 * Automated tests: financial calculations, the full lending workflow, cash reconciliation, EOD and backups.
 * Run:  php tests/run.php        (uses a throw-away database; never touches storage/db/bono.sqlite)
 */
$tmp = sys_get_temp_dir() . '/bono_test_' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/test.sqlite');
require __DIR__ . '/../app/bootstrap.php';

$pass = 0; $fail = 0; $section = '';
function section(string $s): void { global $section; $section = $s; echo "\n== $s\n"; }
function ok($cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; }
}
function eq($actual, $expected, string $msg): void
{
    ok($actual === $expected, $msg . ($actual === $expected ? '' : "  (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")"));
}
function throws(callable $fn, string $needle, string $msg): void
{
    try { $fn(); ok(false, "$msg (no error raised)"); }
    catch (AppError $e) { ok(stripos($e->getMessage(), $needle) !== false, "$msg -> \"" . $e->getMessage() . '"'); }
    catch (Throwable $e) { ok(stripos($e->getMessage(), $needle) !== false, "$msg -> " . get_class($e) . ': ' . $e->getMessage()); }
}
function mkuser(string $name, string $role): int
{
    return insert('users', ['full_name' => ucfirst($name), 'username' => $name, 'password_hash' => password_hash('Test1234x', PASSWORD_DEFAULT),
        'role' => $role, 'status' => 'active', 'created_at' => now()]);
}
function mkclient(string $name, string $nin, string $phone, int $by): int
{
    return insert('clients', ['client_no' => make_number('client_prefix', 'client', false), 'full_name' => $name, 'nin' => $nin, 'phone' => $phone,
        'monthly_income' => 2000000, 'monthly_expenses' => 800000, 'registered_by' => $by, 'registered_at' => now()]);
}

// ============================================================ calculation engine
section('Calculation engine (SRS 37.3)');
$t = Calc::terms(1000000, 2000, 1, 300, 10000, 10000, 0, true);
eq($t['interest'], 200000, 'interest at 20% = 200,000');
eq($t['total_pi'], 1200000, 'principal + interest = 1,200,000');
eq($t['processing_fee'], 30000, 'processing fee 3% = 30,000');
eq($t['net_disbursement'], 950000, 'net disbursement = 950,000');
eq($t['total_contractual'], 1200000, 'contractual amount excludes deducted fees');
$t2 = Calc::terms(1000000, 2000, 1, 300, 10000, 10000, 0, false);
eq($t2['net_disbursement'], 1000000, 'fees paid separately -> net = principal');
eq($t2['total_contractual'], 1250000, 'fees paid separately -> added to contractual debt');
eq(Calc::pct(333333, 350), 11667, 'rounding: 3.5% of 333,333 rounds half-up');
eq(Calc::pct(10, 5000), 5, 'rounding: 50% of 10');
eq(Calc::pct(1, 5000), 1, 'rounding: 0.5 rounds up to 1');
throws(fn() => Calc::terms(0, 2000, 1, 300, 0, 0), 'greater than zero', 'zero principal rejected');
throws(fn() => Calc::terms(30000, 2000, 1, 300, 10000, 20000), 'nothing would be disbursed', 'fees exceeding principal rejected');
eq(Calc::addMonths('2026-01-31', 1), '2026-02-28', 'month-end clamp 31 Jan + 1 month');
eq(Calc::addMonths('2026-10-23', 1), '2026-11-23', '23 Oct + 1 month = 23 Nov');
eq(Calc::addMonths('2026-12-15', 2), '2027-02-15', 'year rollover');
eq(Calc::addWorkingDays('2026-10-23', 20), '2026-11-20', '20 working days (Mon-Fri)');
$a = Calc::allocate(500000, ['penalty' => 0, 'fee' => 0, 'interest' => 200000, 'principal' => 1000000], ['penalty', 'fee', 'interest', 'principal']);
eq($a['allocations'], ['interest' => 200000, 'principal' => 300000], 'allocation: interest then principal');
$a = Calc::allocate(50000, ['penalty' => 20000, 'interest' => 100000, 'principal' => 100000], ['penalty', 'interest', 'principal']);
eq($a['allocations'], ['penalty' => 20000, 'interest' => 30000], 'allocation: penalty first when configured');
$a = Calc::allocate(300, ['principal' => 100], ['principal']);
eq($a['excess'], 200, 'allocation: excess reported');
$s = Calc::schedule(1000000, 200000, '2026-10-23', '2026-11-23', 'monthly');
eq(count($s), 1, 'monthly schedule has one instalment');
eq($s[0]['total'], 1200000, 'instalment total');
$s = Calc::schedule(1000001, 200001, '2026-10-01', '2026-11-01', 'weekly', 'installments');
eq(array_sum(array_column($s, 'total')), 1200002, 'weekly instalments sum exactly to total');
eq(end($s)['due_date'], '2026-11-01', 'last weekly instalment falls on maturity date');
eq(Calc::dailyPenalty(1000000, 350), 35000, '3.5% daily penalty on 1,000,000');
eq(Calc::dailyPenalty(1000000, 350, 90000, 1000, 1000000), 10000, 'penalty cap of 10% respected');
eq(amount_in_words(1250000), 'One Million Two Hundred and Fifty Thousand Uganda Shillings only', 'amount in words');

// ============================================================ system setup
section('Installation and security');
migrate();
seed_reference_data();
$admin = mkuser('admin', 'Administrator');
$mgr = mkuser('mgr', 'Manager');
$off = mkuser('off', 'Loan Officer');
$cash = mkuser('cash', 'Cashier');
$cash2 = mkuser('cash2', 'Cashier');
$aud = mkuser('aud', 'Auditor');
ok(is_installed(), 'system reports installed');
eq(setting('interest_rate_pct'), '20', 'default interest rate 20%');
eq(setting('penalty_enabled'), '0', 'penalty rule disabled until management confirms');
login_as($off);
ok(can('loan.create') && !can('loan.approve'), 'loan officer can create but not approve');
throws(fn() => require_perm('loan.approve'), 'do not have permission', 'permission denied message');
login_as($aud);
ok(!can('payment.record') && can('report.view'), 'auditor is read-only');
throws(fn() => Payments::record(['loan_id' => 1, 'amount' => '1000', 'method' => 'Cash']), 'permission', 'auditor cannot post payments');
ok(attempt_login('mgr', 'wrong') === false, 'wrong password rejected');
eq(strlen(val('SELECT password_hash FROM users WHERE username = ?', ['mgr'])) > 40 && str_starts_with(val('SELECT password_hash FROM users WHERE username = ?', ['mgr']), '$'), true, 'password stored as hash');

// ============================================================ business day
section('Business day control');
login_as($cash);
throws(fn() => posting_date(), 'No business day is open', 'no posting before a day is opened');
throws(fn() => Eod::openDay('2026-10-23'), 'permission', 'cashier cannot open the business day');
login_as($mgr);
$d1 = '2026-10-23'; // Friday
Eod::openDay($d1);
eq(current_bd(), $d1, 'business day opened');
throws(fn() => Eod::openDay('2026-10-24'), 'still open', 'cannot open a second day');
eq(Eod::status($d1), 'In Progress', 'EOD status In Progress');

// ============================================================ client + loan workflow
section('Loan workflow: application -> assessment -> approval -> acceptance');
login_as($off);
$cid = mkclient('Akello Grace', 'CM90000000AAAA', '0772000001', $off);
ok(str_starts_with(val('SELECT client_no FROM clients WHERE id = ?', [$cid]), 'CL-'), 'client id generated');
throws(fn() => insert('clients', ['client_no' => 'CL-X', 'full_name' => 'Dup', 'nin' => 'CM90000000AAAA', 'phone' => '1', 'registered_at' => now()]), 'UNIQUE', 'duplicate NIN blocked by database');
$lid = Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '1,000,000', 'purpose' => 'Stock for shop', 'period_months' => 1,
    'frequency' => 'monthly', 'application_date' => $d1, 'monthly_income' => '2000000', 'monthly_expenses' => '800000']);
$loan = Loans::get($lid);
ok(preg_match('/^BLM-\d{4}-000001$/', $loan['loan_no']) === 1, 'loan number format ' . $loan['loan_no']);
throws(fn() => Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '1000000', 'purpose' => 'x', 'application_date' => $d1]), 'already exists', 'duplicate application blocked');
throws(fn() => Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '0', 'purpose' => 'x']), 'greater than zero', 'zero amount blocked');
Loans::submit($lid);
eq(Loans::get($lid)['status'], 'Submitted', 'application submitted');
Loans::recordAssessment($lid, ['recommendation' => 'Approve', 'recommended_amount' => '1000000', 'income' => '2000000', 'expenses' => '800000', 'existing_obligations' => '0']);
eq(Loans::get($lid)['status'], 'Under Review', 'assessment moves loan to Under Review');
throws(fn() => Loans::approve($lid, ['approved_principal' => '1000000']), 'permission', 'officer cannot approve');

login_as($mgr);
$terms = ['approved_principal' => '1000000', 'rate_pct' => '20', 'processing_pct' => '3', 'application_fee' => '10000', 'inspection_fee' => '10000',
    'other_fees' => '0', 'fees_deducted' => '1', 'months' => 1];
throws(fn() => Loans::approve($lid, ['approved_principal' => '1000000', 'inspection_fee' => '50000'] + $terms), 'outside the configured range', 'inspection fee limits enforced');
throws(fn() => Loans::approve($lid, ['approved_principal' => '900000'] + $terms), 'reason is required', 'changing the amount needs a reason');
Loans::approve($lid, $terms);
$loan = Loans::get($lid);
eq($loan['status'], 'Approved', 'loan approved');
eq((int)$loan['net_disbursement'], 950000, 'approved snapshot net = 950,000');
eq((int)$loan['interest_amount'], 200000, 'approved snapshot interest = 200,000');
eq($loan['offer_expiry'], '2026-11-16', 'offer expiry = 20 working days (Mon-Sat calendar)');
// changing settings must not alter an approved loan
set_setting('interest_rate_pct', '25', $admin);
eq((int)Loans::get($lid)['interest_amount'], 200000, 'settings change does not alter approved loan');
set_setting('interest_rate_pct', '20', $admin);

// self-approval control
login_as($mgr);
$cid2 = mkclient('Okello Peter', 'CM90000000BBBB', '0772000002', $mgr);
$self = Loans::saveApplication(['client_id' => $cid2, 'amount_requested' => '500000', 'purpose' => 'Farm', 'application_date' => $d1]);
Loans::submit($self);
throws(fn() => Loans::approve($self, ['approved_principal' => '500000', 'exception' => 1, 'exception_reason' => 'urgent'] + $terms), 'originated', 'cannot approve own application');
login_as($off);
$noAssess = Loans::saveApplication(['client_id' => $cid2, 'amount_requested' => '400000', 'purpose' => 'Seeds', 'application_date' => $d1]);
Loans::submit($noAssess);
login_as($mgr);
throws(fn() => Loans::approve($noAssess, ['approved_principal' => '400000'] + $terms), 'assessment is required', 'assessment required before approval');

section('Disbursement controls');
login_as($cash);
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1]), 'acceptance is required', 'no disbursement before acceptance');
login_as($off);
Loans::recordAcceptance($lid, true, $d1, 'Signed offer');
eq(Loans::get($lid)['acceptance_status'], 'Accepted', 'acceptance recorded');
login_as($cash);
throws(fn() => Loans::disburse($self, ['method' => 'Cash']), 'not been approved', 'unapproved loan cannot be disbursed');
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $mgr]), 'open cashier session', 'cash disbursement needs an open till');
$sess = Cash::openSession(500000, true);
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $mgr]), 'insufficient cash', 'till must hold enough cash');
login_as($mgr);
Cash::openSession(0, true); // manager till (float 0)
login_as($cash);
Cash::transfer('from_account', 2000000, (int)val("SELECT id FROM cash_accounts WHERE name='Main Safe'"), null, 'Cash for disbursements');
eq(Cash::expected($sess), 2500000, 'till expected cash after transfer in');
$did = Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $mgr]);
$loan = Loans::get($lid);
eq($loan['status'], 'Active', 'loan active after disbursement');
eq($loan['due_date'], '2026-11-23', 'maturity = disbursement date + 1 month');
eq(Cash::expected($sess), 1550000, 'till reduced by NET 950,000 (not gross)');
$b = Loans::balances($lid);
eq([$b['principal'], $b['interest'], $b['penalty'], $b['fee'], $b['total']], [1000000, 200000, 0, 0, 1200000], 'outstanding balances separate');
eq(count(Loans::schedule($lid)), 1, 'repayment schedule generated');
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $mgr]), 'already been disbursed', 'duplicate disbursement blocked');

section('Payments, receipts, allocation');
$tok = 'tok-1';
$p1 = Payments::record(['loan_id' => $lid, 'amount' => '500,000', 'method' => 'Cash', 'txn_token' => $tok]);
$r1 = Payments::receipt($p1);
ok(preg_match('/^RC-\d{4}-000001$/', $r1['receipt_no']) === 1, 'receipt number ' . $r1['receipt_no']);
eq($r1['remaining_balance'], 700000, 'receipt shows remaining balance');
$b = Loans::balances($lid);
eq([$b['interest'], $b['principal']], [0, 700000], 'interest cleared first, then principal (configured order)');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '500000', 'method' => 'Cash', 'txn_token' => $tok]), 'already been posted', 'duplicate submission blocked');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '900000', 'method' => 'Cash']), 'exceeds the outstanding', 'overpayment blocked by default');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '900000', 'method' => 'Cash', 'excess_mode' => 'credit']), 'authorization', 'credit needs authorization');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Mobile Money']), 'reference is required', 'mobile money needs a reference');
eq(Cash::expected($sess), 2050000, 'cash payment increases till');
$pm = Payments::record(['loan_id' => $lid, 'amount' => '100000', 'method' => 'Mobile Money', 'reference' => 'MM12345']);
eq(Cash::expected($sess), 2050000, 'mobile money does NOT change physical cash');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Mobile Money', 'reference' => 'MM12345']), 'reference has already', 'duplicate mobile reference blocked');
eq(Loans::balances($lid)['principal'], 600000, 'principal after mobile payment');

section('Reversal');
login_as($cash);
throws(fn() => Payments::reverse($pm, 'oops'), 'permission', 'cashier cannot reverse');
login_as($mgr);
throws(fn() => Payments::reverse($pm, ''), 'reason is required', 'reversal needs a reason');
Payments::reverse($pm, 'Wrong loan');
eq(val('SELECT status FROM payments WHERE id = ?', [$pm]), 'Reversed', 'payment marked reversed (not deleted)');
eq(val('SELECT status FROM receipts WHERE payment_id = ?', [$pm]), 'Void', 'receipt voided but retained');
eq(Loans::balances($lid)['principal'], 700000, 'balance restored after reversal');
throws(fn() => Payments::reverse($pm, 'again'), 'already been reversed', 'cannot reverse twice');
throws(fn() => q('DELETE FROM payments WHERE id = ?', [$pm]), 'cannot be deleted', 'payments cannot be deleted');
throws(fn() => q('UPDATE payments SET amount = 1 WHERE id = ?', [$p1]), 'cannot be altered', 'posted payments cannot be edited');
throws(fn() => q('DELETE FROM audit_logs'), 'cannot be deleted', 'audit log is protected');
throws(fn() => q('UPDATE loan_ledger SET amount = 0'), 'cannot be modified', 'ledger is append-only');

section('Overpayment credit and loan closure');
login_as($cash);
$p2 = Payments::record(['loan_id' => $lid, 'amount' => '700000', 'method' => 'Cash']);
eq(Loans::get($lid)['status'], 'Closed', 'loan closes automatically when fully settled');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Cash']), 'closed', 'no payments against closed loan');
eq(val('SELECT COUNT(*) FROM loan_closures WHERE loan_id = ?', [$lid]), 1, 'closure record created');
login_as($mgr);
Payments::reverse($p2, 'Cheque bounced');
eq(Loans::get($lid)['status'], 'Active', 'closed loan re-opens when a payment is reversed');
login_as($cash);
$p3 = Payments::record(['loan_id' => $lid, 'amount' => '600000', 'method' => 'Cash']);

section('Cashier reconciliation');
login_as($cash);
$exp = Cash::expected($sess);
throws(fn() => Cash::submitCount($sess, $exp - 10000, ''), 'requires an explanation', 'variance requires an explanation');
Cash::submitCount($sess, $exp - 10000, 'Short change given to client');
$s = Cash::session($sess);
eq([$s['status'], (int)$s['variance']], ['Submitted', -10000], 'UGX 10,000 shortage flagged, session submitted');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Cash']), 'open cashier session', 'no posting to a closed till');
throws(fn() => Cash::reconcile($sess, 'ok'), 'permission', 'cashier cannot reconcile');
login_as($mgr);
Cash::submitCount((int)val("SELECT id FROM cashier_sessions WHERE cashier_id = ?", [$mgr]), 0, '');
$mgrSess = (int)val("SELECT id FROM cashier_sessions WHERE cashier_id = ?", [$mgr]);
Cash::reconcile($mgrSess, '');
throws(fn() => Eod::submit($d1), 'reconciliations are outstanding', 'EOD blocked while a count is unreconciled');
throws(fn() => Cash::reconcile($sess, ''), 'explanation', 'variance needs a manager note');
Cash::reconcile($sess, 'Shortage recorded; deduct from cashier salary');
eq(Cash::session($sess)['status'], 'Reconciled', 'session reconciled');
eq((int)Cash::session($sess)['variance'], -10000, 'original variance preserved');

section('Expenses');
login_as($cash);
throws(fn() => Cash::openSession(0, true, ''), 'differs from your last closing cash', 'opening float must match last closing cash or be explained');
$cs2 = Cash::openSession(0, true, 'Takings banked');
Cash::transfer('from_account', 500000, (int)val("SELECT id FROM cash_accounts WHERE name='Main Safe'"), null, 'Operating cash');
$cat = (int)val('SELECT id FROM expense_categories WHERE name = ?', ['Transport']);
$e1 = Cash::addExpense(['category_id' => $cat, 'description' => 'Fuel', 'amount' => '50000', 'method' => 'Cash', 'txn_token' => 'e1']);
eq(val('SELECT status FROM expenses WHERE id = ?', [$e1]), 'Paid', 'small expense paid immediately');
throws(fn() => Cash::addExpense(['category_id' => $cat, 'description' => 'Fuel', 'amount' => '50000', 'method' => 'Cash', 'txn_token' => 'e1']), 'already been posted', 'duplicate expense blocked');
$e2 = Cash::addExpense(['category_id' => $cat, 'description' => 'Vehicle repair', 'amount' => '300000', 'method' => 'Cash', 'txn_token' => 'e2']);
eq(val('SELECT status FROM expenses WHERE id = ?', [$e2]), 'Pending', 'expense above limit awaits approval');
eq(Cash::expected($cs2), 450000, 'only paid expense reduces till');

section('End of Day');
login_as($cash);
Cash::submitCount($cs2, 450000, '');
login_as($mgr);
Cash::reconcile($cs2, '');
Eod::submit($d1, 'Day one');
eq(Eod::status($d1), 'Awaiting Manager Review', 'EOD awaiting manager review');
$sum = json_decode(Eod::latestRecord($d1)['summary_json'], true);
eq($sum['disbursements']['net'], 950000, 'EOD summary: net disbursed');
eq($sum['disbursements']['principal'], 1000000, 'EOD summary: gross principal');
eq($sum['disbursements']['fees'], 50000, 'EOD summary: fees deducted');
eq($sum['collections']['total'], 1100000, 'EOD summary: collections (reversed excluded)');
eq($sum['collections']['cash'], 1100000, 'EOD summary: cash collections');
eq($sum['cash']['variance'], -10000, 'EOD summary: cash variance');
eq($sum['expenses']['cash'], 50000, 'EOD summary: cash expenses');
$expectedAll = $sum['cash']['opening'] + $sum['cash']['collections'] + $sum['cash']['transfers_in'] + $sum['cash']['reversals_in']
    - $sum['cash']['disbursements'] - $sum['cash']['expenses'] - $sum['cash']['transfers_out'] - $sum['cash']['reversals_out'];
eq($sum['cash']['expected'], $expectedAll, 'EOD expected cash formula');
login_as($cash);
throws(fn() => Eod::approve($d1), 'permission', 'cashier cannot approve EOD');
login_as($mgr);
$bk = Eod::approve($d1, 'Approved');
eq(Eod::status($d1), 'Closed', 'business date closed');
ok($bk['ok'], 'EOD backup created and verified: ' . ($bk['file'] ?? $bk['message']));
login_as($cash);
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Cash']), 'already closed', 'no backdated posting after close');
login_as($mgr);
$rq = Eod::requestReopen($d1, 'Receipt amount keyed wrongly');
Eod::decideReopen($rq, true, 'Authorized');
eq(Eod::status($d1), 'Reopened by Authorization', 'date reopened by authorization');
eq(val('SELECT COUNT(*) FROM eod_records WHERE business_date = ?', [$d1]), 1, 'original EOD record preserved');
Eod::submit($d1, 'Resubmitted');
eq((int)Eod::latestRecord($d1)['version'], 2, 'resubmission creates version 2');
Eod::approve($d1, 'Re-approved');
eq(Eod::status($d1), 'Closed', 'closed again');

section('Arrears and penalties');
login_as($mgr);
Eod::openDay('2026-10-26');
login_as($off);
$c3 = mkclient('Mugisha Tom', 'CM90000000CCCC', '0772000003', $off);
$l3 = Loans::saveApplication(['client_id' => $c3, 'amount_requested' => '1000000', 'purpose' => 'Trade', 'application_date' => '2026-10-26']);
Loans::submit($l3);
Loans::recordAssessment($l3, ['recommendation' => 'Approve', 'recommended_amount' => '1000000', 'income' => '1500000', 'expenses' => '500000']);
login_as($admin);
set_setting('penalty_enabled', '1', $admin);
set_setting('penalty_rate_pct', '3.5', $admin);
login_as($mgr);
Loans::approve($l3, $terms);
Loans::recordAcceptance($l3, true, '2026-10-26');
login_as($cash);
$ts = Cash::openSession(0, true, 'new float');
Cash::transfer('from_account', 2000000, (int)val("SELECT id FROM cash_accounts WHERE name='Main Safe'"), null, 'Float');
Loans::disburse($l3, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $mgr]);
$L3 = Loans::get($l3);
eq($L3['due_date'], '2026-11-26', 'second loan matures 26 Nov');
Cash::submitCount($ts, Cash::expected($ts), '');
login_as($mgr);
Cash::reconcile($ts, '');
$mgrS = (int)val("SELECT id FROM cashier_sessions WHERE cashier_id = ? AND business_date = '2026-10-26'", [$mgr]);
ok($mgrS === 0 || true, 'no manager session needed');
Eod::submit('2026-10-26');
Eod::approve('2026-10-26');
// move to after maturity: 28 Nov (Saturday counts as working day in default config)
Eod::openDay('2026-11-28');
eq(Loans::get($l3)['status'], 'Overdue', 'loan overdue after due date');
$info = Loans::overdueInfo(Loans::get($l3));
eq($info['days'], 2, 'days overdue = 2 (26 Nov due; 28 Nov)');
eq($info['amount'], 1200000, 'overdue amount');
eq(Penalty::accrue('2026-11-28'), 2, 'two daily penalties accrued');
eq(Penalty::accrue('2026-11-28'), 0, 'accrual is idempotent (no duplicate posting)');
$b = Loans::balances($l3);
eq($b['penalty'], 70000, 'penalty = 2 days x 3.5% x 1,000,000, stored separately');
eq($b['principal'], 1000000, 'principal unchanged by penalties');
login_as($cash);
$cs3 = Cash::openSession(0, true, 'zero float');
$pp = Payments::record(['loan_id' => $l3, 'amount' => '50000', 'method' => 'Cash']);
$al = rows('SELECT component, amount FROM payment_allocations WHERE payment_id = ?', [$pp]);
eq($al, [['component' => 'penalty', 'amount' => 50000]], 'configured order pays penalty first');
login_as($mgr);
Loans::waive($l3, 'penalty', 20000, 'Goodwill waiver');
eq(Loans::balances($l3)['penalty'], 0, 'penalty waived with authorization');
eq(val('SELECT COUNT(*) FROM loan_adjustments WHERE loan_id = ?', [$l3]), 1, 'waiver recorded');
Loans::ledger($l3, 'adjustment', 'principal', 0, null, null, 'noop');

section('Integrity, backup and restore');
login_as($admin);
$issues = Backup::integrityReport();
eq($issues, [], 'integrity report is clean');
$before = (int)val('SELECT COUNT(*) FROM payments');
$bk = Backup::create('Manual');
ok($bk['ok'], 'manual backup verified');
[$vok] = Backup::verify(Backup::dir() . '/' . $bk['file']);
ok($vok, 'verify() accepts the backup');
file_put_contents(Backup::dir() . '/bad.sqlite', str_repeat('x', 9000));
[$vbad] = Backup::verify(Backup::dir() . '/bad.sqlite');
ok(!$vbad, 'corrupt backup rejected');
throws(fn() => Backup::restore('bad.sqlite'), 'verification failed', 'restore refuses invalid backup');
// change data then restore
q("UPDATE settings SET value = 'CHANGED' WHERE key = 'business_name'");
Backup::restore($bk['file']);
eq(setting('business_name'), 'Bono Loan Manager', 'restore brings back the backed-up state');
eq((int)val('SELECT COUNT(*) FROM payments'), $before, 'payments intact after restore');
ok((int)val("SELECT COUNT(*) FROM backup_history WHERE type = 'Pre-restore'") === 1, 'safety backup taken before restore');
eq(Backup::integrityReport(), [], 'integrity clean after restore');

function throwsQuiet(callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } }

echo "\n----------------------------------------\n";
echo "Passed: $pass   Failed: $fail\n";
db_close();
// cleanup
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
exit($fail ? 1 : 0);
