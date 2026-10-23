<?php
/**
 * Automated tests (SQLite by default, PostgreSQL when BONO_TEST_DRIVER=pgsql):
 *   php tests/run.php
 *   BONO_TEST_DRIVER=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_USER=postgres PG_BIN=C:\path\to\pgsql\bin php -d extension=pdo_pgsql tests/run.php
 * Uses a throw-away database; never touches your real data.
 */
$tmp = sys_get_temp_dir() . '/bono_test_' . getmypid();
@mkdir($tmp . '/backups', 0775, true);
$driver = getenv('BONO_TEST_DRIVER') ?: 'sqlite';
$pgDb = 'bono_test_' . getmypid();
if ($driver === 'pgsql') {
    putenv('DB_DRIVER=pgsql');
    putenv('DB_NAME=' . $pgDb);
    $admin = new PDO('pgsql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=postgres', getenv('DB_USER') ?: 'postgres', getenv('DB_PASSWORD') ?: '');
    $admin->exec("CREATE DATABASE $pgDb");
} else {
    define('DB_FILE', $tmp . '/test.sqlite');
}
putenv('BACKUP_DIR=' . str_replace('\\', '/', $tmp) . '/backups');
putenv('STORAGE_PATH=' . str_replace('\\', '/', $tmp) . '/uploads');
require __DIR__ . '/../app/bootstrap.php';
require BASE_PATH . '/app/lib/ui.php';
$_SESSION = [];
echo "Database engine: " . db_driver() . "\n";

$pass = 0; $fail = 0;
function section(string $s): void { echo "\n== $s\n"; }
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
    catch (Throwable $e) { $m = stripos($e->getMessage(), $needle) !== false; ok($m, "$msg -> \"" . substr($e->getMessage(), 0, 110) . '"' . ($m ? '' : "  [wanted: $needle]")); }
}

