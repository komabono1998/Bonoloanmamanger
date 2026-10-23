<?php
$title = 'Reports';
$cat = Reports::catalogue();
$key = get('r');
$days = max(1, setting_int('report_default_days'));
$asOf = reporting_date();

if (!$key || !isset($cat[$key])) {
    page_head('Reports', '', 'Every report states its reporting period and business date. Accrued, collected, gross and net amounts are never mixed.');
    $groups = [];
    foreach ($cat as $k => [$g, $t, $d]) $groups[$g][$k] = [$t, $d];
    echo '<div class="grid g2">';
    foreach ($groups as $g => $items) {
        echo '<div class="card"><h2>' . e($g) . '</h2><ul style="margin:0;padding-left:1.1rem">';
        foreach ($items as $k => [$t, $d]) echo '<li style="margin:.3rem 0"><a href="' . e(url('reports', ['r' => $k])) . '"><b>' . e($t) . '</b></a>' . ($d ? '<br><span class="muted">' . e($d) . '</span>' : '') . '</li>';
        echo '</ul></div>';
    }
    echo '</div>';
    return;
}

[$group, $rtitle, $rdesc, $uses] = $cat[$key];
$f = ['from' => get('from') ?: date('Y-m-d', strtotime("-$days days", strtotime($asOf))), 'to' => get('to') ?: $asOf, 'officer' => (int)get('officer'), 'cashier' => (int)get('cashier'), 'method' => get('method'), 'client' => (int)get('client'), 'branch' => (int)get('branch')];
if (!is_date($f['from'])) $f['from'] = date('Y-m-d', strtotime("-$days days", strtotime($asOf)));
if (!is_date($f['to'])) $f['to'] = $asOf;
must($f['from'] <= $f['to'], 'The start date must not be after the end date.');
$rep = Reports::run($key, $f);
$periodic = (bool)array_intersect($uses, ['from', 'to']);
$title = $rtitle;

$params = array_filter(['r' => $key, 'from' => $f['from'], 'to' => $f['to'], 'officer' => $f['officer'] ?: '', 'cashier' => $f['cashier'] ?: '', 'method' => $f['method'], 'client' => $f['client'] ?: '', 'branch' => $f['branch'] ?: ''], fn($v) => $v !== '');
$act = btn('All reports', url('reports'));
$act .= '<a class="btn" href="#" data-print>Print / PDF</a>';
if (can('report.export')) $act .= btn('CSV', url('export', ['kind' => 'report', 'format' => 'csv'] + $params)) . btn('Excel', url('export', ['kind' => 'report', 'format' => 'xls'] + $params));
page_head($rtitle, $act, $group . ($rdesc ? ' · ' . $rdesc : ''));

$filters = [];
if (in_array('from', $uses, true)) { $filters['from'] = ['label' => 'From', 'type' => 'date']; $filters['to'] = ['label' => 'To', 'type' => 'date']; }
if (in_array('officer', $uses, true)) { $o = []; foreach (rows('SELECT id, full_name FROM users ORDER BY full_name') as $u) $o[$u['id']] = $u['full_name']; $filters['officer'] = ['label' => 'Loan officer', 'type' => 'select', 'options' => $o]; }
if (in_array('cashier', $uses, true)) { $o = []; foreach (rows('SELECT id, full_name FROM users ORDER BY full_name') as $u) $o[$u['id']] = $u['full_name']; $filters['cashier'] = ['label' => 'Cashier', 'type' => 'select', 'options' => $o]; }
if (in_array('method', $uses, true)) $filters['method'] = ['label' => 'Payment method', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)];
if (in_array('branch', $uses, true) && count(accessible_branch_ids()) > 1) $filters['branch'] = ['label' => 'Branch', 'type' => 'select', 'all' => 'All my branches', 'options' => branch_options()];
if (in_array('client', $uses, true)) $filters['client'] = ['label' => 'Client', 'type' => 'select', 'options' => client_select_options()];
if ($filters) filter_bar($filters, ['from' => $f['from'], 'to' => $f['to'], 'officer' => $f['officer'] ?: '', 'cashier' => $f['cashier'] ?: '', 'method' => $f['method'], 'client' => $f['client'] ?: '', 'branch' => $f['branch'] ?: ''], 'reports', ['r' => $key]);

echo '<div class="card report-doc">' . letterhead() . '<div class="muted" style="margin:.6rem 0"><b>' . e($rtitle) . '</b> · '
    . ($periodic ? 'Period ' . e(fmt_date($f['from'])) . ' to ' . e(fmt_date($f['to'])) . ' (business dates)' : 'As at business date ' . e(fmt_date($asOf))) . ' · generated ' . e(fmt_dt(now())) . ' by ' . e(user()['full_name']) . ' · ' . count($rep['rows']) . ' row(s)</div>';
if ($rep['note']) echo '<div class="notice info">' . e($rep['note']) . '</div>';
data_table($rep['cols'], $rep['rows'], ['totals' => $rep['totals'], 'empty' => 'No data for these filters.']);
echo '</div>';
