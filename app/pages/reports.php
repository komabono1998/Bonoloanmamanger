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
    $first = ''; foreach (ReportCatalog::CATEGORIES as $k => $_) { if (array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']]))) { $first = $k; break; } }
    echo '<div class="rep-layout"><aside class="rep-rail"><div class="gsearch rep-search">' . icon('search', 18) . '<input type="search" placeholder="Find a report…" data-report-search aria-label="Search reports"></div>';
    echo '<div class="rep-rail-h">Report categories</div>';
    echo '<button type="button" class="rail-b" data-cat-filter=""><span class="rl">∗</span><span class="rn">All reports</span><span class="count">' . count($mine) . '</span></button>';
    foreach (ReportCatalog::CATEGORIES as $k => [$name]) {
        $n = count(array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']])));
        if ($n) echo '<button type="button" class="rail-b' . ($k === $first ? ' on' : '') . '" data-cat-filter="' . $k . '"><span class="rl">' . $k . '</span><span class="rn">' . e($name) . '</span><span class="count">' . $n . '</span></button>';
    }
    echo '</aside><div class="rep-main">';
    foreach (ReportCatalog::CATEGORIES as $k => [$name, $desc]) {
        $items = array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']]));
        if (!$items) continue;
        echo '<section class="card rep-cat" id="cat-' . $k . '" data-report-cat data-cat="' . $k . '"' . ($k === $first ? '' : ' hidden') . '><div class="card-h"><h2><span class="rep-letter">' . $k . '</span> ' . e($name) . '</h2><span class="muted">' . count($items) . ' report' . (count($items) === 1 ? '' : 's') . '</span></div><p class="muted" style="margin:-.3rem 0 .6rem">' . e($desc) . '</p><div class="rep-list">';
        foreach ($items as $r) {
            echo '<a class="rep-item" data-report-item data-text="' . e(strtolower($r['id'] . ' ' . $r['title'] . ' ' . $r['info'])) . '"' . ($k === $first ? '' : ' hidden') . ' href="' . e(url('reports', ['r' => $r['id']])) . '"><span class="rep-id">' . e($r['id']) . '</span><span class="rep-body"><b>' . e($r['title']) . '</b><small>' . e($r['info']) . '</small></span><span class="rep-go">' . icon('chevron', 16) . '</span></a>';
        }
        echo '</div></section>';
    }
    echo '<p class="muted" data-report-none hidden>No report matches your search.</p></div></div>';
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
$offOpts = [];
if ((str_contains($uses, 'o') || $app['officer']) && !portfolio_restricted()) foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Branch Manager','COO') ORDER BY full_name") as $u) $offOpts[$u['id']] = $u['full_name'];
$scopes = [];
if ((str_contains($uses, 'b') || $app['branch']) && count(accessible_branch_ids()) > 1) $scopes['branch'] = ['By branch', 'Branch', branch_options(), $p['branch'] ?: '', 'All my branches'];
if ($offOpts) $scopes['officer'] = ['By loan officer', 'Loan officer', $offOpts, $p['officer'] ?: '', 'All loan officers'];
if ($app['loan']) $scopes['type'] = ['By loan type', 'Loan type (repayment)', ['daily' => 'Daily repayment loans', 'weekly' => 'Weekly repayment loans', 'monthly' => 'Monthly repayment loans'], $p['type'], 'All loan types'];
$curScope = ''; foreach ($scopes as $k => $sc) if ((string)$sc[3] !== '') { $curScope = $k; break; }
$selHtml = function (string $name, string $label, array $opts, $val, string $all = 'All') {
    $h = '<div class="fld"><label>' . e($label) . '</label><select name="' . e($name) . '"><option value="">' . e($all) . '</option>';
    foreach ($opts as $k => $v) $h .= '<option value="' . e($k) . '"' . ((string)$k === (string)$val ? ' selected' : '') . '>' . e($v) . '</option>';
    return $h . '</select></div>';
};
echo '<form method="get" action="' . e(base_url() . '/index.php') . '" class="card filters2 qf"><input type="hidden" name="p" value="reports"><input type="hidden" name="r" value="' . e($id) . '">';
if ($periodic) {
    echo '<div class="qf-row"><div class="qf-label">1 · Date range</div><div class="qf-body"><div class="presets">';
    foreach (ReportRun::PRESETS as $k => $l) echo '<a class="pill' . ($p['preset'] === $k ? ' on' : '') . '" href="' . e(url('reports', $pick(['from', 'to', 'preset']) + ['preset' => $k])) . '">' . e($l) . '</a>';
    echo '</div><div class="qf-dates"><div class="fld"><label>From</label><input type="date" name="from" value="' . e($p['from']) . '"></div><div class="fld"><label>To</label><input type="date" name="to" value="' . e($p['to']) . '"></div></div></div></div>';
}
if ($scopes) {
    echo '<div class="qf-row"><div class="qf-label">' . ($periodic ? '2' : '1') . ' · Scope</div><div class="qf-body"><div class="seg" role="tablist">';
    echo '<button type="button" class="seg-b' . ($curScope === '' ? ' on' : '') . '" data-scope="">Everything</button>';
    foreach ($scopes as $k => $sc) echo '<button type="button" class="seg-b' . ($curScope === $k ? ' on' : '') . '" data-scope="' . $k . '">' . e($sc[0]) . '</button>';
    echo '</div><div class="qf-scopes">';
    foreach ($scopes as $k => $sc) echo '<div class="qf-scope" data-scope-box="' . $k . '"' . ($curScope === $k ? '' : ' hidden') . '>' . $selHtml($k, $sc[1], $sc[2], $sc[3], $sc[4]) . '</div>';
    echo '</div></div></div>';
}
echo '<div class="qf-row"><div class="qf-label">' . ($periodic ? ($scopes ? '3' : '2') : ($scopes ? '2' : '1')) . ' · Narrow down</div><div class="qf-body"><div class="fgrid">';
if ((str_contains($uses, 'c') || $app['cashier']) && !ReportEngines::ownCashierOnly()) { $o = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Cashier','Finance Officer','Finance Manager','Branch Manager') ORDER BY full_name") as $u) $o[$u['id']] = $u['full_name']; echo $selHtml('cashier', 'Cashier', $o, $p['cashier'] ?: ''); }
if (str_contains($uses, 'm') || $app['method']) echo $selHtml('method', 'Payment channel', array_combine(Payments::METHODS, Payments::METHODS), $p['method']);
if (str_contains($uses, 'k') || $app['client']) echo $selHtml('client', 'Client', client_select_options(), $p['client'] ?: '');
if (str_contains($uses, 'l') || $app['loan']) echo '<div class="fld"><label>Loan number</label><input type="text" name="loan" value="' . e($p['loan']) . '" placeholder="e.g. BLM-2026-000001"></div>';
if (str_contains($uses, 'v')) echo $selHtml('view', 'Statement', Gl::VIEWS, $p['view'] ?: 'tb', 'Trial balance');
if ($app['status']) echo $selHtml('status', 'Status', array_combine($app['status'], $app['status']), $p['status']);
if (str_contains($uses, 'd')) echo '<div class="fld"><label>Days</label><input type="number" min="0" name="days" value="' . e($p['days'] ?: '') . '" placeholder="threshold"></div>';
if ($app['amount']) echo '<div class="fld"><label>Amount from</label><input type="text" name="min" value="' . e($p['min']) . '" placeholder="0"></div><div class="fld"><label>Amount to</label><input type="text" name="max" value="' . e($p['max']) . '" placeholder="any"></div>';
echo '<div class="fld wide"><label>Search in results</label><input type="search" name="q" value="' . e($p['q']) . '" placeholder="name, reference, phone…"></div>';
echo '</div></div></div><div class="qf-actions"><button class="btn primary" type="submit">' . icon('search', 14) . ' Run report</button><a class="btn" href="' . e(url('reports', ['r' => $id])) . '">Reset</a></div></form>';
echo '<script>document.querySelectorAll(".qf").forEach(function(f){f.querySelectorAll("[data-scope]").forEach(function(b){b.addEventListener("click",function(){var s=b.getAttribute("data-scope");f.querySelectorAll("[data-scope]").forEach(function(x){x.classList.toggle("on",x===b)});f.querySelectorAll("[data-scope-box]").forEach(function(box){var on=box.getAttribute("data-scope-box")===s;box.hidden=!on;if(!on){var sel=box.querySelector("select");if(sel)sel.value=""}})})})})</script>';

// ---- what is applied
$labels = ['Period' => ['from', 'to', 'preset'], 'Branch' => ['branch'], 'Loan officer' => ['officer'], 'Cashier' => ['cashier'], 'Channel' => ['method'], 'Client' => ['client'], 'Loan' => ['loan'], 'Days' => ['days'], 'Status' => ['status'], 'Statement' => ['view'], 'Loan type' => ['type'], 'Amount from' => ['min'], 'Amount to' => ['max'], 'Text' => ['q']];
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

$u = user();
$facts = [
    $periodic ? 'Period' : 'As at' => $periodic ? fmt_date($p['from']) . ' to ' . fmt_date($p['to']) . ' (business dates)' : 'Business date ' . fmt_date(reporting_date()),
    'Scope' => ReportRun::scopeText($p),
    'Currency' => setting('currency'),
    'Rows' => (string)count($rep['rows']),
    'Generated on' => fmt_dt(now()),
    'Generated by' => $u['full_name'] . ' · ' . $u['role'],
];
$fl = ReportRun::filterText($def, $p); unset($fl['Period']);
if ($fl) $facts['Filters'] = implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($fl), $fl));
echo '<div class="card report-doc" style="--bc:' . e(brand()['color']) . '">' . letterhead()
    . '<div class="rep-banner"><div><span class="rep-id">' . e($def['id']) . '</span><h2>' . e($def['title']) . '</h2><small>' . e(ReportCatalog::CATEGORIES[$def['cat']][0]) . '</small></div>'
    . '<div class="rep-stamp">Generated ' . e(fmt_dt(now())) . '<br>by ' . e($u['full_name']) . '</div></div><div class="rep-facts">';
foreach ($facts as $k => $v) echo '<div><span>' . e($k) . '</span><b>' . e($v) . '</b></div>';
echo '</div>';
if ($rep['note'] !== '' || $def['note'] !== '') echo '<div class="notice info"><b>Basis:</b> ' . e($rep['note'] !== '' ? $rep['note'] : $def['note']) . '</div>';
data_table($rep['cols'], $rep['rows'], ['totals' => $rep['totals'], 'empty' => 'No data for these filters.']);
echo '<div class="rep-foot muted">' . e($def['id']) . ' · ' . e(business_name()) . ' · ' . e(fmt_dt(now())) . '</div></div>';