$U = [];
function mkuser(string $name, string $role, array $branches = []): int
{
    global $U;
    $id = insert('users', ['full_name' => ucfirst($name), 'username' => $name, 'password_hash' => password_hash('Test1234x', PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'created_at' => now()]);
    foreach ($branches as $i => $b) q('INSERT INTO user_branches(user_id, branch_id, is_primary) VALUES (?,?,?)', [$id, $b, $i === 0 ? 1 : 0]);
    return $U[$name] = $id;
}
/** Act as a user, optionally in a given branch. */
function as_(string $name, ?int $branch = null): void
{
    global $U;
    login_as($U[$name]);
    if ($branch) set_branch_context($branch);
}
function mkclient(string $name, string $nin, string $phone, int $branch): int
{
    return insert('clients', ['branch_id' => $branch, 'client_no' => make_number('client_prefix', 'client', false), 'full_name' => $name, 'nin' => $nin, 'phone' => $phone,
        'monthly_income' => 2000000, 'monthly_expenses' => 800000, 'registered_by' => 1, 'registered_at' => now()]);
}
function safe(int $branch): int { return (int)val("SELECT id FROM cash_accounts WHERE branch_id = ? AND name = 'Main Safe'", [$branch]); }

// ============================================================ calculation engine
section('Calculation engine');
$t = Calc::terms(1000000, 2000, 1, 300, 10000, 10000, 0, true);
eq($t['interest'], 200000, 'interest at 20% = 200,000');
eq($t['processing_fee'], 30000, 'processing fee 3% = 30,000');
eq($t['net_disbursement'], 950000, 'net disbursement = 950,000');
eq(Calc::terms(1000000, 2000, 1, 300, 10000, 10000, 0, false)['total_contractual'], 1250000, 'fees paid separately add to the contractual debt');
eq(Calc::pct(333333, 350), 11667, 'rounding: 3.5% of 333,333 half-up');
throws(fn() => Calc::terms(0, 2000, 1, 300, 0, 0), 'greater than zero', 'zero principal rejected');
eq(Calc::addMonths('2026-01-31', 1), '2026-02-28', 'month-end clamp');
eq(Calc::addWorkingDays('2026-10-23', 20), '2026-11-20', '20 working days (Mon-Fri)');
eq(Calc::allocate(500000, ['penalty' => 0, 'fee' => 0, 'interest' => 200000, 'principal' => 1000000], ['penalty', 'fee', 'interest', 'principal'])['allocations'], ['interest' => 200000, 'principal' => 300000], 'allocation order');
$s = Calc::schedule(1000001, 200001, '2026-10-01', '2026-11-01', 'weekly', 'installments');
eq(array_sum(array_column($s, 'total')), 1200002, 'weekly instalments sum exactly');
eq(Calc::dailyPenalty(1000000, 350), 35000, '3.5% daily penalty');
eq(amount_in_words(1250000), 'One Million Two Hundred and Fifty Thousand Uganda Shillings only', 'amount in words');

// ============================================================ MFA and request signing
section('Multi-factor authentication and cloud storage signing');
$secret = Mfa::base32Encode('12345678901234567890');
eq($secret, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 of the RFC 6238 test secret');
eq(Mfa::code($secret, 59), '287082', 'TOTP at t=59 (RFC 6238 vector, last 6 digits of 94287082)');
eq(Mfa::code($secret, 1111111109), '081804', 'TOTP at t=1111111109 (RFC 6238 vector)');
ok(Mfa::verify($secret, '287082', 59) !== null, 'current code accepted');
ok(Mfa::verify($secret, '287082', 59 + 30) !== null, 'previous step accepted (clock drift)');
ok(Mfa::verify($secret, '287082', 59 + 120) === null, 'stale code rejected');
ok(Mfa::verify($secret, '000000', 59) === null, 'wrong code rejected');
eq(decrypt_secret(encrypt_secret('seed-123')), 'seed-123', 'MFA seed encrypted at rest and decrypted');
ok(decrypt_secret('v1:garbage') === null, 'tampered ciphertext rejected');
[$plain, $hashes] = Mfa::recoveryCodes(3);
ok(Mfa::consumeRecovery($hashes, $plain[0]) !== null && Mfa::consumeRecovery(Mfa::consumeRecovery($hashes, $plain[0]), $plain[0]) === null, 'recovery code works once');
$sig = Storage::sigv4('GET', '/test.txt', '', ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9',
    'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'x-amz-date' => '20130524T000000Z'],
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'us-east-1', 's3', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', '20130524T000000Z');
eq($sig['signature'], 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', 'AWS SigV4 documentation example signature');

// ============================================================ installation, roles, branches
section('Installation, roles and permission boundaries');
migrate();
seed_reference_data();
$A = create_branch('KLA', 'Kampala Main');
$B = create_branch('GLU', 'Gulu');
mkuser('admin', 'System Administrator');
mkuser('coo', 'COO');
mkuser('bm', 'Branch Manager', [$A]);
mkuser('bmb', 'Branch Manager', [$B]);
mkuser('fo', 'Finance Officer', [$A]);
mkuser('fob', 'Finance Officer', [$B]);
mkuser('lo', 'Loan Officer', [$A]);
mkuser('lob', 'Loan Officer', [$B]);
mkuser('cash', 'Cashier', [$A]);
mkuser('cashb', 'Cashier', [$B]);
mkuser('bm2', 'Branch Manager', [$A]);
ok(is_installed(), 'system reports installed');
eq((int)val('SELECT COUNT(*) FROM roles WHERE is_principal = 1'), 5, 'five principal roles');
eq(setting('penalty_enabled'), '0', 'penalty rule disabled until management confirms');
as_('admin');
ok(can('user.manage') && can('backup.manage') && can('system.health'), 'System Administrator has technical rights');
ok(!can('loan.approve') && !can('loan.disburse') && !can('payment.record') && !can('eod.approve') && !can('penalty.adjust') && !can('loan.close'), 'System Administrator has NO financial authority');
throws(fn() => Payments::record(['loan_id' => 1, 'amount' => '1000', 'method' => 'Cash']), 'permission', 'administrator cannot post payments');
throws(fn() => assert_permission_boundaries('System Administrator', array_merge(default_role_permissions()['System Administrator'], ['loan.approve'])), 'cannot hold financial authority', 'boundary: admin cannot be granted loan approval');
throws(fn() => assert_permission_boundaries('COO', array_merge(default_role_permissions()['COO'], ['backup.manage'])), 'reserved for the System Administrator', 'boundary: COO cannot receive technical rights');
as_('coo');
ok(can('authority.institution') && can('limits.manage') && !can('user.manage') && !can('backup.manage'), 'COO: institution authority, no technical admin rights');
eq(accessible_branch_ids(), [$A, $B], 'COO can access every branch');
as_('lo');
ok(can('loan.create') && !can('loan.approve'), 'Loan Officer can create but not approve');
eq(accessible_branch_ids(), [$A], 'Loan Officer limited to the assigned branch');
as_('fo');
ok(can('payment.reverse.request') && !can('payment.reverse') && !can('loan.approve') && !can('payment.record'), 'Finance Officer: oversight and requests only by default');
eq(attempt_login('bm', 'wrong'), 'fail', 'wrong password rejected');
ok(str_starts_with((string)val('SELECT password_hash FROM users WHERE username = ?', ['bm']), '$'), 'password stored as hash');
eq(attempt_login('BM', 'Test1234x'), 'ok', 'username is case-insensitive');
q('UPDATE users SET mfa_enabled = 1, mfa_secret = ? WHERE username = ?', [encrypt_secret(Mfa::newSecret()), 'bm']);
eq(attempt_login('bm', 'Test1234x'), 'mfa', 'a user with MFA enrolled is asked for a code after the password');
$_SESSION = [];
q('UPDATE users SET mfa_enabled = 0, mfa_secret = NULL WHERE username = ?', ['bm']);

// ============================================================ business day per branch
section('Business day control per branch');
as_('cash');
throws(fn() => posting_date(), 'No business day is open', 'no posting before a day is opened');
throws(fn() => Eod::openDay('2026-10-23'), 'permission', 'cashier cannot open the business day');
as_('bm');
throws(fn() => Eod::openDay('2026-10-23'), 'permission', 'branch manager does not open the day (finance prepares)');
as_('fo');
$d1 = '2026-10-23';
Eod::openDay($d1);
eq(current_bd(), $d1, 'Kampala business day opened');
throws(fn() => Eod::openDay('2026-10-24', false, $B), 'access', 'finance officer cannot open another branch');
as_('fob');
Eod::openDay('2026-10-20');
eq(current_bd(), '2026-10-20', 'Gulu runs its own business date');
as_('fo');
eq(current_bd(), $d1, 'Kampala date unaffected by Gulu');
eq(Eod::status($d1), 'In Progress', 'EOD status In Progress');

// ============================================================ loan workflow
section('Loan workflow and separation of duties');
as_('lo');
$cid = mkclient('Akello Grace', 'CM90000000AAAA', '0772000001', $A);
$lid = Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '1,000,000', 'purpose' => 'Stock for shop', 'period_months' => 1, 'frequency' => 'monthly', 'application_date' => $d1,
    'monthly_income' => '2000000', 'monthly_expenses' => '800000']);
$loan = Loans::get($lid);
eq((int)$loan['branch_id'], $A, 'loan belongs to the client branch');
ok(preg_match('/^BLM-\d{4}-000001$/', $loan['loan_no']) === 1, 'loan number ' . $loan['loan_no']);
throws(fn() => Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '1000000', 'purpose' => 'x', 'application_date' => $d1]), 'already exists', 'duplicate application blocked');
Loans::submit($lid);
Loans::recordAssessment($lid, ['recommendation' => 'Approve', 'recommended_amount' => '1000000', 'income' => '2000000', 'expenses' => '800000', 'existing_obligations' => '0']);
eq(Loans::get($lid)['status'], 'Under Review', 'assessment moves loan to Under Review');
throws(fn() => Loans::approve($lid, ['approved_principal' => '1000000']), 'permission', 'officer cannot approve');
$terms = ['approved_principal' => '1000000', 'rate_pct' => '20', 'processing_pct' => '3', 'application_fee' => '10000', 'inspection_fee' => '10000', 'other_fees' => '0', 'fees_deducted' => '1', 'months' => 1];
as_('bm');
throws(fn() => Loans::approve($lid, ['approved_principal' => '1000000', 'inspection_fee' => '50000'] + $terms), 'outside the configured range', 'inspection fee limits enforced');
throws(fn() => Loans::approve($lid, ['approved_principal' => '900000'] + $terms), 'reason is required', 'changing the amount needs a reason');
Loans::approve($lid, $terms);
$loan = Loans::get($lid);
eq([$loan['status'], (int)$loan['net_disbursement'], (int)$loan['interest_amount']], ['Approved', 950000, 200000], 'approved snapshot: net 950,000, interest 200,000');
eq($loan['offer_expiry'], '2026-11-16', 'offer expiry = 20 working days (Mon-Sat calendar)');
set_setting('interest_rate_pct', '25', uid());
set_setting('interest_rate_pct', '20', uid());
eq((int)Loans::get($lid)['interest_amount'], 200000, 'a settings change does not alter the approved loan');
eq(val('SELECT approver_role FROM loan_approvals WHERE loan_id = ? ORDER BY id DESC LIMIT 1', [$lid]), 'Branch Manager', 'approver authority level recorded');
as_('lo');
$noAssess = Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '400000', 'purpose' => 'Seeds', 'application_date' => $d1]);
Loans::submit($noAssess);
as_('bm');
throws(fn() => Loans::approve($noAssess, ['approved_principal' => '400000'] + $terms), 'assessment is required', 'assessment required before approval');
q('INSERT INTO user_permissions(user_id, permission, granted) VALUES (?,?,1)', [$U['lo'], 'loan.approve']);
as_('lo');
$own = Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '300000', 'purpose' => 'Own', 'application_date' => $d1]);
Loans::submit($own);
throws(fn() => Loans::approve($own, ['approved_principal' => '300000', 'exception' => 1, 'exception_reason' => 'urgent'] + $terms), 'originated', 'officer cannot approve own application');
q('DELETE FROM user_permissions WHERE user_id = ?', [$U['lo']]);

