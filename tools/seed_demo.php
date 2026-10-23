<?php
/**
 * Optional demo data for evaluation / training (NOT for production). Creates two branches with staff in every role,
 * clients, loans in every stage, closed business days with reconciled tills, an overdue loan, approval limits and a
 * pending escalation - all through the real services, so every rule (audit, ledger, EOD, separation of duties) applies.
 *
 *   php tools/seed_demo.php            (SQLite)      or set DB_DRIVER=pgsql + DB_* first (PostgreSQL)
 *
 * Refuses to run on a system that already holds clients. All demo accounts share the password below and MFA is not
 * required in the demo - deactivate these accounts and turn MFA on (Settings -> Security) before real use.
 */
const DEMO_PASSWORD = 'Demo@2026pass';

require __DIR__ . '/../app/bootstrap.php';
$_SESSION = [];

if (PHP_SAPI !== 'cli') { exit('Run from the command line.'); }
migrate();
if (is_installed() && (int)val('SELECT COUNT(*) FROM clients') > 0) { exit("This database already contains clients; refusing to add demo data.\n"); }

function wd(string $date, int $dir = -1): string { while (!is_working_day($date, 0)) $date = Calc::addDays($date, $dir); return $date; }
function nu(string $user): int { return (int)val('SELECT id FROM users WHERE username = ?', [$user]); }
function as_(string $user, ?int $branch = null): void
{
    $id = $user === 'admin' && !nu('admin') ? (int)val("SELECT MIN(id) FROM users WHERE role = 'System Administrator'") : nu($user);
    login_as($id);
    if ($branch) set_branch_context($branch);
}
function safe(int $b): int { return (int)val("SELECT id FROM cash_accounts WHERE branch_id = ? AND name = 'Main Safe'", [$b]); }

if (!is_installed()) {
    install_system('Bono Loan Manager', 'System Administrator', 'admin', DEMO_PASSWORD, 'Kampala Main', 'KLA');
    echo "Installed. System Administrator: admin\n";
}
$A = (int)val("SELECT id FROM branches WHERE code = 'KLA'") ?: (int)val('SELECT MIN(id) FROM branches');   // an existing installation keeps its own first branch
$B = (int)val("SELECT id FROM branches WHERE code = 'GLU'") ?: create_branch('GLU', 'Gulu Branch', 'Gulu City');
set_setting('mfa_required_roles', '', null, false);   // demo only
as_('admin');
foreach ([['coo', 'Dr. Agnes Tumusiime', 'COO', []], ['bm', 'Grace Namukasa', 'Branch Manager', [$A]], ['fo', 'Ruth Achieng', 'Finance Officer', [$A]], ['lo', 'Peter Okello', 'Loan Officer', [$A]],
          ['cashier', 'Sarah Nakato', 'Cashier', [$A]], ['bmg', 'Samuel Opio', 'Branch Manager', [$B]], ['fog', 'Mercy Akena', 'Finance Officer', [$B]], ['log', 'Daniel Okello', 'Loan Officer', [$B]],
          ['cashierg', 'Patrick Odong', 'Cashier', [$B]]] as [$u, $n, $r, $br]) {
    if ($id = nu($u)) {
        if ($br && !val('SELECT COUNT(*) FROM user_branches WHERE user_id = ?', [$id])) foreach ($br as $i => $b) q('INSERT INTO user_branches(user_id, branch_id, is_primary) VALUES (?,?,?)', [$id, $b, $i === 0 ? 1 : 0]);
        continue;
    }
    $id = insert('users', ['full_name' => $n, 'username' => $u, 'password_hash' => password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), 'role' => $r, 'status' => 'active', 'created_at' => now(), 'created_by' => nu('admin'), 'password_changed_at' => now()]);
    foreach ($br as $i => $b) q('INSERT INTO user_branches(user_id, branch_id, is_primary) VALUES (?,?,?)', [$id, $b, $i === 0 ? 1 : 0]);
}
update('branches', ['manager_id' => nu('bm')], 'id = :id', ['id' => $A]);
update('branches', ['manager_id' => nu('bmg')], 'id = :id', ['id' => $B]);

// demo approval limits (the real institution sets its own; the system ships with none)
as_('coo');
Approvals::setLimit('loan', 0, 3000000);
Approvals::setLimit('expense', 0, 500000);
Approvals::setLimit('eod_variance', 0, 20000);

