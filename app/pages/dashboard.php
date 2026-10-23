<?php
$title = 'Dashboard';
$business = can_any('client.view|loan.view|payment.view|portfolio.view');

// ------------------------------------------------------------------ System Administrator: technical dashboard only
if (!$business) {
    page_head('System dashboard', '', 'Technical health and security. The System Administrator has no access to client or financial records by default.');
    $h = System::health();
    echo quick_tiles([['settings', ['tab' => 'business'], 'Institution profile & logo', 'building', 'settings.manage'], ['users', ['a' => 'new'], 'Add a user', 'user-plus', 'user.manage'], ['users', [], 'Users & Staff IDs', 'users', 'user.manage'],
        ['branches', [], 'Branches', 'building', 'branch.manage'], ['settings', [], 'All settings', 'settings', 'settings.manage|limits.manage'], ['backup', [], 'Backups', 'database', 'backup.manage|backup.request'],
        ['security', [], 'Security monitoring', 'lock', 'security.monitor'], ['audit', [], 'Audit trail', 'eye', null]]);
    if (can('settings.manage')) {
        $list = System::setupChecklist(); $done = count(array_filter($list, fn($x) => $x[1]));
        if ($done < count($list)) {
            echo '<div class="card"><div class="card-h"><h2>Finish setting up your institution</h2><span class="muted">' . $done . ' of ' . count($list) . ' complete</span></div><div class="steps">';
            foreach ($list as [$label, $ok, $hint, $link, $ic]) echo '<a class="step' . ($ok ? ' done' : '') . '" href="' . e(url($link[0], $link[1])) . '"><span class="step-ic">' . icon($ok ? 'check' : $ic, 16) . '</span><span><b>' . e($label) . '</b><small>' . e($hint) . '</small></span></a>';
            echo '</div></div>';
        }
    }
    echo '<div class="kpis">'
        . kpi('Database', $h['db']['ok'] ? 'Online' : 'DOWN', $h['db']['engine'], $h['db']['ok'] ? 'ok' : 'bad')
        . kpi('File storage', $h['storage']['ok'] ? 'Writable' : 'PROBLEM', $h['storage']['driver'], $h['storage']['ok'] ? 'ok' : 'bad')
        . kpi('Last verified backup', $h['backup']['age_hours'] === null ? 'never' : $h['backup']['age_hours'] . ' h ago', $h['backup']['last'] ?? '', $h['backup']['ok'] ? 'ok' : 'bad')
        . kpi('Failed logins (24 h)', num($h['security']['failed_24h']), 'locked accounts: ' . $h['security']['locked'], $h['security']['failed_24h'] > 20 ? 'bad' : '')
        . kpi('Active users', num($h['users']['active']), 'MFA enrolled: ' . $h['users']['mfa'])
        . kpi('Branches', num($h['branches']['active']), 'active')
        . kpi('Pending migrations', num($h['migrations_pending']), '', $h['migrations_pending'] ? 'bad' : 'ok')
        . '</div>';
    if (!$h['mfa_ok']) echo '<div class="notice bad">Some users in roles that require multi-factor authentication have not enrolled yet: ' . e(implode(', ', $h['mfa_missing'])) . '.</div>';
    if (!$h['production_ready']) echo '<div class="notice">Production settings to review: ' . e(implode(' · ', $h['production_notes'])) . '</div>';
    echo '<p>' . btn('System health details', url('system')) . ' ' . btn('Security monitoring', url('security')) . ' ' . btn('Backups', url('backup')) . '</p>';
    return;
}

// ------------------------------------------------------------------ business dashboard (scoped to the user's branches)
$asOf = reporting_date();
$chosen = (int)get('branch');
$only = branch_filter_ids($chosen);
$BL = branch_scope('l.branch_id', $only);
$f = [
    'from' => get('from') ?: date('Y-m-01', strtotime($asOf)),
    'to' => get('to') ?: $asOf,
    'officer' => (int)get('officer'), 'cashier' => (int)get('cashier'), 'status' => get('status'), 'method' => get('method'),
];
if (!is_date($f['from'])) $f['from'] = date('Y-m-01', strtotime($asOf));
if (!is_date($f['to'])) $f['to'] = $asOf;
foreach ($only ?? accessible_branch_ids() as $bid) Loans::refreshStatuses(null, (int)$bid);

