<?php
$title = 'Branch compliance';
$ov = Eod::overview();
page_head('Branch compliance', '', 'Institution-wide oversight of End-of-Day completion, unresolved reconciliation differences and escalations. Read-only: approvals happen in each branch.');

$late = array_filter($ov, fn($r) => $r['status_active'] === 'active' && ($r['days_behind'] ?? 0) > 1 && !$r['business_date']);
$open = array_filter($ov, fn($r) => $r['business_date']);
echo '<div class="kpis">' . kpi('Active branches', num(count(array_filter($ov, fn($r) => $r['status_active'] === 'active'))))
    . kpi('Business day open', num(count($open)), '', 'info')
    . kpi('Behind on EOD (no open day, last close > 1 day ago)', num(count($late)), '', $late ? 'bad' : 'ok')
    . kpi('Unreconciled tills', num(array_sum(array_column($ov, 'unreconciled'))), '', array_sum(array_column($ov, 'unreconciled')) ? 'warn' : 'ok')
    . kpi('Open variance (total)', ugx(array_sum(array_column($ov, 'variance'))), '', array_sum(array_column($ov, 'variance')) ? 'bad' : '') . '</div>';

echo '<div class="card"><h2>End-of-Day status by branch</h2>';
data_table(['name' => 'Branch', 'business_date' => ['Open business date', 'date'], 'eod_status' => ['EOD status', 'badge'], 'open_tills' => ['Open tills', 'num'], 'unreconciled' => ['Unreconciled counts', 'num'], 'variance' => ['Cash variance', 'money'],
    'last_closed' => ['Last closed', 'date'], 'days_behind' => ['Days since close', 'num']], $ov, ['class' => 'nofilter']);
echo '</div>';

$esc = rows("SELECT e.branch_id, e.business_date, e.status, e.submitted_at, u.full_name AS sub FROM eod_records e LEFT JOIN users u ON u.id = e.submitted_by
             WHERE e.status = 'Escalated to COO' AND " . branch_scope('e.branch_id') . ' ORDER BY e.submitted_at');
echo '<div class="card"><h2>Escalated End-of-Day reviews</h2>';
data_table(['branch' => 'Branch', 'business_date' => ['Business date', 'date'], 'sub' => 'Prepared by', 'submitted_at' => ['Submitted', 'dt']], array_map(fn($r) => $r + ['branch' => branch_name((int)$r['branch_id'])], $esc),
    ['class' => 'nofilter', 'empty' => 'No escalated EOD reviews.', 'link' => fn($r) => url('inbox')]);
echo '</div>';

echo '<div class="grid g2"><div class="card"><h2>Portfolio quality by branch</h2>';
$rep = Reports::run('branch_comparison', ['from' => date('Y-m-01', strtotime(today())), 'to' => today()]);
data_table(['branch' => 'Branch', 'principal_out' => ['Principal out', 'money'], 'overdue' => ['Overdue', 'num'], 'par' => 'PAR'], $rep['rows'], ['class' => 'nofilter']);
echo '<p>' . btn('Full branch comparison report', url('reports', ['r' => 'branch_comparison']), 'sm') . '</p></div><div class="card"><h2>Approval limits</h2>';
$un = Approvals::unconfigured();
if ($un) echo '<div class="notice">Not configured: ' . e(implode(' · ', $un)) . '. The institution must set these; the system does not invent them.</div>';
$lim = [];
foreach (Approvals::CATEGORIES as $k => $label) $lim[] = ['cat' => $label, 'limit' => Approvals::limit($k, 0)];
data_table(['cat' => 'Category', 'limit' => ['Branch Manager limit (institution default)', fn($r) => $r['limit'] === null ? '<span class="muted">not set</span>' : e(ugx($r['limit']))]], $lim, ['class' => 'nofilter']);
if (can('limits.manage')) echo '<p>' . btn('Configure limits', url('settings', ['tab' => 'limits']), 'sm') . '</p>';
echo '</div></div>';