$clients = [];
foreach ([['Akello Grace', 'Female', '1988-03-12', 'CF88012345AKGR', '0772100001', 'Kisenyi', 'Kampala', 'Market vendor', 'Grace Fresh Produce', 1800000, 900000],
          ['Okumu Joseph', 'Male', '1985-07-30', 'CM85098765OKJO', '0772100002', 'Nansana', 'Wakiso', 'Boda boda rider', '', 1500000, 700000],
          ['Namatovu Esther', 'Female', '1992-11-05', 'CF92045612NAES', '0772100003', 'Ntinda', 'Kampala', 'Salon owner', 'Esther Beauty Hub', 2400000, 1100000],
          ['Mukasa Ronald', 'Male', '1980-01-22', 'CM80011122MURO', '0772100004', 'Entebbe', 'Wakiso', 'Carpenter', 'Mukasa Furniture', 2000000, 800000],
          ['Atim Beatrice', 'Female', '1990-09-17', 'CF90076543ATBE', '0772100005', 'Gulu', 'Gulu', 'Tailor', 'Atim Fashions', 1200000, 500000],
          ['Ssemakula Brian', 'Male', '1995-05-02', 'CM95022211SSBR', '0772100006', 'Mukono', 'Mukono', 'Shop keeper', 'Brian General Store', 1700000, 750000]] as $i => $c) {
    $branch = $i === 4 ? $B : $A;
    $clients[$i] = insert('clients', ['branch_id' => $branch, 'client_no' => make_number('client_prefix', 'client', false), 'full_name' => $c[0], 'gender' => $c[1], 'dob' => $c[2], 'nin' => $c[3], 'phone' => $c[4], 'village' => $c[5], 'district' => $c[6],
        'occupation' => $c[7], 'business_name' => $c[8] ?: null, 'monthly_income' => $c[9], 'monthly_expenses' => $c[10], 'status' => 'Active', 'registered_by' => nu($i === 4 ? 'log' : 'lo'), 'registered_at' => now()]);
}
$g1 = insert('guarantors', ['branch_id' => $A, 'guarantor_no' => make_number('guarantor_prefix', 'guarantor', false), 'surname' => 'Nabirye', 'first_name' => 'Joyce', 'gender' => 'Female', 'phone' => '0701555001', 'nin' => 'CF75011111NAJO', 'district' => 'Kampala',
    'housing_status' => 'Owner', 'marital_status' => 'Married', 'spouse_name' => 'Nabirye Sam', 'spouse_phone' => '0701555002', 'nok_name' => 'Nabirye Sam', 'nok_phone' => '0701555002', 'created_by' => nu('lo'), 'created_at' => now()]);

$terms = ['rate_pct' => '20', 'processing_pct' => '3', 'application_fee' => '10000', 'inspection_fee' => '10000', 'other_fees' => '0', 'fees_deducted' => '1', 'months' => 1];

function newLoan(string $off, string $mgr, int $branch, int $cid, int $amount, string $purpose, string $date, array $terms, bool $approve = true): int
{
    as_($off, $branch);
    $lid = Loans::saveApplication(['client_id' => $cid, 'amount_requested' => (string)$amount, 'purpose' => $purpose, 'application_date' => $date, 'monthly_income' => '1500000', 'monthly_expenses' => '700000']);
    Loans::submit($lid);
    Loans::recordAssessment($lid, ['recommendation' => 'Approve', 'recommended_amount' => (string)$amount, 'income' => '1500000', 'expenses' => '700000', 'existing_obligations' => '0', 'notes' => 'Stable business, good references.']);
    if ($approve) { as_($mgr, $branch); Loans::approve($lid, ['approved_principal' => (string)$amount] + $terms); }
    return $lid;
}

/** One closed business day in Kampala: FO opens, cashier works, FO reconciles / BM reviews, FO submits, BM approves. */
function runDayA(string $date, callable $fn, int $A, bool $close = true): void
{
    as_('fo', $A); Eod::openDay($date, true, $A);
    as_('cashier', $A);
    $sid = Cash::openSession((int)Cash::lastClosingCash(nu('cashier')), true, 'Carried forward');
    Cash::transfer('from_account', 3000000, safe($A), null, 'Operating cash from safe');
    $fn($sid);
    if (!$close) return;
    as_('cashier', $A); Cash::submitCount($sid, Cash::expected($sid), '');
    as_('fo', $A); Cash::reconcile($sid, '');
    Eod::submit($date, 'Routine close');
    as_('bm', $A); Eod::approve($date, 'Reconciled and approved');
}