$lw = [$BL]; $lp = [];
if ($f['officer']) { $lw[] = 'l.officer_id = ?'; $lp[] = $f['officer']; }
if ($f['status'] !== '') { $lw[] = 'l.status = ?'; $lp[] = $f['status']; }
$LW = implode(' AND ', $lw);
$openLoans = "l.status IN ('Active','Overdue')";
$cnt = fn(string $extra, array $p = []) => (int)val("SELECT COUNT(*) FROM loans l WHERE $LW AND $extra", array_merge($lp, $p));

$pw = ["p.status = 'Posted'", 'p.business_date BETWEEN ? AND ?', branch_scope('p.branch_id', $only)]; $pp = [$f['from'], $f['to']];
if ($f['cashier']) { $pw[] = 'p.cashier_id = ?'; $pp[] = $f['cashier']; }
if ($f['method'] !== '') { $pw[] = 'p.method = ?'; $pp[] = $f['method']; }
if ($f['officer']) { $pw[] = 'l.officer_id = ?'; $pp[] = $f['officer']; }
$PW = implode(' AND ', $pw);
$collected = fn(string $comp = '') => (int)val("SELECT COALESCE(SUM(" . ($comp ? "CASE WHEN a.component = '$comp' THEN a.amount END" : "CASE WHEN a.component <> 'credit' THEN a.amount END") . "),0)
    FROM payment_allocations a JOIN payments p ON p.id = a.payment_id JOIN loans l ON l.id = p.loan_id WHERE $PW", $pp);

$BP = branch_scope('branch_id', $only);
$todayColl = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status='Posted' AND p.business_date = ? AND " . branch_scope('p.branch_id', $only), [$asOf]);
$monthColl = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status='Posted' AND p.business_date BETWEEN ? AND ? AND " . branch_scope('p.branch_id', $only), [date('Y-m-01', strtotime($asOf)), $asOf]);
$bal = row("SELECT COALESCE(SUM(b.principal_out),0) AS pr, COALESCE(SUM(b.interest_out),0) AS it, COALESCE(SUM(b.penalty_out),0) AS pn
            FROM v_loan_balances b JOIN loans l ON l.id = b.loan_id WHERE $LW AND $openLoans", $lp);
$disb = row("SELECT COALESCE(SUM(d.approved_principal),0) AS pr, COALESCE(SUM(d.processing_fee+d.application_fee+d.inspection_fee+d.other_deductions),0) AS fees
             FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE $LW AND d.business_date BETWEEN ? AND ?", array_merge($lp, [$f['from'], $f['to']]));
$feesPaid = $collected('fee');
$tillCash = (int)val("SELECT COALESCE(SUM(m.direction*m.amount),0) FROM cash_movements m JOIN cashier_sessions s ON s.id = m.session_id WHERE m.is_cash = 1 AND s.status IN ('Open','Submitted','Investigation') AND " . branch_scope('m.branch_id', $only));
$varSum = (int)val('SELECT COALESCE(SUM(variance),0) FROM cashier_sessions WHERE business_date BETWEEN ? AND ? AND ' . $BP . ($f['cashier'] ? ' AND cashier_id = ' . (int)$f['cashier'] : ''), [$f['from'], $f['to']]);
$bdNow = current_bd();
$sc = $bdNow ? Eod::sessionCounts($bdNow) : ['Open' => 0, 'Submitted' => 0, 'Investigation' => 0, 'Reconciled' => 0];
$lastEod = row("SELECT business_date FROM eod_records WHERE status = 'Closed' AND " . $BP . ' ORDER BY business_date DESC, version DESC LIMIT 1');
$awaitingEod = (int)val("SELECT COUNT(*) FROM eod_records e WHERE e.status IN ('Awaiting Manager Review','Escalated to COO') AND " . branch_scope('e.branch_id', $only) . ' AND e.version = (SELECT MAX(version) FROM eod_records x WHERE x.branch_id = e.branch_id AND x.business_date = e.business_date)');

$scope = $only ? branch_name($only[0]) : (count(accessible_branch_ids()) > 1 ? 'all your branches' : branch_name(branch_id()));
// ------------------------------------------------------------------ headline view (branch-scoped, ignores the filters below)
$mStart = date('Y-m-01', strtotime($asOf));
$pStart = date('Y-m-01', strtotime('-1 month', strtotime($mStart)));
$pEnd = date('Y-m-d', strtotime('-1 day', strtotime($mStart)));
$BLo = branch_scope('l.branch_id', $only);
$totClients = (int)val('SELECT COUNT(*) FROM clients WHERE ' . $BP);
$clientsPrev = (int)val('SELECT COUNT(*) FROM clients WHERE substr(registered_at,1,10) <= ? AND ' . $BP, [$pEnd]);
$activeLoans = (int)val("SELECT COUNT(*) FROM loans l WHERE $BLo AND l.status IN ('Active','Overdue')");
$overdueLoans = (int)val("SELECT COUNT(*) FROM loans l WHERE $BLo AND l.status = 'Overdue'");
$disbSum = fn(string $a, string $b) => (int)val("SELECT COALESCE(SUM(d.approved_principal),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE $BLo AND d.business_date BETWEEN ? AND ?", [$a, $b]);
$collSum = fn(string $a, string $b) => (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND " . branch_scope('p.branch_id', $only), [$a, $b]);
$disbNow = $disbSum($mStart, $asOf); $disbPrev = $disbSum($pStart, $pEnd);
$collNow = $collSum($mStart, $asOf); $collPrev = $collSum($pStart, $pEnd);
$trend = function (int $cur, int $prev): string {
    if ($prev <= 0) return '<div class="trend">' . ($cur > 0 ? '<i class="up">new</i> this month' : 'no change yet') . '</div>';
    $pc = round(($cur - $prev) / $prev * 100);
    return '<div class="trend"><i class="' . ($pc >= 0 ? 'up' : 'down') . '">' . ($pc >= 0 ? '↑ ' : '↓ ') . abs($pc) . '%</i> from last month</div>';
};
$first = explode(' ', trim(user()['full_name']))[0];
$hour = (int)date('G');
echo '<div class="welcome"><div><h1>' . ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening')) . ', ' . e($first) . '!</h1><p>Here’s what’s happening at ' . ($only ? e(branch_name($only[0])) : (count(accessible_branch_ids()) > 1 ? 'your branches' : 'your branch')) . ' today.</p></div>'
    . '<div class="when"><span>' . icon('calendar', 18) . e(date('D, j M Y', strtotime($asOf))) . '</span><span>' . icon('building', 18) . e($scope) . '</span></div></div>';
echo '<div class="stats">'
    . '<div class="stat"><span class="stat-ic">' . icon('users', 26) . '</span><div><b>' . num($totClients) . '</b><div class="sl">Total clients</div>' . $trend($totClients, $clientsPrev) . '</div></div>'
    . '<div class="stat"><span class="stat-ic">' . icon('clipboard', 26) . '</span><div><b>' . num($activeLoans) . '</b><div class="sl">Active loans</div><div class="trend">' . ($overdueLoans ? '<i class="down">' . $overdueLoans . ' overdue</i>' : '<i class="up">none overdue</i>') . '</div></div></div>'
    . '<div class="stat"><span class="stat-ic">' . icon('dollar', 26) . '</span><div><b>' . ugx($disbNow) . '</b><div class="sl">Disbursed this month</div>' . $trend($disbNow, $disbPrev) . '</div></div>'
    . '<div class="stat"><span class="stat-ic">' . icon('card', 26) . '</span><div><b>' . ugx($collNow) . '</b><div class="sl">Collected this month</div>' . $trend($collNow, $collPrev) . '</div></div>'
    . '</div>';

// collections trend (daily, current month) + loan status donut
$daily = [];
foreach (rows("SELECT p.business_date AS d, SUM(p.amount) AS s FROM payments p WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND " . branch_scope('p.branch_id', $only) . ' GROUP BY p.business_date', [$mStart, $asOf]) as $r) $daily[$r['d']] = (int)$r['s'];
$dim = (int)date('t', strtotime($mStart));
$maxV = max(1, ...array_values($daily ?: [1]));
$mag = 10 ** max(0, strlen((string)$maxV) - 1); $top = (int)(ceil($maxV / $mag) * $mag);
$W = 640; $H = 250; $L = 46; $B = 28; $T = 10; $cw = ($W - $L - 8) / $dim; $ph = $H - $B - $T;
$svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Collections per day this month">';
for ($i = 0; $i <= 4; $i++) {
    $y = $T + $ph - $ph * $i / 4; $v = $top * $i / 4;
    $svg .= '<line class="gl" x1="' . $L . '" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '"/><text x="' . ($L - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' . ($v >= 1000000 ? round($v / 1000000, 1) . 'M' : ($v >= 1000 ? round($v / 1000) . 'k' : (int)$v)) . '</text>';
}
for ($d = 1; $d <= $dim; $d++) {
    $date = date('Y-m-', strtotime($mStart)) . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    $v = $daily[$date] ?? 0; $bh = $v ? max(2, $ph * $v / $top) : 0;
    $x = $L + ($d - 1) * $cw + $cw * .18;
    if ($v) $svg .= '<rect class="bar" rx="2" x="' . round($x, 1) . '" y="' . round($T + $ph - $bh, 1) . '" width="' . round($cw * .64, 1) . '" height="' . round($bh, 1) . '"><title>' . e(fmt_date($date) . ': ' . ugx($v)) . '</title></rect>';
    if ($d === 1 || $d % 5 === 0) $svg .= '<text x="' . round($x + $cw * .32, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . $d . ' ' . date('M', strtotime($mStart)) . '</text>';
}
$svg .= '</svg>';
$st = ['Active' => 0, 'Overdue' => 0, 'Closed' => 0];
foreach (rows("SELECT l.status AS s, COUNT(*) AS n FROM loans l WHERE $BLo AND l.status IN ('Active','Overdue','Closed') GROUP BY l.status") as $r) $st[$r['s']] = (int)$r['n'];
$stTot = array_sum($st);
$cols = ['Active' => 'var(--side)', 'Overdue' => 'var(--brand)', 'Closed' => 'color-mix(in srgb, var(--brand) 32%, #fff)'];
$circ = 2 * M_PI * 62; $off = 0; $arcs = '';
if ($stTot) foreach ($st as $k => $n) {
    if (!$n) continue;
    $len = $circ * $n / $stTot;
    $arcs .= '<circle cx="85" cy="85" r="62" fill="none" stroke="' . $cols[$k] . '" stroke-width="24" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 85 85)"><title>' . e($k . ': ' . $n) . '</title></circle>';
    $off += $len;
} else $arcs = '<circle cx="85" cy="85" r="62" fill="none" stroke="var(--line)" stroke-width="24"/>';
echo '<div class="dash-grid"><div class="card"><div class="card-h"><h2>Collections trend</h2><span class="muted">' . e(date('F Y', strtotime($mStart))) . '</span></div>' . ($daily ? $svg : '<p class="muted">No collections posted this month yet.</p>') . '</div>';
echo '<div class="card"><div class="card-h"><h2>Loan status</h2></div><div class="donut-wrap"><svg class="donut" viewBox="0 0 170 170" role="img" aria-label="Loan status">' . $arcs
    . '<text class="dn" x="85" y="88" text-anchor="middle">' . $stTot . '</text><text class="dl" x="85" y="106" text-anchor="middle">Total loans</text></svg><ul class="legend">';
foreach ($st as $k => $n) echo '<li><i style="background:' . $cols[$k] . '"></i>' . e($k) . '<b>' . $n . ($stTot ? ' (' . round($n / $stTot * 100) . '%)' : '') . '</b></li>';
echo '</ul></div></div></div>';

// quick actions + recent activity
$acts = [];
foreach (rows('SELECT registered_at AS t, full_name, client_no FROM clients WHERE ' . $BP . ' ORDER BY registered_at DESC LIMIT 4') as $r) $acts[] = [$r['t'], 'user-plus', 'New client registered', $r['full_name']];
foreach (rows("SELECT l.application_date AS t, l.loan_no, c.full_name FROM loans l JOIN clients c ON c.id = l.client_id WHERE $BLo ORDER BY l.id DESC LIMIT 4") as $r) $acts[] = [$r['t'], 'clipboard', 'Loan application', $r['loan_no'] . ' · ' . $r['full_name']];
foreach (rows("SELECT p.created_at AS t, p.amount, c.full_name FROM payments p JOIN clients c ON c.id = p.client_id WHERE p.status = 'Posted' AND " . branch_scope('p.branch_id', $only) . ' ORDER BY p.id DESC LIMIT 4') as $r) $acts[] = [$r['t'], 'card', 'Collection recorded', ugx($r['amount']) . ' from ' . $r['full_name']];
foreach (rows("SELECT d.created_at AS t, d.approved_principal AS a, c.full_name FROM disbursements d JOIN loans l ON l.id = d.loan_id JOIN clients c ON c.id = l.client_id WHERE $BLo ORDER BY d.id DESC LIMIT 4") as $r) $acts[] = [$r['t'], 'dollar', 'Loan disbursed', ugx($r['a']) . ' to ' . $r['full_name']];
usort($acts, fn($a, $b) => strcmp((string)$b[0], (string)$a[0]));
$acts = array_slice($acts, 0, 6);
echo '<div class="dash-grid2"><div class="card"><div class="card-h"><h2>Quick actions</h2></div>' . quick_actions([['clients', ['a' => 'new'], 'Register client', 'user-plus', 'client.manage'], ['loans', ['a' => 'new'], 'New loan application', 'clipboard', 'loan.create'], ['payments', [], 'Record collection', 'card', 'payment.record'],
    ['clients', [], 'View clients', 'users', 'client.view'], ['loans', [], 'Loan list', 'file', 'loan.view'], ['disbursements', [], 'Disburse loan', 'dollar', 'loan.disburse|loan.disburse.authorize'], ['till', [], 'Cashier & till', 'clock', 'till.use'],
    ['cashbook', [], 'Record expense', 'layers', 'expense.create'], ['inbox', [], 'My approvals', 'inbox', 'loan.approve|payment.reverse|expense.approve|till.reconcile|eod.approve|backup.authorize'], ['arrears', [], 'Arrears', 'alert', 'portfolio.view'],
    ['eod', [], 'End of day', 'calendar', 'eod.view'], ['reports', [], 'Reports', 'bar', 'report.view']]) . '</div>';
echo '<div class="card"><div class="card-h"><h2>Recent activities</h2><a class="more" href="' . e(url('audit')) . '">' . (can_any('audit.own|audit.financial|audit.branch|audit.all') ? 'View all ›' : '') . '</a></div>';
if (!$acts) echo '<p class="muted">Nothing has happened yet.</p>';
else {
    echo '<ul class="activity">';
    foreach ($acts as [$t, $ic, $h, $sub]) {
        $when = strlen((string)$t) > 10 ? (substr((string)$t, 0, 10) === $asOf || substr((string)$t, 0, 10) === today() ? date('g:i A', strtotime($t)) : date('j M', strtotime($t))) : date('j M', strtotime((string)$t));
        echo '<li><span class="ai">' . icon($ic, 18) . '</span><span class="at"><b>' . e($h) . '</b><small>' . e($sub) . '</small></span><time>' . e($when) . '</time></li>';
    }
    echo '</ul>';
}
echo '</div></div>';
echo '<div class="detail-t">Detailed analysis</div><p class="muted" style="margin:.1rem 0 .6rem">Scope: ' . e($scope) . ' · as at ' . e(fmt_date($asOf)) . ' (business date). Accrued amounts are shown separately from cash actually received.</p>';
$officers = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Branch Manager','COO') ORDER BY full_name") as $u) $officers[$u['id']] = $u['full_name'];
$cashiers = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Cashier','Finance Officer','Branch Manager') ORDER BY full_name") as $u) $cashiers[$u['id']] = $u['full_name'];
$sts = ['Draft', 'Submitted', 'Under Review', 'Approved', 'Active', 'Overdue', 'Closed', 'Rejected'];
$filters = ['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date']];
if (count(accessible_branch_ids()) > 1) $filters['branch'] = ['label' => 'Branch', 'type' => 'select', 'all' => 'All my branches', 'options' => branch_options()];
$filters += ['officer' => ['label' => 'Loan officer', 'type' => 'select', 'options' => $officers], 'cashier' => ['label' => 'Cashier', 'type' => 'select', 'options' => $cashiers],
    'status' => ['label' => 'Loan status', 'type' => 'select', 'options' => array_combine($sts, $sts)], 'method' => ['label' => 'Payment method', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)]];
filter_bar($filters, $f + ['branch' => $chosen ?: ''], 'dashboard');

if (can('backup.request') || can('backup.manage')) {
    $lastBk = Backup::lastSuccess();
    if (!$lastBk || strtotime($lastBk['at']) < time() - 3 * 86400) echo '<div class="notice bad">No verified backup in the last 3 days. Ask the System Administrator to back up now.</div>';
}
if (can('limits.manage') && Approvals::unconfigured()) echo '<div class="notice">Approval limits are not configured for: ' . e(implode(', ', Approvals::unconfigured())) . '. Until configured, nothing is auto-escalated to the COO. <a href="' . e(url('settings', ['tab' => 'limits'])) . '">Configure →</a></div>';
$pendingInbox = can_any('loan.approve|payment.reverse|expense.approve|till.reconcile|eod.approve') ? count(Approvals::inbox()) : 0;
if ($pendingInbox) echo '<div class="notice info">You have <b>' . $pendingInbox . '</b> item(s) waiting for your decision. <a href="' . e(url('inbox')) . '">Open My Approvals →</a></div>';
?>
<div class="section-t">Clients</div>
<div class="kpis">
  <?= kpi('Registered clients', num(val('SELECT COUNT(*) FROM clients WHERE ' . $BP))) ?>
  <?= kpi('New today', num(val('SELECT COUNT(*) FROM clients WHERE substr(registered_at,1,10) = ? AND ' . $BP, [today()])), '', 'info') ?>
  <?= kpi('New this month', num(val('SELECT COUNT(*) FROM clients WHERE substr(registered_at,1,7) = ? AND ' . $BP, [date('Y-m')])), '', 'info') ?>
  <?= kpi('Active borrowers', num(val("SELECT COUNT(DISTINCT client_id) FROM loans l WHERE $LW AND $openLoans", $lp))) ?>
  <?= kpi('Clients with overdue loans', num(val("SELECT COUNT(DISTINCT client_id) FROM loans l WHERE $LW AND l.status = 'Overdue'", $lp)), '', 'bad') ?>
</div>
<div class="section-t">Loans</div>
<div class="kpis">
  <?= kpi('Applications awaiting review', num($cnt("l.status IN ('Draft','Submitted','Returned for Correction')")), 'Draft, submitted or returned', 'warn') ?>
  <?= kpi('Loans awaiting approval', num($cnt("l.status = 'Under Review'")), 'Assessed, with the manager', 'warn') ?>
  <?= kpi('Escalated to COO', num($cnt("l.status IN ('Submitted','Under Review') AND l.escalated_at IS NOT NULL")), '', 'warn') ?>
  <?= kpi('Approved, awaiting disbursement', num($cnt("l.status = 'Approved'")), '', 'info') ?>
  <?= kpi('Active loans', num($cnt($openLoans))) ?>
  <?= kpi('Due today', num($cnt("$openLoans AND l.due_date = ?", [$asOf])), '', 'warn') ?>
  <?= kpi('Due this week', num($cnt("$openLoans AND l.due_date BETWEEN ? AND ?", [$asOf, Calc::addDays($asOf, 7)]))) ?>
  <?= kpi('Overdue loans', num($cnt("l.status = 'Overdue'")), '', 'bad') ?>
  <?= kpi('Fully settled', num($cnt("l.status = 'Closed'")), '', 'muted') ?>
</div>
<div class="section-t">Financial position <span class="muted" style="text-transform:none;letter-spacing:0">· cash flows for <?= e(fmt_date($f['from'])) ?> – <?= e(fmt_date($f['to'])) ?></span></div>
<div class="kpis">
  <?= kpi('Principal disbursed', ugx($disb['pr']), 'gross, in period') ?>
  <?= kpi('Outstanding principal', ugx($bal['pr']), 'active portfolio') ?>
  <?= kpi('Interest receivable', ugx($bal['it']), 'accrued, NOT yet received', 'warn') ?>
  <?= kpi('Interest collected', ugx($collected('interest')), 'cash received in period', 'ok') ?>
  <?= kpi('Collections today', ugx($todayColl), 'posted ' . fmt_date($asOf)) ?>
  <?= kpi('Collections this month', ugx($monthColl)) ?>
  <?= kpi('Fees collected', ugx($disb['fees'] + $feesPaid), 'deducted at disbursement + paid') ?>
  <?= kpi('Penalties collected', ugx($collected('penalty')), 'accrued outstanding: ' . ugx($bal['pn'])) ?>
  <?= kpi('Cash in tills now', ugx($tillCash), 'open / unreconciled sessions', 'info') ?>
  <?= kpi('Cashier variances', ugx($varSum), 'in period', $varSum != 0 ? 'bad' : '') ?>
</div>
<div class="section-t">End of Day</div>
<div class="kpis">
  <?= kpi('Business date', $bdNow ? e(fmt_date($bdNow)) : '<span class="muted">none open</span>', branch_name(branch_id())) ?>
  <?= kpi('EOD status', $bdNow ? badge(Eod::status($bdNow)) : badge('Not Started')) ?>
  <?= kpi('Cashiers with open tills', num($sc['Open']), '', $sc['Open'] ? 'warn' : '') ?>
  <?= kpi('Awaiting reconciliation', num($sc['Submitted'] + $sc['Investigation']), '', ($sc['Submitted'] + $sc['Investigation']) ? 'warn' : '') ?>
  <?= kpi('Cashiers reconciled', num($sc['Reconciled']), '', 'ok') ?>
  <?= kpi('EOD awaiting approval', num($awaitingEod), '', $awaitingEod ? 'warn' : '') ?>
  <?= kpi('Last completed EOD', $lastEod ? e(fmt_date($lastEod['business_date'])) : '—') ?>
</div>
<?php if (can('compliance.view') || count(accessible_branch_ids()) > 1): ?>
<div class="card"><div class="card-h"><h2>Branches — EOD status</h2><a class="btn sm" href="<?= e(url('compliance')) ?>">Compliance →</a></div>
<?php
$ov = Eod::overview();
data_table(['name' => 'Branch', 'business_date' => ['Business date', 'date'], 'eod_status' => ['EOD status', 'badge'], 'open_tills' => ['Open tills', 'num'], 'unreconciled' => ['Unreconciled', 'num'], 'variance' => ['Variance', 'money'], 'last_closed' => ['Last closed', 'date']],
    $ov, ['class' => 'nofilter']);
?></div>
<?php endif; ?>
<div class="grid g2">
  <div class="card">
    <h2>Collections — last 14 business dates</h2>
    <?php
    $series = array_reverse(rows("SELECT business_date AS d, SUM(amount) AS s FROM payments WHERE status = 'Posted' AND $BP GROUP BY business_date ORDER BY business_date DESC LIMIT 14"));
    $max = max(1, ...array_map(fn($r) => (int)$r['s'], $series ?: [['s' => 1]]));
    if (!$series) echo '<p class="muted">No collections recorded yet.</p>';
    else {
        echo '<div class="bars">';
        foreach ($series as $r) echo '<div class="bar" style="height:' . max(2, round($r['s'] / $max * 100)) . '%" title="' . e(fmt_date($r['d']) . ': ' . ugx($r['s'])) . '"><span>' . e(number_format($r['s'] / 1000)) . 'k</span></div>';
        echo '</div><div class="bar-lbl">';
        foreach ($series as $r) echo '<span>' . e(date('d/m', strtotime($r['d']))) . '</span>';
        echo '</div>';
    }
    ?>
  </div>
  <div class="card">
    <h2>Overdue ageing (outstanding principal)</h2>
    <?php
    $buckets = ['1–7 days' => [1, 7], '8–30 days' => [8, 30], '31–60 days' => [31, 60], '61–90 days' => [61, 90], 'Over 90 days' => [91, 99999]];
    $out = [];
    foreach ($buckets as $label => [$lo, $hi]) {
        $r = row("SELECT COUNT(*) AS n, COALESCE(SUM(b.principal_out),0) AS pr, COALESCE(SUM(b.total_out),0) AS tot FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id
                  WHERE l.status = 'Overdue' AND l.overdue_since IS NOT NULL AND $BL AND " . sql_daydiff('?', 'l.overdue_since') . ' BETWEEN ? AND ?', [$asOf, $lo, $hi]);
        $out[] = ['bucket' => $label, 'n' => $r['n'], 'pr' => $r['pr'], 'tot' => $r['tot']];
    }
    data_table(['bucket' => 'Days overdue', 'n' => ['Loans', 'num'], 'pr' => ['Principal', 'money'], 'tot' => ['Total owed', 'money']], $out, ['class' => 'nofilter', 'totals' => ['n', 'pr', 'tot']]);
    ?>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Loans due soon or overdue</h2><a class="btn sm" href="<?= e(url('arrears')) ?>">Open arrears →</a></div>
  <?php
  $due = rows("SELECT l.id, l.loan_no, c.full_name, c.phone, l.due_date, l.status, b.total_out FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id
               WHERE $LW AND l.status IN ('Active','Overdue') AND l.due_date <= ? ORDER BY l.due_date LIMIT 12", array_merge($lp, [Calc::addDays($asOf, 7)]));
  data_table(['loan_no' => 'Loan', 'full_name' => 'Client', 'phone' => 'Phone', 'due_date' => ['Due', 'date'], 'status' => ['Status', 'badge'], 'total_out' => ['Outstanding', 'money']],
      $due, ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'No loans due in the next 7 days.', 'class' => 'nofilter']);
  ?>
</div>