section('Disbursement controls and branch isolation');
as_('cash');
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['bm']]), 'acceptance is required', 'no disbursement before acceptance');
as_('lo');
Loans::recordAcceptance($lid, true, $d1, 'Signed offer');
as_('cash');
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['bm']]), 'open cashier session', 'cash disbursement needs an open till');
$sess = Cash::openSession(500000, true);
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['bm']]), 'insufficient cash', 'till must hold enough cash');
Cash::transfer('from_account', 2000000, safe($A), null, 'Cash for disbursements');
eq(Cash::expected($sess), 2500000, 'till expected cash after transfer in');
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['fo']]), 'authorizing manager', 'authorizer must hold disbursement-authorization rights');
as_('cashb');
throws(fn() => Loans::get($lid), 'do not have access', 'Gulu cashier cannot read a Kampala loan');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Cash']), 'do not have access', 'Gulu cashier cannot post to a Kampala loan');
eq(rows('SELECT id FROM loans WHERE ' . branch_scope('branch_id')), [], 'branch_scope hides Kampala loans from Gulu users');
as_('lob');
throws(fn() => Loans::saveApplication(['client_id' => $cid, 'amount_requested' => '100000', 'purpose' => 'x']), 'access', 'Gulu officer cannot lend to a Kampala client');
as_('coo');
ok(Loans::get($lid)['id'] == $lid, 'COO can read loans of every branch');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Cash']), 'permission', 'COO does not post collections');
as_('cash');
$did = Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['bm']]);
$loan = Loans::get($lid);
eq([$loan['status'], $loan['due_date']], ['Active', '2026-11-23'], 'loan active; maturity = disbursement + 1 month');
eq(Cash::expected($sess), 1550000, 'till reduced by NET 950,000');
$b = Loans::balances($lid);
eq([$b['principal'], $b['interest'], $b['penalty'], $b['fee'], $b['total']], [1000000, 200000, 0, 0, 1200000], 'outstanding balances separate');
throws(fn() => Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['bm']]), 'already been disbursed', 'duplicate disbursement blocked');

