<?php
/**
 * End-to-end smoke flows over HTTP, as real users: an administrator creates a new profile for each role, the new people sign in
 * (forced password change), and each performs the work of their role through the actual screens and buttons.
 *
 *   php tests/flows.php http://127.0.0.1:8097 [admin_user admin_pass]
 *
 * Needs a throw-away database seeded with `php tools/seed_demo.php` (the demo admin is "admin"). Never run against production.
 */
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/http.php';
$base = $argv[1] ?? 'http://127.0.0.1:8097';
$adminUser = $argv[2] ?? 'siteadmin'; $adminPass = $argv[3] ?? 'Admin-pass-2026x';
$sfx = '_' . substr(md5((string)microtime(true)), 0, 4);
$TEMP = 'Temp-pass-2026x'; $NEW = 'Brand-new-pass-77a';
$pass = 0; $fail = 0; $failures = [];
function step(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; $failures[] = $name; echo "  FAIL $name" . ($info !== '' ? "  -> $info" : '') . "\n"; }
}
function flashOk(Web $w, string $needle = ''): bool
{
    foreach ($w->flash() as [$cls, $txt]) if ($cls === 'ok' && ($needle === '' || stripos($txt, $needle) !== false)) return true;
    return false;
}
function info(Web $w): string { return $w->lastUrl . ' ' . $w->flashText() . (preg_match('~<h2>Notice</h2><p>([^<]*)~', $w->body, $m) ? ' NOTICE: ' . $m[1] : ''); }

// ================================================================= A. the administrator creates a new profile for every role
echo "\n== Administrator creates new profiles\n";
$admin = new Web($base);
step('administrator signs in', $admin->login($adminUser, $adminPass), $admin->flashText());
$admin->get($admin->url('users', ['a' => 'new']));
preg_match('~name="branches\[\]" value="(\d+)"> Gulu~', $admin->body, $gm); $B = (int)($gm[1] ?? 0);
step('the new-user form offers the Gulu branch', $B > 0);
$people = []; foreach (['flo' => ['Flow Loan Officer', 'Loan Officer'], 'ffo' => ['Flow Finance Officer', 'Finance Officer'], 'fbm' => ['Flow Branch Manager', 'Branch Manager'], 'fcash' => ['Flow Cashier', 'Cashier'], 'ffm' => ['Flow Finance Manager', 'Finance Manager']] as $k => $v) $people[$k . $sfx] = $v;
foreach ($people as $u => [$name, $role]) {
    $admin->get($admin->url('users', ['a' => 'new']));
    $admin->submit('a=create', ['full_name' => $name, 'username' => $u, 'phone' => '0700000' . rand(100, 999), 'email' => "$u@example.test", 'role' => $role, 'password' => $TEMP, 'branches[]' => [(string)$B], 'primary_branch' => (string)$B, 'must_change' => '1']);
    step("administrator creates the $role profile ($u)", flashOk($admin, 'User created'), info($admin));
}
$admin->get($admin->url('users'));
step('the user list shows the new staff IDs', substr_count($admin->body, 'EF-S-') >= 5 || preg_match('~[A-Z]{2,6}-S-\d{4}~', $admin->body) === 1);

// ================================================================= each new person signs in
echo "\n== New profiles sign in\n";
$W = [];
foreach ($people as $u => [$name, $role]) {
    $W[$u] = new Web($base);
    $ok = $W[$u]->login($u, $TEMP, $NEW);
    step("$u: forced password change, then signed in", $ok, info($W[$u]));
    $W[$u]->get($W[$u]->url('dashboard'));
    step("$u: dashboard loads with an unlocked menu", $W[$u]->code === 200 && !str_contains($W[$u]->body, 'nav-link disabled') && str_contains($W[$u]->body, 'Good '), info($W[$u]));
}

// the Finance Officer starts the branch's business day
echo "
== Finance Officer: start the business day
";
$fo = $W['ffo' . $sfx];
$fo->get($fo->url('eod'));
$fo->submit('a=open', ['allow_nonworking' => '1']);
step('open the branch business day', flashOk($fo, 'open'), info($fo));