$D0 = wd(date('Y-m-d', strtotime('-45 days')));
$D1 = wd(date('Y-m-d', strtotime('-30 days')));
$D2 = wd(date('Y-m-d', strtotime('-8 days')));
$D3 = wd(date('Y-m-d'));

// ---- Day 0 (Kampala): three loans approved, accepted, disbursed
as_('fo', $A); Eod::openDay($D0, true, $A);
$loanA = newLoan('lo', 'bm', $A, $clients[0], 1000000, 'Stock for the market stall', $D0, $terms);
$loanB = newLoan('lo', 'bm', $A, $clients[1], 2000000, 'Buy a new motorcycle', $D0, $terms);
$loanC = newLoan('lo', 'bm', $A, $clients[2], 500000, 'Salon equipment', $D0, $terms);
as_('lo', $A);
foreach ([$loanA, $loanB, $loanC] as $lid) Loans::recordAcceptance($lid, true, $D0, 'Signed offer form');
foreach ([[$loanA, 'Land title', 'Plot 12 Kisenyi, title deed', 4500000], [$loanB, 'Motorcycle', 'Bajaj Boxer, UBB 123X', 3200000]] as [$lid, $type, $desc, $val]) {
    $cl = (int)val('SELECT client_id FROM loans WHERE id = ?', [$lid]);
    insert('loan_guarantors', ['loan_id' => $lid, 'guarantor_id' => $g1, 'client_id' => $cl, 'guarantee_date' => $D0, 'guarantee_amount' => 1000000, 'declaration_accepted' => 1, 'pledge_status' => 'Pledged', 'status' => 'Active', 'created_by' => nu('lo'), 'created_at' => now()]);
    $sid = insert('securities', ['security_no' => make_number('security_prefix', 'security', false), 'loan_id' => $lid, 'client_id' => $cl, 'owner_type' => 'Borrower', 'type' => $type, 'description' => $desc, 'est_value' => $val,
        'status' => 'Pledged', 'date_pledged' => $D0, 'storage_location' => 'Office safe', 'created_by' => nu('lo'), 'created_at' => now()]);
    insert('security_history', ['security_id' => $sid, 'old_status' => null, 'new_status' => 'Pledged', 'changed_by' => nu('lo'), 'changed_at' => now(), 'note' => 'Registered']);
}
as_('cashier', $A);
$sid0 = Cash::openSession(0, true);
Cash::transfer('from_account', 4000000, safe($A), null, 'Cash for disbursements');
foreach ([$loanA, $loanB, $loanC] as $lid) Loans::disburse($lid, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => nu('bm')]);
Cash::submitCount($sid0, Cash::expected($sid0), '');
as_('fo', $A); Cash::reconcile($sid0, '');
Eod::submit($D0, 'First day');
as_('bm', $A); Eod::approve($D0, 'First day closed');

// ---- Day 1: repayments (A in full, B partial)
runDayA($D1, function ($sid) use ($A, $loanA, $loanB) {
    as_('cashier', $A);
    Payments::record(['loan_id' => $loanA, 'amount' => '1200000', 'method' => 'Cash', 'txn_token' => 'seed-a1']);
    Payments::record(['loan_id' => $loanB, 'amount' => '1000000', 'method' => 'Mobile Money', 'reference' => 'MM-884201', 'txn_token' => 'seed-b1']);
    Cash::addExpense(['category_id' => (int)val("SELECT id FROM expense_categories WHERE name = 'Transport'"), 'description' => 'Fuel for field visits', 'amount' => '60000', 'method' => 'Cash', 'txn_token' => 'seed-e1']);
}, $A);

// ---- Day 2: new loan D disbursed; a small till shortage the Branch Manager reconciles
as_('fo', $A); Eod::openDay($D2, true, $A);
$loanD = newLoan('lo', 'bm', $A, $clients[3], 1500000, 'Timber and tools', $D2, $terms);
as_('lo', $A); Loans::recordAcceptance($loanD, true, $D2);
as_('cashier', $A);
$sid2 = Cash::openSession((int)Cash::lastClosingCash(nu('cashier')), true, 'Carried forward');
Cash::transfer('from_account', 3000000, safe($A), null, 'Operating cash from safe');
Loans::disburse($loanD, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => nu('bm')]);
Cash::submitCount($sid2, Cash::expected($sid2) - 5000, 'Small shortage - coins miscounted at the counter');
as_('bm', $A); Cash::reconcile($sid2, 'Shortage UGX 5,000 noted; counted twice, cashier cautioned.');
as_('fo', $A); Eod::submit($D2);
as_('bm', $A); Eod::approve($D2, 'Approved with a recorded UGX 5,000 variance (within the Branch Manager limit)');