section('Payments, receipts, allocation');
$p1 = Payments::record(['loan_id' => $lid, 'amount' => '500,000', 'method' => 'Cash', 'txn_token' => 'tok-1']);
$r1 = Payments::receipt($p1);
ok(preg_match('/^RC-\d{4}-000001$/', $r1['receipt_no']) === 1, 'receipt number ' . $r1['receipt_no']);
eq($r1['remaining_balance'], 700000, 'receipt shows remaining balance');
eq([Loans::balances($lid)['interest'], Loans::balances($lid)['principal']], [0, 700000], 'interest cleared first, then principal');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '500000', 'method' => 'Cash', 'txn_token' => 'tok-1']), 'already been posted', 'duplicate submission blocked');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '900000', 'method' => 'Cash']), 'exceeds the outstanding', 'overpayment blocked by default');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Mobile Money']), 'reference is required', 'mobile money needs a reference');
$pm = Payments::record(['loan_id' => $lid, 'amount' => '100000', 'method' => 'Mobile Money', 'reference' => 'MM12345']);
eq(Cash::expected($sess), 2050000, 'mobile money does NOT change physical cash');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Mobile Money', 'reference' => 'MM12345']), 'reference has already', 'duplicate mobile reference blocked');

section('Payment reversal (maker-checker)');
as_('cash');
throws(fn() => Payments::requestReversal($pm, 'oops'), 'permission', 'cashier cannot request a reversal');
throws(fn() => Payments::reverse($pm, 'oops'), 'permission', 'cashier cannot reverse');
as_('bm');
throws(fn() => Payments::reverse($pm, 'Wrong loan'), 'independent approval', 'manager cannot reverse directly while independent approval is required');
as_('fo');
throws(fn() => Payments::requestReversal($pm, ''), 'reason is required', 'reversal request needs a reason');
$rq = Payments::requestReversal($pm, 'Wrong loan');
throws(fn() => Payments::requestReversal($pm, 'again'), 'already pending', 'only one pending request per payment');
throws(fn() => Payments::approveReversal($rq), 'permission', 'finance officer cannot approve reversals');
as_('bm');
Payments::approveReversal($rq, 'Verified with the client');
eq(val('SELECT status FROM payments WHERE id = ?', [$pm]), 'Reversed', 'payment marked reversed (not deleted)');
eq(val('SELECT status FROM receipts WHERE payment_id = ?', [$pm]), 'Void', 'receipt voided but retained');
eq(Loans::balances($lid)['principal'], 700000, 'balance restored after reversal');
ok(str_contains((string)val("SELECT approval_ref FROM audit_logs WHERE action = 'Payment reversed' ORDER BY id DESC LIMIT 1"), 'REQ-'), 'reversal audit carries the approval reference');
throws(fn() => q('DELETE FROM payments WHERE id = ?', [$pm]), 'cannot be deleted', 'payments cannot be deleted');
throws(fn() => q('UPDATE payments SET amount = 1 WHERE id = ?', [$p1]), 'cannot be altered', 'posted payments cannot be edited');
throws(fn() => q('DELETE FROM audit_logs'), 'cannot be deleted', 'audit log is protected');
throws(fn() => q('UPDATE loan_ledger SET amount = 0'), 'cannot be modified', 'ledger is append-only');
as_('cash');
$p2 = Payments::record(['loan_id' => $lid, 'amount' => '100000', 'method' => 'Cash', 'txn_token' => 'tok-2']);
as_('bm');
$rq2 = Payments::requestReversal($p2, 'Test self-approval');
throws(fn() => Payments::approveReversal($rq2), 'requested', 'requester cannot approve their own reversal request');
as_('bm2');
Payments::rejectReversal($rq2, 'Not needed');
eq(val('SELECT status FROM approval_requests WHERE id = ?', [$rq2]), 'Rejected', 'request rejected by an independent approver');