// ================================================================= B. loan officer: client -> application
echo "\n== Loan Officer: register a client and prepare a loan\n";
$lo = $W['flo' . $sfx];
$lo->get($lo->url('clients', ['a' => 'new']));
$nin = 'CM' . rand(10000000, 99999999) . 'ZZ';
$lo->submit('a=save', ['full_name' => 'Flow Client' . $sfx, 'gender' => 'Female', 'dob' => '1990-05-05', 'nin' => $nin, 'phone' => '07' . rand(10000000, 99999999), 'address' => 'Plot 1 Gulu', 'district' => 'Gulu', 'occupation' => 'Trader', 'monthly_income' => '2,000,000', 'monthly_expenses' => '800,000']);
step('register a client', flashOk($lo, 'registered'), info($lo));
preg_match('~[?&]id=(\d+)~', $lo->lastUrl, $m); $clientId = (int)($m[1] ?? 0);
step('the client profile opens', $clientId > 0 && str_contains(strip_tags($lo->body), 'Flow Client' . $sfx), info($lo));
foreach (['overview', 'loans', 'payments', 'guarantors', 'securities'] as $tab) { $lo->get($lo->url('clients', ['a' => 'view', 'id' => $clientId, 'tab' => $tab])); step("client profile tab: $tab", $lo->code === 200 && !str_contains($lo->body, 'Something went wrong'), info($lo)); }
$lo->get($lo->url('guarantors', ['a' => 'new']));
$lo->submit('a=save', ['surname' => 'Guarantor', 'first_name' => 'Flow', 'phone' => '07' . rand(10000000, 99999999), 'nin' => 'CF' . rand(10000000, 99999999) . 'GG', 'address' => 'Gulu', 'gender' => 'Male']);
step('register a guarantor', flashOk($lo, 'egistered') || flashOk($lo, 'saved') || flashOk($lo), info($lo));
$lo->get($lo->url('loans', ['a' => 'new']));
step('the new application offers a search to attach an existing client', str_contains($lo->body, 'data-client-q') && str_contains($lo->body, 'Attach an existing client'), info($lo));
$cl = json_decode($lo->get($lo->url('clientlookup', ['q' => 'Flow Client'])), true);
step('the client search finds the existing client', is_array($cl) && count($cl) >= 1 && str_contains($cl[0]['name'], 'Flow Client') && str_contains($cl[0]['url'], 'client_id='), json_encode($cl));
$dist = json_decode($lo->get($lo->url('geo', ['level' => 'district'])), true);
step('the address drop-down lists the districts of Uganda', is_array($dist) && count($dist) >= 130 && in_array('Gulu', $dist, true) && in_array('Wakiso', $dist, true), 'count ' . count((array)$dist));
$cty = json_decode($lo->get($lo->url('geo', ['level' => 'county', 'district' => 'Gulu'])), true);
$sub = is_array($cty) && $cty ? json_decode($lo->get($lo->url('geo', ['level' => 'subcounty', 'district' => 'Gulu', 'county' => $cty[0]])), true) : [];
$par = !empty($sub) ? json_decode($lo->get($lo->url('geo', ['level' => 'parish', 'district' => 'Gulu', 'county' => $cty[0], 'subcounty' => $sub[0]])), true) : [];
$vil = !empty($par) ? json_decode($lo->get($lo->url('geo', ['level' => 'village', 'district' => 'Gulu', 'county' => $cty[0], 'subcounty' => $sub[0], 'parish' => $par[0]])), true) : [];
step('Gulu opens down to counties, sub-counties, parishes and villages', !empty($cty) && !empty($sub) && !empty($par) && !empty($vil), json_encode([count((array)$cty), count((array)$sub), count((array)$par), count((array)$vil)]));
$lo->get($lo->url('clients', ['a' => 'new']));
step('the client form has linked address drop-downs and a profession list', str_contains($lo->body, 'data-geo-level="district"') && str_contains($lo->body, 'data-geo-level="village"') && str_contains($lo->body, 'dl_professions') && str_contains($lo->body, 'Boda boda rider'), info($lo));
$lo->get($lo->url('dashboard'));
step('Guarantors is no longer a separate menu item', !preg_match('~class="nav-link[^"]*"[^>]*href="[^"]*p=guarantors"~', $lo->body), info($lo));
$lo->get($lo->url('loans', ['a' => 'new', 'client_id' => $clientId]));
$lo->submit('a=save', ['client_id' => (string)$clientId, 'amount_requested' => '1,000,000', 'period_months' => '1', 'purpose' => 'Stock', 'monthly_income' => '2,000,000', 'monthly_expenses' => '800,000', 'frequency' => 'monthly']);
step('create a loan application (draft)', flashOk($lo), info($lo));
preg_match('~[?&]id=(\d+)~', $lo->lastUrl, $m); $loanId = (int)($m[1] ?? 0);
step('the loan page opens', $loanId > 0 && str_contains($lo->body, 'BLM-'), info($lo));
$forms = array_map(fn($f) => $f['action'], $lo->forms());
echo '       loan page forms: ' . implode(' | ', $forms) . "\n";

