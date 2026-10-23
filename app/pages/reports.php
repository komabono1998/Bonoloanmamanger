<?php
/**
 * Report Centre. Every signed-in user with the Reports module sees the reports their profile has been given access to,
 * grouped in the 12 categories of the reporting specification. Each report is generated from live records inside the
 * user's branch and portfolio scope, and states that scope on the output.
 */
$title = 'Report Centre';
$id = strtoupper(trim((string)get('r')));
$mine = ReportCatalog::mine();

// ------------------------------------------------------------------ Report Centre home
if ($id === '') {
    $total = count(ReportCatalog::all());
    page_head('Report Centre', '', 'You can open ' . count($mine) . ' of the ' . $total . ' reports. What you see is set by your role and by your administrator; every report only shows records inside your own branch and portfolio scope.');
    if (!$mine) {
        echo '<div class="card"><p>No reports have been assigned to your profile yet. Ask your administrator to give you access to the reports you need.</p></div>';
        return;
    }
    $cats = ReportCatalog::byCategory();
    echo '<div class="rep-top"><div class="gsearch rep-search">' . icon('search', 18) . '<input type="search" placeholder="Search reports by name, ID or content…" data-report-search aria-label="Search reports"></div></div>';
    echo '<div class="rep-cats"><button type="button" class="chip on" data-cat-filter="">All <span class="count">' . count($mine) . '</span></button>';
    foreach (ReportCatalog::CATEGORIES as $k => [$name]) {
        $n = count(array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']])));
        if ($n) echo '<button type="button" class="chip" data-cat-filter="' . $k . '"><b>' . $k . '</b> ' . e($name) . ' <span class="count">' . $n . '</span></button>';
    }
    echo '</div>';
    foreach (ReportCatalog::CATEGORIES as $k => [$name, $desc]) {
        $items = array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']]));
        $hidden = count($cats[$k] ?? []) - count($items);
        if (!$items && !$hidden) continue;
        if (!$items) continue;
        echo '<div class="card rep-cat" id="cat-' . $k . '" data-report-cat data-cat="' . $k . '"><div class="card-h"><h2><span class="rep-letter">' . $k . '</span> ' . e($name) . '</h2><span class="muted">' . count($items) . ' report' . (count($items) === 1 ? '' : 's') . '</span></div><p class="muted" style="margin:-.3rem 0 .6rem">' . e($desc) . '</p><div class="rep-list">';
        foreach ($items as $r) {
            echo '<a class="rep-item" data-report-item data-text="' . e(strtolower($r['id'] . ' ' . $r['title'] . ' ' . $r['info'])) . '" href="' . e(url('reports', ['r' => $r['id']])) . '"><span class="rep-id">' . e($r['id']) . '</span><span class="rep-body"><b>' . e($r['title']) . '</b><small>' . e($r['info']) . '</small></span><span class="rep-go">' . icon('chevron', 16) . '</span></a>';
        }
        echo '</div>' . ($hidden ? '<p class="muted" style="margin:.6rem 0 0;font-size:.82rem">' . $hidden . ' other report' . ($hidden === 1 ? '' : 's') . ' in this category ' . ($hidden === 1 ? 'is' : 'are') . ' not part of your profile.</p>' : '') . '</div>';
    }
    echo '<p class="muted" data-report-none hidden>No report matches your search.</p>';
    return;
}

// ------------------------------------------------------------------ one report
$def = ReportCatalog::get($id);
must((bool)$def, 'That report does not exist.');
if (!$def['available']) {
    page_head($def['id'] . ' · ' . $def['title'], btn('All reports', url('reports')), $def['info']);
    echo '<div class="card"><div class="notice">' . e($def['note']) . '</div></div>';
    return;
}
if (!isset($mine[$id])) {
    try { audit('Access denied', 'Security', 'report', $id, null, 'report not granted to this profile'); } catch (Throwable $e) {}
    http_response_code(403);
    throw new AppError('You have not been given access to this report.');
}

$uses = $def['filters'];
$p = ReportRun::params($_GET);
$periodic = str_contains($uses, 'f');
$run = ReportRun::execute($def, $p);
$rep = $run['rep']; $app = $run['applicable'];
$title = $def['title'];
$q = ReportRun::query($p, $periodic) + ['r' => $id];

// ---- toolbar: Report Centre, Print and every download format
$act = btn('Report Centre', url('reports')) . '<a class="btn" href="#" data-print>' . icon('file', 14) . ' Print</a>';
if (can('report.export')) {
    $act .= '<details class="dl"><summary class="btn primary">' . icon('download', 14) . ' Download</summary><div class="dl-menu">';
    foreach (ReportRun::FORMATS as $fk => [$fl, $fi]) $act .= '<a href="' . e(url('export', ['kind' => 'report', 'format' => $fk] + $q)) . '">' . icon($fi, 15) . ' ' . e($fl) . '</a>';
    $act .= '<small>The file contains exactly what is on screen, with your filters.</small></div></details>';
}
page_head($def['title'], $act, ReportCatalog::CATEGORIES[$def['cat']][0] . ' · ' . $def['id']);

// ---- filters
$pick = fn(array $keys) => array_diff_key($q, array_flip($keys));
echo '<form method="get" action="' . e(base_url() . '/index.php') . '" class="card filters2"><input type="hidden" name="p" value="reports"><input type="hidden" name="r" value="' . e($id) . '">';
if ($periodic) {
    echo '<div class="presets"><span class="muted">Period</span>';
    foreach (ReportRun::PRESETS as $k => $l) echo '<a class="pill' . ($p['preset'] === $k ? ' on' : '') . '" href="' . e(url('reports', $pick(['from', 'to', 'preset']) + ['preset' => $k])) . '">' . e($l) . '</a>';
    echo '</div><div class="fgrid"><div class="fld"><label>From</label><input type="date" name="from" value="' . e($p['from']) . '"></div><div class="fld"><label>To</label><input type="date" name="to" value="' . e($p['to']) . '"></div>';
} else echo '<div class="fgrid">';
$sel = function (string $name, string $label, array $opts, $val, string $all = 'All') {
    echo '<div class="fld"><label>' . e($label) . '</label><select name="' . e($name) . '"><option value="">' . e($all) . '</option>';
    foreach ($opts as $k => $v) echo '<option value="' . e($k) . '"' . ((string)$k === (string)$val ? ' selected' : '') . '>' . e($v) . '</option>';
    echo '</select></div>';
};
if ((str_contains($uses, 'b') || $app['branch']) && count(accessible_branch_ids()) > 1) $sel('branch', 'Branch', branch_options(), $p['branch'] ?: '', 'All my branches');
if ((str_contains($uses, 'o') || $app['officer']) && !portfolio_restricted()) { $o = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Branch Manager','COO') ORDER BY full_name") as $u) $o[$u['id']] = $u['full_name']; $sel('officer', 'Loan officer', $o, $p['officer'] ?: ''); }
if ((str_contains($uses, 'c') || $app['cashier']) && !ReportEngines::ownCashierOnly()) { $o = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Cashier','Finance Officer','Finance Manager','Branch Manager') ORDER BY full_name") as $u) $o[$u['id']] = $u['full_name']; $sel('cashier', 'Cashier', $o, $p['cashier'] ?: ''); }
if (str_contains($uses, 'm') || $app['method']) $sel('method', 'Payment channel', array_combine(Payments::METHODS, Payments::METHODS), $p['method']);
if (str_contains($uses, 'k') || $app['client']) $sel('client', 'Client', client_select_options(), $p['client'] ?: '');
if (str_contains($uses, 'l') || $app['loan']) echo '<div class="fld"><label>Loan number</label><input type="text" name="loan" value="' . e($p['loan']) . '" placeholder="e.g. BLM-2026-000001"></div>';
if ($app['status']) $sel('status', 'Status', array_combine($app['status'], $app['status']), $p['status']);
if (str_contains($uses, 'd')) echo '<div class="fld"><label>Days</label><input type="number" min="0" name="days" value="' . e($p['days'] ?: '') . '" placeholder="threshold"></div>';
if ($app['amount']) echo '<div class="fld"><label>Amount from</label><input type="text" name="min" value="' . e($p['min']) . '" placeholder="0"></div><div class="fld"><label>Amount to</label><input type="text" name="max" value="' . e($p['max']) . '" placeholder="any"></div>';
echo '<div class="fld wide"><label>Search in results</label><input type="search" name="q" value="' . e($p['q']) . '" placeholder="name, reference, phone…"></div>';
echo '<div class="fld actions-col"><label>&nbsp;</label><div class="fbtns"><button class="btn primary" type="submit">' . icon('search', 14) . ' Apply filters</button><a class="btn" href="' . e(url('reports', ['r' => $id])) . '">Reset</a></div></div></div></form>';

// ---- what is applied
$labels = ['Period' => ['from', 'to', 'preset'], 'Branch' => ['branch'], 'Loan officer' => ['officer'], 'Cashier' => ['cashier'], 'Channel' => ['method'], 'Client' => ['client'], 'Loan' => ['loan'], 'Days' => ['days'], 'Status' => ['status'], 'Amount from' => ['min'], 'Amount to' => ['max'], 'Text' => ['q']];
$chips = '';
$dflt = ReportRun::params([]);
foreach (ReportRun::filterText($def, $p) as $lab => $val) {
    if ($lab === 'Period' && !$p['preset'] && $p['from'] === $dflt['from'] && $p['to'] === $dflt['to']) continue;
    $chips .= '<a class="chip-x" href="' . e(url('reports', $pick($labels[$lab] ?? []))) . '" title="Remove this filter">' . e($lab) . ': <b>' . e($val) . '</b> ×</a>';
}
echo '<div class="applied">' . $chips . '<span class="muted">' . ($run['shown'] === $run['total'] ? $run['shown'] . ' row(s)' : 'Showing ' . $run['shown'] . ' of ' . $run['total'] . ' rows') . '</span></div>';

// ---- drill-down: reference columns open the source record through the search page
foreach (['loan_no' => 'Loan', 'client_no' => 'Client ID', 'receipt_no' => 'Receipt'] as $k => $dl) {
    if (isset($rep['cols'][$k]) && !is_array($rep['cols'][$k])) {
        $label = $rep['cols'][$k];
        $rep['cols'][$k] = [$label, fn($r) => ($r[$k] ?? '') === '' ? '' : '<a href="' . e(url('search', ['q' => $r[$k]])) . '">' . e($r[$k]) . '</a>'];
    }
}

echo '<div class="card report-doc">' . letterhead() . '<div class="rep-meta"><div><b>' . e($def['title']) . '</b> <span class="rep-id">' . e($def['id']) . '</span></div>'
    . '<div>' . ($periodic ? 'Period: ' . e(fmt_date($p['from'])) . ' to ' . e(fmt_date($p['to'])) . ' (business dates)' : 'As at business date ' . e(fmt_date(reporting_date()))) . '</div>'
    . '<div>Scope: ' . e(ReportRun::scopeText($p)) . '</div>'
    . '<div>Currency: ' . e(setting('currency')) . ' · Generated ' . e(fmt_dt(now())) . ' by ' . e(user()['full_name']) . ' · ' . count($rep['rows']) . ' row(s)</div></div>';
if ($rep['note'] !== '' || $def['note'] !== '') echo '<div class="notice info"><b>Basis:</b> ' . e($rep['note'] !== '' ? $rep['note'] : $def['note']) . '</div>';
data_table($rep['cols'], $rep['rows'], ['totals' => $rep['totals'], 'empty' => 'No data for these filters.']);
echo '<div class="rep-foot muted">' . e($def['id']) . ' · ' . e(business_name()) . ' · ' . e(fmt_dt(now())) . '</div></div>';