section('Approval limits and escalation');
as_('admin');
set_setting('admin_manages_policy', '0', uid()); $GLOBALS['__settings_dirty'] = true;
throws(fn() => Approvals::setLimit('loan', 0, 2000000), 'permission', 'with strict separation on, the administrator cannot set approval limits');
set_setting('admin_manages_policy', '1', uid()); $GLOBALS['__settings_dirty'] = true;
as_('coo');
eq(count(Approvals::unconfigured()), count(Approvals::CATEGORIES), 'no approval limit is invented: all categories start unconfigured');
Approvals::setLimit('loan', 0, 2000000);
Approvals::setLimit('expense', 0, 500000);
Approvals::setLimit('eod_variance', 0, 5000);
eq(Approvals::required('loan', 1500000, $A), 'Branch Manager', 'within limit -> Branch Manager');
eq(Approvals::required('loan', 3000000, $A), 'COO', 'above limit -> COO');
Approvals::setLimit('loan', $B, 10000000);
eq(Approvals::required('loan', 3000000, $B), 'Branch Manager', 'a branch-specific limit overrides the institution default');
as_('lo');
$big = mkclient('Big Borrower', 'CM90000000BIGG', '0772000099', $A);
$bigLoan = Loans::saveApplication(['client_id' => $big, 'amount_requested' => '3000000', 'purpose' => 'Large order', 'application_date' => $d1]);
Loans::submit($bigLoan);
Loans::recordAssessment($bigLoan, ['recommendation' => 'Approve', 'recommended_amount' => '3000000', 'income' => '5000000', 'expenses' => '1000000']);
as_('bm');
throws(fn() => Loans::approve($bigLoan, ['approved_principal' => '3000000'] + $terms), 'exceeds the Branch Manager limit', 'branch manager cannot approve above the limit');
Loans::escalate($bigLoan, 'Above my limit');
ok(Loans::get($bigLoan)['escalated_at'] !== null, 'loan escalated to the COO');
$bigNo = Loans::get($bigLoan)['loan_no'];
eq(count(array_filter(Approvals::inbox(), fn($i) => $i['ref'] === $bigNo)), 0, 'escalated loan is not in the Branch Manager inbox');
as_('coo', $A);
eq(count(array_filter(Approvals::inbox(), fn($i) => $i['ref'] === $bigNo)), 1, 'escalated loan appears in the COO inbox');
Loans::approve($bigLoan, ['approved_principal' => '3000000'] + $terms);
eq([Loans::get($bigLoan)['status'], val('SELECT approver_role FROM loan_approvals WHERE loan_id = ? AND decision = ? ORDER BY id DESC LIMIT 1', [$bigLoan, 'Approved'])], ['Approved', 'COO'], 'COO approves the escalated loan');

section('Expenses and till separation of duties');
as_('cash');
$cat = (int)val('SELECT id FROM expense_categories WHERE name = ?', ['Transport']);
$e1 = Cash::addExpense(['category_id' => $cat, 'description' => 'Fuel', 'amount' => '50000', 'method' => 'Cash', 'txn_token' => 'e1']);
eq(val('SELECT status FROM expenses WHERE id = ?', [$e1]), 'Paid', 'routine expense paid immediately');
throws(fn() => Cash::addExpense(['category_id' => $cat, 'description' => 'Fuel', 'amount' => '50000', 'method' => 'Cash', 'txn_token' => 'e1']), 'already been posted', 'duplicate expense blocked');
$e2 = Cash::addExpense(['category_id' => $cat, 'description' => 'Vehicle repair', 'amount' => '300000', 'method' => 'Cash', 'txn_token' => 'e2']);
eq(val('SELECT status FROM expenses WHERE id = ?', [$e2]), 'Pending', 'expense above the routine limit awaits approval');
throws(fn() => Cash::pay($e2), 'must be approved', 'unapproved expense cannot be paid');
$e3 = Cash::addExpense(['category_id' => $cat, 'description' => 'Generator', 'amount' => '700000', 'method' => 'Cash', 'txn_token' => 'e3']);
eq(val('SELECT required_authority FROM expenses WHERE id = ?', [$e3]), 'COO', 'expense above the branch limit requires COO');
as_('bm');
Cash::approveExpense($e2);
throws(fn() => Cash::approveExpense($e3), 'exceeds the Branch Manager limit', 'branch manager cannot approve a COO-level expense');
as_('cash');
Cash::pay($e2);
eq(val('SELECT status FROM expenses WHERE id = ?', [$e2]), 'Paid', 'approved expense paid');
eq(Cash::expected($sess), 1800000, 'only paid expenses reduce the till');
$exp = Cash::expected($sess);
throws(fn() => Cash::submitCount($sess, $exp - 10000, ''), 'requires an explanation', 'variance requires an explanation');
Cash::submitCount($sess, $exp - 10000, 'Short change given to client');
eq([Cash::session($sess)['status'], (int)Cash::session($sess)['variance']], ['Submitted', -10000], 'UGX 10,000 shortage flagged');
throws(fn() => Cash::reconcile($sess, 'ok'), 'permission', 'cashier cannot reconcile');