// ---- Today: Kampala left open at various stages
as_('fo', $A); Eod::openDay($D3, true, $A);
$loanE = newLoan('lo', 'bm', $A, $clients[5], 800000, 'Shop restocking', $D3, $terms);          // approved, awaiting acceptance
as_('lo', $A);
$loanF = Loans::saveApplication(['client_id' => $clients[0], 'amount_requested' => '1200000', 'purpose' => 'Second stall', 'application_date' => $D3, 'monthly_income' => '1700000', 'monthly_expenses' => '750000']);
Loans::submit($loanF);
Loans::recordAssessment($loanF, ['recommendation' => 'Approve', 'recommended_amount' => '1200000', 'income' => '1700000', 'expenses' => '750000']);   // under review
$loanBig = Loans::saveApplication(['client_id' => $clients[3], 'amount_requested' => '4500000', 'purpose' => 'Workshop expansion', 'application_date' => $D3, 'monthly_income' => '2000000', 'monthly_expenses' => '800000']);
Loans::submit($loanBig);
Loans::recordAssessment($loanBig, ['recommendation' => 'Approve', 'recommended_amount' => '4500000', 'income' => '2000000', 'expenses' => '800000']);   // above BM limit -> COO
Loans::saveApplication(['client_id' => $clients[2], 'amount_requested' => '600000', 'purpose' => 'Hair products', 'application_date' => $D3]);          // draft
as_('cashier', $A);
Cash::openSession((int)Cash::lastClosingCash(nu('cashier')), true, 'Carried forward');
Cash::transfer('from_account', 1000000, safe($A), null, 'Float top-up');
Payments::record(['loan_id' => $loanD, 'amount' => '400000', 'method' => 'Cash', 'txn_token' => 'seed-d1']);
$pBank = Payments::record(['loan_id' => $loanB, 'amount' => '300000', 'method' => 'Bank Transfer', 'reference' => 'BK-77120', 'txn_token' => 'seed-b2']);
Cash::addExpense(['category_id' => (int)val("SELECT id FROM expense_categories WHERE name = 'Stationery'"), 'description' => 'Receipt books and toner', 'amount' => '85000', 'method' => 'Cash', 'txn_token' => 'seed-e2']);
Cash::addExpense(['category_id' => (int)val("SELECT id FROM expense_categories WHERE name = 'Rent'"), 'description' => 'Office rent - month', 'amount' => '900000', 'method' => 'Bank Transfer', 'reference' => 'RENT-OCT', 'txn_token' => 'seed-e3']);   // above BM limit -> COO
as_('fo', $A);
Payments::requestReversal($pBank, 'Reference typed against the wrong loan');                       // waits for the Branch Manager
as_('bm', $A);
Loans::escalate($loanBig, 'Above my approval limit');

// ---- Gulu branch: its own business date and one active loan
as_('fog', $B); Eod::openDay($D3, true, $B);
$loanG = newLoan('log', 'bmg', $B, $clients[4], 900000, 'Sewing machine and fabric', $D3, $terms);
as_('log', $B); Loans::recordAcceptance($loanG, true, $D3);
as_('cashierg', $B);
Cash::openSession(0, true);
Cash::transfer('from_account', 1500000, safe($B), null, 'Opening cash');
Loans::disburse($loanG, ['method' => 'Cash', 'doc_verified' => 1, 'client_ack' => 1, 'authorized_by' => nu('bmg')]);
Payments::record(['loan_id' => $loanG, 'amount' => '150000', 'method' => 'Cash', 'txn_token' => 'seed-g1']);

as_('bm', $A);
Loans::refreshStatuses();
as_('admin');
Backup::create('Manual');

echo "\nDemo data created.\n";
echo "Kampala business date open: $D3 · Gulu business date open: $D3\n";
echo "Accounts (password in this file): admin, coo, bm, fo, lo, cashier (Kampala) · bmg, fog, log, cashierg (Gulu)\n";
echo "Overdue loans: " . (int)val("SELECT COUNT(*) FROM loans WHERE status = 'Overdue'") . ", active: " . (int)val("SELECT COUNT(*) FROM loans WHERE status = 'Active'") . ", closed: " . (int)val("SELECT COUNT(*) FROM loans WHERE status = 'Closed'") . "\n";
$issues = Backup::integrityReport();
echo $issues ? 'INTEGRITY ISSUES: ' . json_encode($issues) . "\n" : "Integrity check: clean\n";
