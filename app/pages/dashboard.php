<?php
$title = 'Dashboard';
$business = can_any('client.view|loan.view|payment.view|portfolio.view');

// ------------------------------------------------------------------ System Administrator: technical dashboard only
if (!$business) {
    page_head('System dashboard', '', 'Technical health and security. ');
    $h = System::health();
    echo quick_tiles([['settings', ['tab' => 'business'], 'Institution profile & logo', 'building', 'settings.manage'], ['users', ['a' => 'new'], 'Add a user', 'user-plus', 'user.manage'], ['users', [], 'Users & Staff IDs', 'users', 'user.manage'],
        ['eodsys', [], 'End of Day', 'calendar', 'backup.manage|system.health'], ['branches', [], 'Branches', 'building', 'branch.manage'], ['settings', [], 'All settings', 'settings', 'settings.manage|limits.manage'], ['backup', [], 'Backups', 'database', 'backup.manage|backup.request'],
        ['security', [], 'Security monitoring', 'lock', 'security.monitor'], ['audit', [], 'Audit trail', 'eye', null]]);
    if (can('settings.manage')) {
        $list = System::setupChecklist(); $done = count(array_filter($list, fn($x) => $x[1]));
        if ($done < count($list)) {
            echo '<div class="card"><div class="card-h"><h2>Finish setting up your institution</h2><span class="muted">' . $done . ' of ' . count($list) . ' complete</span></div><div class="steps">';
            foreach ($list as [$label, $ok, $hint, $link, $ic]) echo '<a class="step' . ($ok ? ' done' : '') . '" href="' . e(url($link[0], $link[1])) . '"><span class="step-ic">' . icon($ok ? 'check' : $ic, 16) . '</span><span><b>' . e($label) . '</b><small>' . e($hint) . '</small></span></a>';
            echo '</div></div>';
        }
    }
    $sd = '';
    foreach (all_branch_options(false) as $bid => $bn) $sd .= '<span class="chip">' . icon('building', 14) . ' ' . e($bn) . ': <b>' . (current_bd($bid) ? e(fmt_date(current_bd($bid))) : 'no day open') . '</b></span> ';
    echo '<div class="card"><div class="card-h"><h2>System date</h2><a class="more" href="' . e(url('eodsys')) . '">End of Day & backup ›</a></div><div style="display:flex;gap:.5rem;flex-wrap:wrap">' . $sd . '</div><p class="muted" style="margin:.6rem 0 0">The system date is each branch’s open business date. It moves to the next working day when that branch closes its End-of-Day.</p></div>';
    echo '<div class="kpis">'
        . kpi('Database', $h['db']['ok'] ? 'Online' : 'DOWN', $h['db']['engine'], $h['db']['ok'] ? 'ok' : 'bad')
        . kpi('File storage', $h['storage']['ok'] ? 'Writable' : 'PROBLEM', $h['storage']['driver'], $h['storage']['ok'] ? 'ok' : 'bad')
        . kpi('Last verified backup', $h['backup']['age_hours'] === null ? 'never' : $h['backup']['age_hours'] . ' h ago', $h['backup']['last'] ?? '', $h['backup']['ok'] ? 'ok' : 'bad')
        . kpi('Failed logins (24 h)', num($h['security']['failed_24h']), 'locked accounts: ' . $h['security']['locked'], $h['security']['failed_24h'] > 20 ? 'bad' : '')
        . kpi('Active users', num($h['users']['active']), 'MFA enrolled: ' . $h['users']['mfa'])
        . kpi('Inactive accounts', num((int)val("SELECT COUNT(*) FROM users WHERE status = 'inactive'")), 'deactivated')
        . kpi('Permission changes (7 days)', num((int)val("SELECT COUNT(*) FROM audit_logs WHERE action IN ('User permissions changed','Role permissions changed','User report access changed','User updated') AND at > ?", [date('Y-m-d H:i:s', time() - 7 * 86400)])), 'user, role and report access changes')
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
if (portfolio_restricted()) $f['officer'] = (int)uid();
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
// ------------------------------------------------------------------ headline view (branch-scoped; ignores the filters further down)
$safe = function (string $what, callable $fn) {
    try { $fn(); } catch (Throwable $e) { error_log((string)$e); echo '<div class="notice bad">' . e($what) . ' could not be loaded. Try <a href="' . e($_SERVER['REQUEST_URI'] ?? '') . '">refreshing</a>; if it continues, contact the administrator.</div>'; }
};
$first = explode(' ', trim(user()['full_name']))[0];
$hour = (int)date('G');
$refreshed = date('H:i:s');
echo '<div class="welcome"><div><h1>Dashboard <span class="accent">Summary</span></h1><p>' . ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening')) . ', ' . e($first) . '! Here’s what’s happening at ' . ($only ? e(branch_name($only[0])) : (count(accessible_branch_ids()) > 1 ? 'your branches' : 'your branch')) . ' today.</p></div>'
    . '<div class="when"><span>' . icon('calendar', 18) . e(date('D, j M Y', strtotime($asOf))) . '</span><span>' . icon('building', 18) . e($scope) . '</span><span class="muted" title="Figures are read live from the database">Data as of ' . $refreshed . '</span></div></div>';
echo '<div class="dash-actions">' . (can('client.manage') ? '<a class="btn primary" href="' . e(url('clients', ['a' => 'new'])) . '">' . icon('plus', 14) . ' New Borrower</a>' : '') . (can('loan.create') ? '<a class="btn primary" href="' . e(url('loans', ['a' => 'new'])) . '">' . icon('plus', 14) . ' New Loan</a>' : '')
    . (can('payment.record') ? '<a class="btn primary" href="' . e(url('payments')) . '">' . icon('file', 14) . ' Payment Form</a>' : '') . '<a class="btn" href="' . e($_SERVER['REQUEST_URI'] ?? '') . '">' . icon('activity', 14) . ' Refresh</a></div>';

$sm = null;
$safe('The summary cards', function () use ($only, &$sm) {
    $sm = Dashboard::summary($only);
    $cards = '<div class="stat"><span class="stat-ic">' . icon('users', 26) . '</span><div><b>' . num($sm['clients']) . '</b><div class="sl">Total clients</div>' . Dashboard::trend($sm['clients'], $sm['clients_prev']) . '</div></div>'
        . '<div class="stat"><span class="stat-ic">' . icon('clipboard', 26) . '</span><div><b>' . num($sm['active']) . '</b><div class="sl">Active loans</div><div class="trend">' . ($sm['overdue'] ? '<i class="down">' . $sm['overdue'] . ' overdue</i>' : '<i class="up">none overdue</i>') . ' · ' . $sm['new_loans_month'] . ' disbursed this month</div></div></div>'
        . '<div class="stat"><span class="stat-ic">' . icon('dollar', 26) . '</span><div><b>' . ugx($sm['disbursed_total']) . '</b><div class="sl">Total disbursed <span class="muted">(cumulative)</span></div><div class="trend">' . ugx($sm['disbursed_month']) . ' this month</div></div></div>';
    if (isset($sm['coll_today'])) $cards .= '<div class="stat"><span class="stat-ic">' . icon('card', 26) . '</span><div><b>' . ugx($sm['coll_today']) . '</b><div class="sl">Collections today</div>' . ($sm['coll_prev_day'] > 0 ? Dashboard::trend($sm['coll_today'], $sm['coll_prev_day'], 'vs ' . fmt_date($sm['coll_prev_day_date'])) : '<div class="trend">payments received today</div>') . '</div></div>';
    else $cards .= '<div class="stat"><span class="stat-ic">' . icon('calendar', 26) . '</span><div><b>' . num($sm['due_today']) . '</b><div class="sl">Loans due today</div><div class="trend">accounts needing payment follow-up</div></div></div>';
    echo '<div class="stats">' . $cards . '</div>';
    echo '<div class="kpis kpis-sm">'
        . kpi('Outstanding principal', ugx($sm['principal_out']), 'active + overdue loans, not yet repaid')
        . kpi('Overdue loan balance', ugx($sm['overdue_balance']), 'requires collection follow-up', $sm['overdue_balance'] ? 'bad' : '')
        . kpi('Pending applications', num($sm['pending_apps']), 'awaiting assessment or approval', $sm['pending_apps'] ? 'warn' : '')
        . kpi('Loans due today', num($sm['due_today']), 'accounts requiring follow-up', $sm['due_today'] ? 'warn' : '') . '</div>';
});

$safe('The loan lists', function () use ($only) {
    if (!can_any('loan.view|portfolio.view')) return;
    $ll = Dashboard::loanLists($only);
    echo '<div class="dash-lists"><div class="card"><div class="card-h"><div><h2>Client Loan List</h2><small class="muted">Active loans with the amount outstanding and the next due date</small></div></div>';
    data_table(['full_name' => 'Client name', 'principal_out' => ['Principal', 'money'], 'total_out' => ['Total owed', 'money'], 'due_date' => ['Due date', 'date']], $ll['active'], ['class' => 'nofilter dark', 'empty' => 'No active loans.', 'link' => fn($r) => url('loan', ['id' => $r['id']])]);
    echo '</div><div class="card"><div class="card-h"><div><h2>Past Due Payments</h2><small class="muted">Loans past their due date</small></div></div>';
    data_table(['full_name' => 'Client name', 'total_out' => ['Amount due', 'money'], 'due_date' => ['Due date', 'date']], $ll['past'], ['class' => 'nofilter dark', 'empty' => 'Nothing is past due.', 'link' => fn($r) => url('loan', ['id' => $r['id']])]);
    echo '</div></div><div class="card"><div class="card-h"><div><h2>Upcoming Loan Payments</h2><small class="muted">Loans falling due in the next 14 days</small></div></div>';
    data_table(['full_name' => 'Client name', 'total_out' => ['Amount due', 'money'], 'due_date' => ['Due date', 'date']], $ll['upcoming'], ['class' => 'nofilter dark', 'empty' => 'No loans fall due in the next 14 days.', 'link' => fn($r) => url('loan', ['id' => $r['id']])]);
    echo '</div>';
});

$safe('The role summary', function () use ($only) {
    foreach (Dashboard::focus($only) as $b) {
        echo '<div class="card focus"><div class="card-h"><h2>' . e($b['title']) . '</h2>' . (!empty($b['link']) ? '<a class="more" href="' . e($b['link'][0]) . '">' . e($b['link'][1]) . ' ›</a>' : '') . '</div><div class="kpis">';
        foreach ($b['kpis'] as $k) echo kpi($k[0], $k[1], $k[2] ?? '', $k[3] ?? '');
        echo '</div>';
        if (!empty($b['table']) && $b['table']['rows']) data_table($b['table']['cols'], $b['table']['rows'], ['class' => 'nofilter']);
        echo '</div>';
    }
});

// two default charts: collections trend (with comparison and target) and loan portfolio status
$showCash = can('payment.view');
$safe('The charts', function () use ($only, $showCash) {
    $mode = in_array(get('trend'), ['d', 'w', 'm'], true) ? get('trend') : 'd';
    echo '<div class="dash-grid' . ($showCash ? '' : ' one') . '">';
    if ($showCash) {
        $ser = Dashboard::collectionSeries($only, $mode);
        $labels = ['d' => 'Daily', 'w' => 'Weekly', 'm' => 'Monthly'];
        echo '<div class="card"><div class="card-h"><h2>Collections trend</h2><div class="seg">';
        foreach ($labels as $k => $l) echo '<a class="' . ($k === $mode ? 'on' : '') . '" href="' . e(url('dashboard', array_filter(['trend' => $k] + $_GET, fn($v, $kk) => $kk !== 'p' && $v !== '', ARRAY_FILTER_USE_BOTH))) . '">' . $l . '</a>';
        echo '</div></div>';
        $has = array_filter(array_column($ser['rows'], 'cur')) || array_filter(array_column($ser['rows'], 'prev'));
        echo $has ? Dashboard::collectionsChart($ser) : '<p class="muted">No collections posted in this period yet.</p>';
        echo '<div class="chart-legend"><span><i style="background:var(--brand)"></i> Current period</span><span><i class="ln" style="background:var(--side)"></i> Previous period</span>'
            . ($ser['target'] > 0 ? '<span><i class="ln dash"></i> Target ' . e(ugx($ser['target'])) . '</span>' : '<span class="muted">No target set (Settings → Cash &amp; End of Day)</span>') . '</div></div>';
    }
    $st = Dashboard::statusBreakdown($only);
    $tot = array_sum(array_column($st, 'n'));
    $cols = ['Active' => 'var(--side)', 'Overdue' => 'var(--brand)', 'Pending' => 'color-mix(in srgb, var(--brand) 55%, #fff)', 'Closed' => 'color-mix(in srgb, var(--brand) 22%, #fff)'];
    $circ = 2 * M_PI * 62; $off = 0; $arcs = '';
    if ($tot) foreach ($st as $k => $g) {
        if (!$g['n']) continue;
        $len = $circ * $g['n'] / $tot;
        $arcs .= '<circle cx="85" cy="85" r="62" fill="none" stroke="' . $cols[$k] . '" stroke-width="24" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 85 85)"><title>' . e($k . ': ' . $g['n']) . '</title></circle>';
        $off += $len;
    } else $arcs = '<circle cx="85" cy="85" r="62" fill="none" stroke="var(--line)" stroke-width="24"/>';
    echo '<div class="card"><div class="card-h"><h2>Loan portfolio status</h2></div><div class="donut-wrap"><svg class="donut" viewBox="0 0 170 170" role="img" aria-label="Loan portfolio status">' . $arcs
        . '<text class="dn" x="85" y="88" text-anchor="middle">' . $tot . '</text><text class="dl" x="85" y="106" text-anchor="middle">Total loans</text></svg><ul class="legend">';
    foreach ($st as $k => $g) echo '<li><i style="background:' . $cols[$k] . '"></i><span>' . e($k) . '<small class="muted"> ' . ($k === 'Closed' ? '' : ($k === 'Pending' ? '· requested / approved ' : '· outstanding ') . e(ugx($g['v']))) . '</small></span><b>' . $g['n'] . ($tot ? ' (' . round($g['n'] / $tot * 100) . '%)' : '') . '</b></li>';
    echo '</ul></div></div></div>';
});

// quick actions + recent activity
$safe('Quick actions and recent activity', function () use ($only, $asOf) {
    echo '<div class="dash-grid2"><div class="card"><div class="card-h"><h2>Quick actions</h2></div>' . quick_actions([['clients', ['a' => 'new'], 'Register client', 'user-plus', 'client.manage'], ['loans', ['a' => 'new'], 'New loan application', 'clipboard', 'loan.create'], ['payments', [], 'Record collection', 'card', 'payment.record'],
        ['loans', ['status' => 'Submitted'], 'Review applications', 'search', 'loan.view'], ['approvals', [], 'Loan approval', 'check', 'loan.approve'], ['disbursements', [], 'Disburse loan', 'dollar', 'loan.disburse|loan.disburse.authorize'], ['till', [], 'Cashier & till', 'clock', 'till.use'],
        ['cashbook', [], 'Record expense', 'layers', 'expense.create'], ['inbox', [], 'My approvals', 'inbox', 'loan.approve|payment.reverse|expense.approve|till.reconcile|eod.approve|backup.authorize'], ['arrears', [], 'Arrears', 'alert', 'portfolio.view'],
        ['eod', [], 'End of day', 'calendar', 'eod.view'], ['reports', [], 'Reports', 'bar', 'report.view']]) . '</div>';
    $acts = Dashboard::activities($only, 8);
    echo '<div class="card"><div class="card-h"><h2>Recent activities</h2>' . (can_any('audit.own|audit.financial|audit.branch|audit.all') ? '<a class="more" href="' . e(url('audit')) . '">View all ›</a>' : '') . '</div>';
    if (!$acts) echo '<p class="muted">Nothing has happened yet.</p>';
    else {
        echo '<ul class="activity">';
        foreach ($acts as [$t, $ic, $h, $ref, $usr, $stat]) {
            $d = substr((string)$t, 0, 10);
            $when = strlen((string)$t) > 10 ? ($d === $asOf || $d === today() ? date('g:i A', strtotime($t)) : date('j M, g:i A', strtotime($t))) : date('j M', strtotime((string)$t));
            echo '<li><span class="ai">' . icon($ic, 18) . '</span><span class="at"><b>' . e($h) . '</b><small>Ref ' . e($ref) . ' · ' . e($usr) . '</small></span><span class="as">' . badge($stat) . '<time>' . e($when) . '</time></span></li>';
        }
        echo '</ul>';
    }
    echo '</div></div>';
});

echo '<div class="detail-t">Detailed analysis</div><p class="muted" style="margin:.1rem 0 .6rem">Scope: ' . e($scope) . ' · as at ' . e(fmt_date($asOf)) . ' (business date). Accrued amounts are shown separately from cash actually received. Cumulative disbursements and current outstanding principal are different measures.</p>';
$safe('Repayment performance', function () use ($only) {
    if (!can('portfolio.view')) return;
    $rp = Dashboard::repayment($only);
    $ar = Dashboard::arrearsBreakdown($only);
    echo '<div class="grid g2"><div class="card"><div class="card-h"><h2>Repayment performance</h2><span class="muted">last 6 months</span></div>';
    echo array_sum(array_column($rp, 'expected')) + array_sum(array_column($rp, 'actual')) > 0 ? Dashboard::repaymentChart($rp) : '<p class="muted">No scheduled instalments or payments in this period.</p>';
    echo '<div class="chart-legend"><span><i style="background:var(--side)"></i> Expected (scheduled instalments due)</span><span><i style="background:var(--brand)"></i> Actual (payments posted)</span><span class="muted">Figures under each month: repayment rate</span></div></div>';
    echo '<div class="card"><div class="card-h"><h2>Arrears by ' . e(strtolower(Dashboard::arrearsGrouping($only))) . '</h2></div>';
    data_table(['name' => Dashboard::arrearsGrouping($only), 'loans' => ['Overdue loans', 'num'], 'principal' => ['Principal', 'money'], 'total' => ['Total owed', 'money']], $ar, ['class' => 'nofilter', 'empty' => 'No overdue loans.']);
    echo '</div></div>';
});
$officers = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Branch Manager','COO') ORDER BY full_name") as $u) $officers[$u['id']] = $u['full_name'];
$cashiers = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Cashier','Finance Officer','Branch Manager') ORDER BY full_name") as $u) $cashiers[$u['id']] = $u['full_name'];
$sts = ['Draft', 'Submitted', 'Under Review', 'Approved', 'Active', 'Overdue', 'Closed', 'Rejected'];
$filters = ['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date']];
if (count(accessible_branch_ids()) > 1) $filters['branch'] = ['label' => 'Branch', 'type' => 'select', 'all' => 'All my branches', 'options' => branch_options()];
$filters += ['officer' => ['label' => 'Loan officer', 'type' => 'select', 'options' => $officers]];
if ($showCash) $filters += ['cashier' => ['label' => 'Cashier', 'type' => 'select', 'options' => $cashiers]];
$filters += ['status' => ['label' => 'Loan status', 'type' => 'select', 'options' => array_combine($sts, $sts)]];
if ($showCash) $filters += ['method' => ['label' => 'Payment method', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)]];
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
  <?php if ($showCash): ?>
  <?= kpi('Interest collected', ugx($collected('interest')), 'cash received in period', 'ok') ?>
  <?= kpi('Collections today', ugx($todayColl), 'posted ' . fmt_date($asOf)) ?>
  <?= kpi('Collections this month', ugx($monthColl)) ?>
  <?= kpi('Fees collected', ugx($disb['fees'] + $feesPaid), 'deducted at disbursement + paid') ?>
  <?= kpi('Penalties collected', ugx($collected('penalty')), 'accrued outstanding: ' . ugx($bal['pn'])) ?>
  <?= kpi('Cash in tills now', ugx($tillCash), 'open / unreconciled sessions', 'info') ?>
  <?= kpi('Cashier variances', ugx($varSum), 'in period', $varSum != 0 ? 'bad' : '') ?>
  <?php else: ?>
  <?= kpi('Penalties accrued', ugx($bal['pn']), 'outstanding') ?>
  <?php endif; ?>
</div>
<?php if (can('eod.view')): ?>
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
<?php endif; ?>
<?php if (can('compliance.view') || count(accessible_branch_ids()) > 1): ?>
<div class="card"><div class="card-h"><h2>Branches — EOD status</h2><?php if (can('compliance.view')): ?><a class="btn sm" href="<?= e(url('compliance')) ?>">Compliance →</a><?php endif; ?></div>
<?php
$ov = Eod::overview();
data_table(['name' => 'Branch', 'business_date' => ['Business date', 'date'], 'eod_status' => ['EOD status', 'badge'], 'open_tills' => ['Open tills', 'num'], 'unreconciled' => ['Unreconciled', 'num'], 'variance' => ['Variance', 'money'], 'last_closed' => ['Last closed', 'date']],
    $ov, ['class' => 'nofilter']);
?></div>
<?php endif; ?>
<div class="grid g2">
  <?php if ($showCash): ?>
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
  <?php endif; ?>
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