section('End of Day: preparation, independent approval, escalation, reopening');
as_('fo');
throws(fn() => Eod::submit($d1), 'reconciliations are outstanding', 'EOD blocked while a count is unreconciled');
throws(fn() => Cash::reconcile($sess, 'Shortage noted'), 'approval authority', 'finance officer cannot approve a till variance (needs manager authority)');
as_('bm');
throws(fn() => Cash::reconcile($sess, ''), 'explanation', 'variance needs a reconciliation note');
throws(fn() => Cash::reconcile($sess, 'Shortage noted'), 'exceeds the Branch Manager limit', 'branch manager cannot approve a variance above the configured limit');
as_('coo', $A);
Cash::reconcile($sess, 'Shortage recorded; counted twice');
eq((int)Cash::session($sess)['variance'], -10000, 'original variance preserved after reconciliation');
as_('fo');
Eod::submit($d1, 'Day one');
eq(Eod::status($d1), 'Escalated to COO', 'EOD with a variance above the limit is escalated');
as_('bm');
throws(fn() => Eod::approve($d1), 'COO', 'branch manager cannot approve an escalated EOD');
as_('fo');
throws(fn() => Eod::approve($d1), 'permission', 'preparer has no approval permission');
as_('coo', $A);
$sum = json_decode(Eod::latestRecord($d1)['summary_json'], true);
eq([$sum['disbursements']['net'], $sum['disbursements']['principal'], $sum['disbursements']['fees']], [950000, 1000000, 50000], 'EOD summary: net / gross / fees');
eq([$sum['collections']['total'], $sum['collections']['cash']], [600000, 600000], 'EOD summary: collections exclude the reversed payment');
eq($sum['cash']['variance'], -10000, 'EOD summary: cash variance');
$bk = Eod::approve($d1, 'Approved after review');
eq(Eod::status($d1), 'Closed', 'business date closed by the COO');
ok($bk['ok'], 'EOD backup created and verified: ' . ($bk['file'] ?? $bk['message']));
as_('cash');
throws(fn() => Payments::record(['loan_id' => $lid, 'amount' => '1000', 'method' => 'Cash']), 'already closed', 'no backdated posting after close');
as_('fob');
eq(current_bd(), '2026-10-20', 'Gulu still open: the Kampala closure did not block it');
as_('fo');
$rqo = Eod::requestReopen($d1, 'Receipt amount keyed wrongly');
throws(fn() => Eod::decideReopen($rqo, true, 'ok'), 'permission', 'finance officer cannot authorize reopening');
as_('bm');
Eod::decideReopen($rqo, true, 'Authorized');
eq(Eod::status($d1), 'Reopened by Authorization', 'date reopened by authorization');
eq((int)val('SELECT COUNT(*) FROM eod_records WHERE branch_id = ? AND business_date = ?', [$A, $d1]), 1, 'original EOD record preserved');
as_('fo');
Eod::submit($d1, 'Resubmitted');
eq((int)Eod::latestRecord($d1)['version'], 2, 'resubmission creates version 2');
q('INSERT INTO user_permissions(user_id, permission, granted) VALUES (?,?,1)', [$U['fo'], 'eod.approve']);
q('INSERT INTO user_permissions(user_id, permission, granted) VALUES (?,?,1)', [$U['fo'], 'authority.institution']);
as_('fo');
throws(fn() => Eod::approve($d1), 'cannot be its approver', 'preparer cannot approve their own EOD even if given approval rights');
q('DELETE FROM user_permissions WHERE user_id = ?', [$U['fo']]);
as_('coo', $A);
Eod::approve($d1, 'Re-approved');
eq(Eod::status($d1), 'Closed', 'closed again');

section('Arrears and penalties (branch dates)');
as_('fo');
Eod::openDay('2026-10-26');
as_('lo');
$c3 = mkclient('Mugisha Tom', 'CM90000000CCCC', '0772000003', $A);
$l3 = Loans::saveApplication(['client_id' => $c3, 'amount_requested' => '1000000', 'purpose' => 'Trade', 'application_date' => '2026-10-26']);
Loans::submit($l3);
Loans::recordAssessment($l3, ['recommendation' => 'Approve', 'recommended_amount' => '1000000', 'income' => '1500000', 'expenses' => '500000']);
set_setting('penalty_enabled', '1', 1);
set_setting('penalty_rate_pct', '3.5', 1);
as_('bm');
Loans::approve($l3, $terms);
as_('lo'); Loans::recordAcceptance($l3, true, '2026-10-26');
as_('cash');
$ts = Cash::openSession((int)Cash::lastClosingCash($U['cash']), true, 'carried forward');
Cash::transfer('from_account', 2000000, safe($A), null, 'Float');
Loans::disburse($l3, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => $U['bm']]);
eq(Loans::get($l3)['due_date'], '2026-11-26', 'second loan matures 26 Nov');
Cash::submitCount($ts, Cash::expected($ts), '');
as_('fo'); Cash::reconcile($ts, '');
Eod::submit('2026-10-26');
as_('bm'); Eod::approve('2026-10-26');
as_('fo'); Eod::openDay('2026-11-28');
eq(Loans::get($l3)['status'], 'Overdue', 'loan overdue after due date');
$info = Loans::overdueInfo(Loans::get($l3));
eq([$info['days'], $info['amount']], [2, 1200000], 'days overdue 2; overdue amount 1,200,000');
eq(Penalty::accrue('2026-11-28', $A), 2, 'two daily penalties accrued');
eq(Penalty::accrue('2026-11-28', $A), 0, 'accrual is idempotent');
$b = Loans::balances($l3);
eq([$b['penalty'], $b['principal']], [70000, 1000000], 'penalty 70,000 stored separately; principal unchanged');
as_('cash');
$cs3 = Cash::openSession((int)Cash::lastClosingCash($U['cash']), true, 'carried forward');
$pp = Payments::record(['loan_id' => $l3, 'amount' => '50000', 'method' => 'Cash']);
eq(rows('SELECT component, amount FROM payment_allocations WHERE payment_id = ?', [$pp]), [['component' => 'penalty', 'amount' => 50000]], 'configured order pays penalty first');
as_('bm');
Loans::waive($l3, 'penalty', 20000, 'Goodwill waiver');
eq(Loans::balances($l3)['penalty'], 0, 'penalty waived with authorization');