$lo->submit('a=submit');
step('the submission is refused until the client KYC is verified', str_contains($lo->flashText(), 'cannot be submitted yet') && str_contains($lo->flashText(), 'National ID'), info($lo));
$lo->get($lo->url('loan', ['id' => $loanId]));
step('the loan page shows the stage tracker with the blocking reason', str_contains($lo->body, 'Application workflow') && str_contains($lo->body, 'wf-issues'), info($lo));
$kycPng = sys_get_temp_dir() . '/flow_kyc_' . getmypid() . '.png'; file_put_contents($kycPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$lo->get($lo->url('clients', ['a' => 'view', 'id' => $clientId, 'tab' => 'kyc']));
step('the client KYC tab lists the required document', str_contains($lo->body, 'National ID') && str_contains($lo->body, 'Client KYC profile'), info($lo));
$lo->submit('a=kyc_upload', ['kind' => 'national_id', 'doc' => new CURLFile($kycPng, 'image/png', 'national_id.png')]);
step('the loan officer uploads the national ID', flashOk($lo, 'awaits verification'), info($lo));
$lo->get($lo->url('clients', ['a' => 'view', 'id' => $clientId, 'tab' => 'kyc']));
step('the loan officer cannot verify documents', !str_contains($lo->body, 'a=kyc_doc'), info($lo));
$bmk = $W['fbm' . $sfx];
$fo->get($fo->url('clients', ['a' => 'view', 'id' => $clientId, 'tab' => 'kyc']));
step('in the two-role process the Finance Officer does not verify KYC', !str_contains($fo->body, 'a=kyc_doc') && !str_contains($fo->body, 'a=kyc_verify'), info($fo));
$bmk->get($bmk->url('clients', ['a' => 'view', 'id' => $clientId, 'tab' => 'kyc']));
$bmk->submitWhere('a=kyc_doc', [], ['decision' => 'verify']);
step('the Branch Manager verifies the document', flashOk($bmk, 'verified'), info($bmk));
$bmk->submit('a=kyc_verify', ['decision' => 'verify', 'note' => 'ID checked']);
step('the Branch Manager verifies the client KYC profile', flashOk($bmk, 'verified'), info($bmk));
@unlink($kycPng);
$lo->get($lo->url('loan', ['id' => $loanId]));
step('the tracker now shows the KYC stage complete', str_contains($lo->body, 'wf-s done'), info($lo));
$lo->submit('a=submit');
step('submit the application for review', flashOk($lo), info($lo));
$lo->get($lo->url('assessment', ['id' => $loanId]));
step('the credit assessment page opens', $lo->code === 200 && !str_contains($lo->body, 'Something went wrong'), info($lo));
echo '       assessment forms: ' . implode(' | ', array_map(fn($f) => $f['action'] . ' {' . implode(',', $f['names']) . '}', $lo->forms())) . "\n";

$lo->submit('p=assessment&id=' . $loanId, ['recommendation' => 'Approve', 'recommended_amount' => '1,000,000', 'income' => '2,000,000', 'expenses' => '800,000', 'existing_obligations' => '0']);
step('record the credit assessment and recommendation', flashOk($lo), info($lo));

// ================================================================= C. branch manager approves
echo "\n== Branch Manager: approve the application\n";
$bm = $W['fbm' . $sfx];
$bm->get($bm->url('approvals'));
step('the approvals queue lists the application', $bm->code === 200 && str_contains($bm->body, 'Flow Client'), info($bm));
foreach (['documents' => 'Documents attached', 'guarantors' => 'Guarantors', 'security' => 'Security'] as $rt => $rl) {
    $bm->get($bm->url('approvals', ['id' => $loanId, 'rtab' => $rt]));
    step("the review has a tab to check: $rl", $bm->code === 200 && str_contains($bm->body, $rl) && str_contains($bm->body, 'rtab=decision') === false && !str_contains($bm->body, 'Something went wrong'), info($bm));
}
$bm->get($bm->url('approvals', ['id' => $loanId]));
step('the approval screen opens', $bm->code === 200 && !str_contains($bm->body, 'Something went wrong'), info($bm));
$bm->submitByField('approved_principal', ['approved_principal' => '1,000,000', 'months' => '1', 'rate_pct' => '20', 'processing_pct' => '3']);
step('approve the loan', flashOk($bm), info($bm));
$bm->get($bm->url('loan', ['id' => $loanId]));
step('the approved loan shows status Approved', str_contains($bm->body, 'Approved'), info($bm));
echo '       loan forms (manager): ' . implode(' | ', array_map(fn($f) => $f['action'] . ' {' . implode(',', $f['names']) . '}', $bm->forms())) . "\n";
$cash = $W['fcash' . $sfx];
$cash->get($cash->url('till'));
step('the cashier till page opens', $cash->code === 200 && !str_contains($cash->body, 'Something went wrong'), info($cash));
echo '       till forms: ' . implode(' | ', array_map(fn($f) => $f['action'] . ' {' . implode(',', $f['names']) . '}', $cash->forms())) . "\n";
$bm->get($bm->url('loan', ['id' => $loanId]));
$bm->submit('a=accept', ['decision' => 'accept', 'note' => 'accepted in branch']);
step('record the client\'s acceptance of the offer', flashOk($bm, 'acceptance'), info($bm));
$cash->get($cash->url('disbursements', ['id' => $loanId]));
step('in the two-role process the Cashier cannot release loans', $cash->code === 403 || !str_contains($cash->body, 'authorized_by'), info($cash));
$admin->get($admin->url('process'));
step('the administrator opens Loan Process Roles', $admin->code === 200 && str_contains($admin->body, 'Who does what') && str_contains($admin->body, 'Two roles: Loan Officer + Branch Manager'), info($admin));
$admin->submitWhere('a=preset', ['preset' => 'separate']);
step('the administrator switches to separated duties (cashier releases funds)', flashOk($admin, 'Profile applied'), info($admin));
$cash->get($cash->url('till'));
$cash->submit('a=open', ['opening_float' => '5,000,000', 'confirmed' => '1']);
step('the cashier opens a till with an opening float', flashOk($cash), info($cash));
$cash->get($cash->url('disbursements', ['id' => $loanId]));
step('the disbursement screen opens', $cash->code === 200 && !str_contains($cash->body, 'Something went wrong'), info($cash));
$cash->submitByField('authorized_by', ['authorized_by' => (string)$cash->optionValue('Flow Branch Manager'), 'doc_verified' => '1', 'client_ack' => '1', 'method' => 'Cash']);
step('the cashier releases the approved loan (voucher issued)', str_contains($cash->lastUrl, 'doc=voucher') || flashOk($cash, 'disbursed'), info($cash));
$bm->get($bm->url('loan', ['id' => $loanId]));
step('the loan is now Active with a repayment schedule', str_contains($bm->body, 'Active') && str_contains($bm->body, 'Repayment schedule'), info($bm));
$admin->get($admin->url('process'));
$admin->submitWhere('a=preset', ['preset' => 'two_role']);
step('the administrator returns to the two-role process', flashOk($admin, 'Profile applied'), info($admin));

// ================================================================= D. collections
echo "\n== Cashier: receive a repayment\n";
$cash->get($cash->url('payments', ['loan_id' => $loanId]));
step('the payment screen opens for the loan', $cash->code === 200 && !str_contains($cash->body, 'Something went wrong'), info($cash));
$cash->submit('a=pay', ['amount' => '300,000', 'method' => 'Cash']);
step('record a cash repayment', flashOk($cash) || str_contains($cash->lastUrl, 'receipt'), info($cash));
$cash->get($cash->url('receipts'));
step('the receipt register lists the receipt', $cash->code === 200 && str_contains($cash->body, 'Flow Client'), info($cash));
$cash->get($cash->url('till'));
echo '       till forms (open session): ' . implode(' | ', array_map(fn($f) => $f['action'] . ' {' . implode(',', $f['names']) . '}', $cash->forms())) . "\n";
foreach (['sessions', 'transfers', 'movements'] as $tb) { $cash->get($cash->url('till', ['tab' => $tb])); step("till tab: $tb", $cash->code === 200 && !str_contains($cash->body, 'Something went wrong'), info($cash)); }
$cash->get($cash->url('cashbook', ['tab' => 'new']));
$cash->submit('a=add', ['description' => 'Stationery', 'amount' => '20,000', 'method' => 'Cash', 'category_id' => (string)$cash->optionValue('Stationery')]);
step('record a small expense', flashOk($cash), info($cash));
$cash->get($cash->url('till'));
preg_match('~Expected[^0-9]{0,80}([\d,]{5,})~s', strip_tags($cash->body), $em);
$expected = (int)str_replace(',', '', $em[1] ?? '0');
step('the till shows the expected cash', $expected > 0, 'expected text not found');
$sess = 0; foreach ($cash->forms() as $fm) if (isset($fm['fields']['session_id']) && str_contains($fm['action'], 'a=count')) $sess = (int)$fm['fields']['session_id'];
$cash->submit('a=count', ['counted' => number_format($expected)]);
step('count the till and submit it for reconciliation', flashOk($cash), info($cash));

echo "\n== Finance Officer: reconcile the till, prepare End-of-Day\n";
$fo = $W['ffo' . $sfx];
$fo->get($fo->url('till', ['tab' => 'sessions']));
step('the sessions list opens', $fo->code === 200 && !str_contains($fo->body, 'Something went wrong'), info($fo));
$fo->get($fo->url('till', ['tab' => 'sessions', 'session' => $sess]));
$fo->submit('a=reconcile', ['notes' => 'Count verified']);
step('approve the cashier reconciliation', flashOk($fo, 'reconciled'), info($fo));
$fo->get($fo->url('eod'));
step('the End-of-Day screen opens', $fo->code === 200 && !str_contains($fo->body, 'Something went wrong'), info($fo));
$fo->get($fo->url('eod'));
step('finance sees that End of Day is run by the System Administrator (no submit button)', str_contains($fo->body, 'run once, by the System Administrator') && !str_contains($fo->body, 'a=submit'), info($fo));

echo "
== System Administrator: back up, then run End of Day (one step, one place)
";
$admin->get($admin->url('eodsys'));
step('the End of Day page shows each branch with its system date', $admin->code === 200 && str_contains($admin->body, 'Run End of Day') && str_contains($admin->body, 'System date'), info($admin));
preg_match('~data-eod(?: data-participant="1")? data-branch="' . $B . '" data-date="([^"]+)" data-datelabel="[^"]*" data-url="([^"]+)" data-csrf="([^"]+)"~', $admin->body, $em);
step('a ready branch offers one Run End of Day button with a progress runner', count($em) === 4 && str_contains($admin->body, 'eod-bar') && str_contains($admin->body, 'data-eod-all'), info($admin));
$eodStep = function (string $phase) use ($admin, $em, $B) { $admin->req(html_entity_decode($em[2]), ['_csrf' => $em[3], 'branch' => (string)$B, 'date' => $em[1], 'phase' => $phase, 'notes' => 'flow test close']); return json_decode($admin->body, true) ?: ['ok' => false, 'message' => substr($admin->body, 0, 120)]; };
$r = $eodStep('close');
step('the day cannot be closed before the backup', !$r['ok'] && str_contains($r['message'], 'backup first'), json_encode($r));
$allOk = true; $trace = [];
foreach (['check', 'backup', 'close', 'ledger', 'closing_backup', 'next', 'done'] as $ph) { $r = $eodStep($ph); $trace[] = $ph . ':' . ($r['ok'] ? 'ok' : 'FAIL ' . $r['message']); if (!$r['ok']) $allOk = false; }
step('run End of Day step by step: backup, close, ledger, closing backup, next day, notice', $allOk, implode(' | ', $trace));
$admin->get($admin->url('eodsys'));
preg_match('~data-eod(?: data-participant="1")? data-branch="' . $B . '" data-date="([^"]+)"~', $admin->body, $nd);
step('the system date moved to the next working day straight after End of Day', !empty($nd[1]) && $nd[1] > $em[1], 'before ' . $em[1] . ' after ' . ($nd[1] ?? 'none'));
$admin->get($admin->url('eodsys'));
step('the administrator sees the reset panel', str_contains($admin->body, 'Reset the End-of-Day position'), info($admin));
$admin->submitWhere('a=reset', ['branch' => (string)$B], ['reason' => 'flow test: closed by mistake', 'confirm' => '1']);
step('the administrator resets the End-of-Day position after an error', flashOk($admin, 'position reset'), info($admin));
$admin->get($admin->url('eodsys'));
preg_match('~data-eod(?: data-participant="1")? data-branch="' . $B . '" data-date="([^"]+)"~', $admin->body, $rd);
step('the system date went back to the reopened day', !empty($rd[1]) && $rd[1] === $em[1], 'expected ' . $em[1] . ' got ' . ($rd[1] ?? 'none'));
$em[1] = $rd[1] ?? $em[1];
$allOk2 = true; $trace2 = [];
foreach (['check', 'backup', 'close', 'ledger', 'closing_backup', 'next', 'done'] as $ph) { $r = $eodStep($ph); if (!$r['ok']) { $allOk2 = false; $trace2[] = $ph . ': ' . $r['message']; } }
step('End of Day runs again cleanly after the reset', $allOk2, implode(' | ', $trace2));
$bmk->get($bmk->url('till', ['tab' => 'accounts']));
step('the Branch Manager sees the safe, bank and mobile account balances', $bmk->code === 200 && str_contains($bmk->body, 'Balances held outside the tills') && str_contains($bmk->body, 'Record an entry'), info($bmk));
$bmk->submit('a=fund', ['account_id' => (string)$bmk->optionValue('Bank Account'), 'kind' => 'opening', 'amount' => '5,000,000', 'reason' => 'Opening balance per statement']);
step('the Branch Manager records an opening bank balance', flashOk($bmk, 'Recorded') || str_contains($bmk->flashText(), 'ok'), info($bmk));
$bm->get($bm->url('eod'));
step('the branch manager sees the new system date and no approval step', $bm->code === 200 && !str_contains($bm->body, 'a=approve'), info($bm));
$bm->get($bm->url('dashboard'));
step('the system date is shown under the profile', str_contains($bm->body, 'System date'), info($bm));

// the General Ledger was posted by End of Day
$fo = $W['ffo' . $sfx];
$fo->get($fo->url('ledger'));
step('the Finance Officer opens the General Ledger and sees the chart of accounts', $fo->code === 200 && str_contains($fo->body, 'Loans Receivable'), info($fo));
$fo->get($fo->url('ledger', ['a' => 'journals', 'from' => '2000-01-01', 'to' => '2099-12-31']));
step('End of Day posted journals to the ledger', $fo->code === 200 && str_contains($fo->body, 'GL-0000'), info($fo));
$fo->get($fo->url('reports', ['r' => 'FN-12']));
step('FN-12 shows the trial balance and it agrees', $fo->code === 200 && str_contains($fo->body, 'trial balance agrees'), info($fo));
$bm->get($bm->url('ledger'));
step('a Branch Manager has no General Ledger access', $bm->code === 403 || !str_contains($bm->body, 'Chart of accounts'), info($bm));

// ================================================================= E. day-to-day screens for every profile
echo "\n== Every profile: the screens in their menu open and their filters work\n";
foreach ($W as $u => $web) {
    $web->get($web->url('dashboard'));
    preg_match_all('~<a class="nav-link[^"]*" href="([^"]+)"~', $web->body, $mm);
    $bad = [];
    foreach (array_unique($mm[1]) as $h) {
        $h = html_entity_decode($h); if (str_contains($h, 'p=logout')) continue;
        $web->get($h);
        if ($web->code >= 400 || str_contains($web->body, 'Something went wrong') || preg_match('~<h2>Notice</h2><p>[^<]*(permission|does not exist)~', $web->body)) $bad[] = preg_replace('~^.*p=~', '', $h);
    }
    step("$u: all " . count(array_unique($mm[1])) . ' menu items open', !$bad, implode(', ', $bad));
}
$lo->get($lo->url('search', ['q' => 'Flow']));
step('global search finds the new client', str_contains($lo->body, 'Flow Client'), info($lo));
$lo->get($lo->url('reports'));
step('the Report Centre lists reports for the new Loan Officer', str_contains($lo->body, 'LP-02') && !str_contains($lo->body, 'FN-01'), info($lo));
foreach (['LP-02', 'CL-01', 'LA-01', 'AR-02', 'GC-02'] as $rid) { $lo->get($lo->url('reports', ['r' => $rid])); step("report $rid opens", $lo->code === 200 && str_contains($lo->body, 'Generated by'), info($lo)); }
$admin->get($admin->url('settings', ['tab' => 'workflow']));
step('the loan workflow rules are configurable in Settings', $admin->code === 200 && str_contains($admin->body, 'Documents required before an application can be submitted') && str_contains($admin->body, 'wf_min_guarantors'), info($admin));
foreach (['pdf', 'xlsx', 'csv'] as $dlf) {
    $ch = curl_init($lo->url('export', ['kind' => 'report', 'format' => $dlf, 'r' => 'LP-02']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $lo->jar, CURLOPT_FOLLOWLOCATION => true]);
    $resp = (string)curl_exec($ch); curl_close($ch);
    step("downloading LP-02 as $dlf saves a file named after the report", preg_match('~Content-Disposition: attachment; filename="Active_Loans_Register_LP-02_[^"]+\.' . $dlf . '"~i', $resp) === 1, substr($resp, 0, 300));
}
$lo->get($lo->url('reports', ['r' => 'FN-01']));
step('a finance report is refused to the Loan Officer', $lo->code === 403, 'status ' . $lo->code);
$ffo = $W['ffo' . $sfx];
foreach (['CR-01', 'FN-01', 'EOD-03', 'CR-06'] as $rid) { $ffo->get($ffo->url('reports', ['r' => $rid])); step("finance report $rid opens", $ffo->code === 200 && str_contains($ffo->body, 'Generated by'), info($ffo)); }
$ffo->get($ffo->url('export', ['kind' => 'report', 'format' => 'csv', 'r' => 'CR-01']));
step('a permitted report exports to CSV', $ffo->code === 200 && (str_contains($ffo->body, 'Business date') || str_contains($ffo->body, 'Payments')), info($ffo));
foreach (['csv' => 'Business date', 'pdf' => '%PDF-1.4', 'xlsx' => 'PK', 'doc' => '<table>', 'json' => '"report"'] as $fmt => $sig) {
    $ffo->get($ffo->url('export', ['kind' => 'report', 'format' => $fmt, 'r' => 'CR-01', 'preset' => 'year']));
    step("download a report as " . strtoupper($fmt), $ffo->code === 200 && str_contains(substr($ffo->body, 0, 4000), $sig), 'status ' . $ffo->code . ' starts: ' . substr($ffo->body, 0, 60));
}
$ffo->get($ffo->url('reports', ['r' => 'CR-01', 'preset' => 'month', 'q' => 'zzz-nothing']));
step('report filters (period preset + text search) apply on screen', $ffo->code === 200 && str_contains($ffo->body, 'Text:') && str_contains($ffo->body, 'Showing 0 of') || str_contains($ffo->body, '0 row'), info($ffo));
$lo->get($lo->url('export', ['kind' => 'report', 'format' => 'pdf', 'r' => 'FN-01']));
step('a download of a report the profile does not have is refused', $lo->code !== 200 || !str_starts_with($lo->body, '%PDF'), 'status ' . $lo->code);

// ================================================================= F. administrator: settings, branches, users, report access, backup
echo "\n== Administrator: settings, branches, users, report access, backup\n";
$admin->get($admin->url('settings', ['tab' => 'business']));
$admin->submit('a=save', ['business_tagline' => 'Flow test tagline', 'business_address' => 'Plot 9, Test Road', 'business_phone' => '+256700000001', 'business_email' => 'info@flowtest.example']);
step('save the institution profile', flashOk($admin, 'Settings saved'), info($admin));
$admin->get($admin->url('settings', ['tab' => 'business']));
step('the profile page shows the saved address in the letterhead preview', str_contains($admin->body, 'Plot 9, Test Road'), info($admin));
$lo->get($lo->url('reports', ['r' => 'LP-02']));
step('reports now print the new address automatically', str_contains($lo->body, 'Plot 9, Test Road') && str_contains($lo->body, 'Flow test tagline'), info($lo));
$admin->get($admin->url('settings', ['tab' => 'lending']));
$admin->submit('a=save', ['interest_rate_pct' => '18']);
step('change the interest rate', flashOk($admin, 'Settings saved'), info($admin));
$admin->get($admin->url('settings', ['tab' => 'lending']));
step('the new interest rate is stored', str_contains($admin->body, 'value="18"'), info($admin));
foreach (['numbering', 'penalty', 'payments', 'eod', 'security', 'email'] as $tb) {
    $admin->get($admin->url('settings', ['tab' => $tb]));
    $admin->submit('a=save');
    step("save settings tab: $tb", flashOk($admin, 'Settings saved'), info($admin));
}
$admin->get($admin->url('settings', ['tab' => 'limits']));
$admin->submit('a=limits', ['limit[loan][0]' => '5,000,000', 'limit[expense][0]' => '500,000']);
step('set approval limits', flashOk($admin, 'limits saved'), info($admin));
$admin->get($admin->url('settings', ['tab' => 'calendar']));
$admin->submit('a=holiday', ['date' => date('Y-12-25'), 'note' => 'Christmas', 'branch_id' => '0']);
step('add a holiday', flashOk($admin, 'Calendar'), info($admin));
$admin->get($admin->url('branches', ['a' => 'new']));
$admin->submit('a=create', ['code' => 'MBL' . substr($sfx, 1, 2), 'name' => 'Mbale Branch' . $sfx, 'location' => 'Mbale', 'phone' => '0700111222', 'collection_target' => '10,000,000']);
step('create a new branch', flashOk($admin, 'Branch created'), info($admin));
$admin->get($admin->url('users'));
preg_match('~p=users&a=edit&id=(\d+)~', preg_replace('~&amp;~', '&', $admin->body), $um);
$flo = array_keys($people)[0]; $floId = 0;
foreach ($W as $u => $_) { }
if (preg_match_all('~<a href="([^"]*a=edit&amp;id=(\d+))">([^<]*)</a>~', $admin->body, $ul, PREG_SET_ORDER)) foreach ($ul as $x) if (str_contains($x[3], '-S-') && $floId === 0) { }
$admin->get($admin->url('users', ['a' => 'edit', 'id' => (int)($um[1] ?? 0)]));
step('open a user for editing', $admin->code === 200 && str_contains($admin->body, 'Individual permissions'), info($admin));
$editUrl = $admin->url('users', ['a' => 'edit', 'id' => (int)($um[1] ?? 0)]);
$admin->submitByField('staff_no', ['phone' => '0700999000']);
step('edit a user profile (all fields)', flashOk($admin, 'User updated'), info($admin));
$admin->get($editUrl);
$admin->submit('a=reset');
step('send a password reset link by email (logged when SMTP is not set)', flashOk($admin) || str_contains($admin->flashText(), 'mail'), info($admin));
$admin->get($editUrl);
$admin->submit('a=reports', ['reason' => 'flow test']);
step('save per-user report access', flashOk($admin, 'Report access saved'), info($admin));
$admin->get($admin->url('reportaccess'));
$admin->submit('a=save', ['reason' => 'flow test']);
step('save the report access matrix', flashOk($admin), info($admin));
$admin->get($admin->url('backup'));
$admin->submit('a=create');
step('take a backup now', flashOk($admin) || str_contains($admin->flashText(), 'ackup'), info($admin));
$admin->get($admin->url('eodsys'));
step('the End of Day & System Date page opens for the administrator', $admin->code === 200 && str_contains($admin->body, 'Run End of Day'), info($admin));
foreach (['audit', 'security', 'system', 'branches', 'users'] as $pg) { $admin->get($admin->url($pg)); step("administrator page: $pg", $admin->code === 200 && !str_contains($admin->body, 'Something went wrong'), info($admin)); }

// ================================================================= G. corrections, follow-up, collateral, files
echo "\n== Corrections, recovery follow-up, collateral and documents\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pngFile = sys_get_temp_dir() . '/flow_' . getmypid() . '.png'; file_put_contents($pngFile, $png);
$file = fn() => new CURLFile($pngFile, 'image/png', 'photo.png');
$lo->get($lo->url('account'));
$lo->submit('a=photo', ['photo' => $file()]);
step('upload my own profile photo', flashOk($lo, 'photo'), info($lo));
$lo->get($lo->url('dashboard'));
preg_match('~<img class="pfp"[^>]*src="([^"]+)"~', $lo->body, $pm);
$img = $pm ? $lo->get(html_entity_decode($pm[1])) : '';
step('the photo is served back (top-bar avatar)', $pm && str_starts_with($img, "\x89PNG"), $pm ? 'not an image' : 'no avatar image in the page');
$lo->get($lo->url('clients', ['a' => 'view', 'id' => $clientId, 'tab' => 'admin']));
$lo->submit('a=photo', ['photo' => $file()]);
step('upload a client photograph', flashOk($lo), info($lo));
// a second client and application, to exercise guarantor, collateral and document screens
$lo->get($lo->url('clients', ['a' => 'new']));
$lo->submit('a=save', ['full_name' => 'Second Client' . $sfx, 'gender' => 'Male', 'dob' => '1985-02-02', 'nin' => 'CM' . rand(10000000, 99999999) . 'QQ', 'phone' => '07' . rand(10000000, 99999999), 'address' => 'Gulu', 'monthly_income' => '1,500,000', 'monthly_expenses' => '500,000']);
preg_match('~[?&]id=(\d+)~', $lo->lastUrl, $m); $c2 = (int)($m[1] ?? 0);
$lo->get($lo->url('loans', ['a' => 'new', 'client_id' => $c2]));
$lo->submit('a=save', ['client_id' => (string)$c2, 'amount_requested' => '500,000', 'period_months' => '1', 'purpose' => 'Seeds', 'frequency' => 'monthly']);
preg_match('~[?&]id=(\d+)~', $lo->lastUrl, $m); $loan2 = (int)($m[1] ?? 0);
step('create a second application', $loan2 > 0 && flashOk($lo), info($lo));
$lo->get($lo->url('loan', ['id' => $loan2, 'tab' => 'guarantors']));
$gid = $lo->optionValue('Guarantor');
$lo->submit('a=link_guarantor', ['guarantor_id' => (string)$gid, 'guarantee_amount' => '500,000', 'declaration_accepted' => '1']);
step('link a guarantor to the application', flashOk($lo), info($lo));
$lo->get($lo->url('loan', ['id' => $loan2, 'tab' => 'securities']));
$lo->submit('a=add_security', ['type' => 'Land title', 'description' => 'Plot 12 Gulu', 'est_value' => '3,000,000', 'owner_type' => 'Client']);
step('record collateral', flashOk($lo), info($lo));
$lo->get($lo->url('loan', ['id' => $loan2, 'tab' => 'documents']));
$lo->submit('a=upload', ['doc' => $file(), 'kind' => 'application_form']);
step('upload an application document', flashOk($lo), info($lo));
$lo->get($lo->url('loan', ['id' => $loan2, 'tab' => 'documents']));
step('the uploaded document is listed and downloadable', str_contains($lo->body, 'photo.png'), info($lo));
$lo->get($lo->url('loan', ['id' => $loan2]));
$lo->submit('a=cancel', ['reason' => 'client withdrew']);
step('cancel an application with a reason', flashOk($lo), info($lo));
// a new profile created WITH a photo: the person still has to choose a password, and the photo must show on that very first screen
$admin->get($admin->url('users', ['a' => 'new']));
$admin->submit('a=create', ['full_name' => 'Photo Person', 'username' => 'fphoto' . $sfx, 'phone' => '0700123456', 'email' => 'fphoto' . $sfx . '@example.test', 'role' => 'Loan Officer', 'password' => $TEMP, 'branches[]' => [(string)$B], 'primary_branch' => (string)$B, 'must_change' => '1', 'photo' => $file()]);
step('create a profile together with a photo', flashOk($admin, 'User created'), info($admin));
$ph = new Web($base);
$ph->login('fphoto' . $sfx, $TEMP);
step('first sign-in: every module is active, with a reminder to choose a password', str_contains($ph->body, 'pw-remind') && !str_contains($ph->body, 'nav-link disabled') && str_contains($ph->body, 'Dashboard'), info($ph));
$ph->get($ph->url('clients'));
step('the new profile can open Clients straight away', $ph->code === 200 && str_contains($ph->body, 'Clients') && !str_contains($ph->title(), 'My account'), info($ph));
preg_match('~<img class="pfp"[^>]*src="([^"]+)"~', $ph->body, $pm);
$img = $pm ? $ph->get(html_entity_decode($pm[1])) : '';
step('the photo already loads on that first screen', $pm && str_starts_with($img, "\x89PNG"), $pm ? 'not an image (redirected?)' : 'no photo in the page');
@unlink($pngFile);

echo "\n== Summary\n";
echo "Passed: $pass   Failed: $fail\n";
if ($failures) echo "Failures:\n - " . implode("\n - ", $failures) . "\n";
exit($fail ? 1 : 0);