section('Branch isolation sweep (every list page rendered as a Gulu user)');
$render = function (string $page, array $get = []) {
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/index.php?p=' . $page;
    ob_start();
    try { $GLOBALS['title'] = ''; require BASE_PATH . '/app/pages/' . $page . '.php'; } catch (Throwable $e) { /* permission notices are fine */ }
    return ob_get_clean();
};
$secrets = ['Akello Grace', 'Big Borrower', 'Mugisha Tom', 'BLM-2026-0000', 'RC-2026-0000', 'Kampala Main'];
$leaks = [];
foreach (['cashb' => ['clients', 'guarantors', 'loans', 'disbursements', 'securities', 'schedules', 'payments', 'receipts', 'portfolio', 'arrears', 'till', 'cashbook', 'eod', 'dashboard'],
          'bmb' => ['approvals', 'assessment', 'reports', 'audit', 'inbox'], 'lob' => ['loans', 'assessment']] as $who => $pages) {
    as_($who);
    foreach ($pages as $pg) {
        $html = $render($pg);
        foreach ($secrets as $needle) if (stripos($html, $needle) !== false && !($needle === 'Kampala Main' && in_array($pg, ['dashboard'], true))) $leaks[] = "$who/$pg shows '$needle'";
    }
}
foreach (['daily_collections', 'active_loans', 'outstanding_balances', 'client_register', 'guarantor_register', 'security_register', 'daily_transactions', 'cashier_variances', 'eod_history', 'principal_disbursed', 'cashbook_summary', 'approvals_log', 'portfolio_summary'] as $rk) {
    as_('bmb');
    $rep = Reports::run($rk, ['from' => '2020-01-01', 'to' => '2030-12-31']);
    $blob = json_encode($rep['rows']);
    foreach (['Akello Grace', 'Big Borrower', 'Mugisha Tom', 'BLM-2026-0000'] as $needle) if (stripos($blob, $needle) !== false) $leaks[] = "report $rk leaks '$needle'";
}
eq($leaks, [], 'no Kampala record appears on any list page or report for Gulu users');
as_('bmb');
eq(Approvals::inbox(), [], 'Gulu manager inbox holds nothing from Kampala');
$kampala = $render('loans');
as_('bm');
ok(str_contains($render('loans'), 'BLM-2026-0000'), 'sanity: the same page does show loans to a Kampala user');
$_GET = [];
$src = '';
foreach (glob(BASE_PATH . '/app/pages/*.php') as $f) $src .= file_get_contents($f);
eq(preg_match('/\\\\"\s*\.\s*branch_scope/', $src), 0, 'source lint: no mis-escaped branch_scope() inside SQL (SQLite would silently ignore it)');

section('Cloud plumbing: shared sessions, proxy-aware client IP, health');
$h = new DbSessionHandler();
ok($h->write('sess-test-1', 'a|s:1:"b";') && $h->read('sess-test-1') === 'a|s:1:"b";', 'database-backed session write/read');
$h->destroy('sess-test-1');
eq($h->read('sess-test-1'), '', 'session destroy');
ok($h->write('sess-old', 'x') && q('UPDATE app_sessions SET updated_at = 1 WHERE id = ?', ['sess-old']) && $h->gc(3600) >= 1, 'expired sessions are garbage-collected');
putenv('TRUST_PROXY=1'); $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.2'; $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
eq(client_ip(), '203.0.113.9', 'client IP taken from X-Forwarded-For only behind a trusted proxy');
putenv('TRUST_PROXY=0'); eq(client_ip(), '10.0.0.2', 'X-Forwarded-For ignored when no trusted proxy is configured');
$_SERVER['HTTP_X_FORWARDED_FOR'] = ''; unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$ps = System::publicStatus();
eq([$ps['db'], $ps['storage'], $ps['migrations']], ['ok', 'ok', 'ok'], 'public health probe reports db / storage / migrations');
ok(!isset($ps['users']) && !str_contains(json_encode($ps), 'password'), 'health probe reveals no sensitive data');
as_('admin');
$hh = System::health();
ok(isset($hh['mfa_missing']) && is_array($hh['production_notes']), 'administrator health snapshot available');
as_('admin');

section('Recovery, integrity and health');
as_('admin');
eq(Backup::integrityReport(), [], 'integrity report is clean');
$bk = Backup::create('Manual');
ok($bk['ok'], 'manual backup verified (' . $bk['message'] . ')');
file_put_contents(Backup::dir() . '/bad.' . Backup::ext(), str_repeat('x', 9000));
[$vbad] = Backup::verify(Backup::dir() . '/bad.' . Backup::ext());
ok(!$vbad, 'corrupt backup rejected');
throws(fn() => Backup::restore($bk['file']), 'authorization', 'restore needs prior COO authorization');
$rr = Backup::requestRestore($bk['file'], 'Disaster-recovery drill');
throws(fn() => Backup::authorizeRestore($rr, true), 'permission', 'administrator cannot authorize their own recovery');
as_('coo');
Backup::authorizeRestore($rr, true, 'Approved for the drill');
as_('admin');
$before = (int)val('SELECT COUNT(*) FROM payments');
q("UPDATE settings SET value = 'CHANGED' WHERE key = 'business_name'");
$GLOBALS['__settings_dirty'] = true;
Backup::restore($bk['file']);
eq(setting('business_name'), 'Bono Loan Manager', 'restore brings back the backed-up state');
eq((int)val('SELECT COUNT(*) FROM payments'), $before, 'payments intact after restore');
ok((int)val("SELECT COUNT(*) FROM backup_history WHERE type = 'Pre-restore'") === 1, 'safety backup taken before restore');
eq(Backup::integrityReport(), [], 'integrity clean after restore');
ok(Storage::writable(), 'private file storage is writable');

section('Institution profile, letterhead and staff IDs');
as_('admin');
set_setting('business_name', 'Emglo Finance Ltd', uid());
set_setting('business_address', 'Plot 7 Kampala Road', uid());
set_setting('business_phone', '+256700111222', uid());
set_setting('institution_code', 'EMG', uid());
$GLOBALS['__settings_dirty'] = true;
$lh = letterhead('RECEIPT', 'No. 1');
ok(str_contains($lh, 'Emglo Finance Ltd') && str_contains($lh, 'Plot 7 Kampala Road') && str_contains($lh, '+256700111222'), 'letterhead carries the saved name, address and phone');
ok(str_contains($lh, 'RECEIPT'), 'letterhead shows the document title');
set_setting('business_name', 'Renamed <b>Co</b>', uid());
$GLOBALS['__settings_dirty'] = true;
ok(!str_contains(letterhead(), '<b>Co</b>') && str_contains(letterhead(), 'Renamed'), 'letterhead escapes institution text');
$s1 = mkuser('staffa', 'Loan Officer'); $s2 = mkuser('staffb', 'Cashier');
$n1 = assign_staff_no($s1); $n2 = assign_staff_no($s2);
ok(preg_match('/^EMG-S-[0-9]{4,}$/', (string)$n1) === 1, 'staff ID uses institution code and prefix: ' . $n1);
ok($n1 !== $n2, 'staff IDs are unique');
eq((int)(find_user_by_name(strtolower((string)$n1))['id'] ?? 0), $s1, 'a user can sign in with their Staff ID (case-insensitive)');
throws(fn() => q('UPDATE users SET staff_no = ? WHERE id = ?', [$n1, $s2]), '', 'duplicate Staff ID is rejected by the database');
eq(institution_code_from('Emglo Finance Ltd'), 'EFL', 'institution code derived from initials');
ok(in_array('logo', profile_gaps(), true), 'profile gaps flag the missing logo');
$cl = System::setupChecklist();
ok(count($cl) >= 8 && isset($cl[0][4]), 'set-up checklist is produced');
q("UPDATE users SET status = 'inactive' WHERE role = 'COO'");
as_('admin');
Approvals::setLimit('loan', 0, 5000000);
eq((int)val("SELECT max_amount FROM approval_limits WHERE category = 'loan' AND branch_id = 0"), 5000000, 'administrator can configure initial limits while no COO exists');
q("UPDATE users SET status = 'active' WHERE role = 'COO'");
Approvals::setLimit('loan', 0, 6000000);
eq((int)val("SELECT max_amount FROM approval_limits WHERE category = 'loan' AND branch_id = 0"), 6000000, 'by default the administrator keeps full configuration rights even with a COO');
set_setting('admin_manages_policy', '0', uid()); $GLOBALS['__settings_dirty'] = true;
if ((int)val("SELECT COUNT(*) FROM users WHERE role = 'COO' AND status = 'active'")) throws(fn() => Approvals::setLimit('loan', 0, 1), 'permission', 'with strict separation switched on, the administrator cannot change limits once a COO exists');
set_setting('admin_manages_policy', '1', uid()); $GLOBALS['__settings_dirty'] = true;

echo "\n----------------------------------------\n";
echo "Passed: $pass   Failed: $fail   (" . db_driver() . ")\n";
db_close();
if ($driver === 'pgsql') {
    try { $admin->exec("DROP DATABASE IF EXISTS $pgDb WITH (FORCE)"); } catch (Throwable $e) { echo "cleanup: " . $e->getMessage() . "\n"; }
}
foreach (array_merge(glob($tmp . '/backups/*') ?: [], glob($tmp . '/uploads/*') ?: [], glob($tmp . '/*') ?: []) as $f) @is_file($f) && @unlink($f);
@rmdir($tmp . '/backups'); @rmdir($tmp . '/uploads'); @rmdir($tmp);
exit($fail ? 1 : 0);
