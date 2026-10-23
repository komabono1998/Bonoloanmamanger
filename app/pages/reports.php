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
    echo '<div class="rep-cats">';
    foreach (ReportCatalog::CATEGORIES as $k => [$name]) {
        $n = count(array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']])));
        if ($n) echo '<a class="chip" href="#cat-' . $k . '"><b>' . $k . '</b> ' . e($name) . ' <span class="count">' . $n . '</span></a>';
    }
    echo '</div>';
    foreach (ReportCatalog::CATEGORIES as $k => [$name, $desc]) {
        $items = array_filter($cats[$k] ?? [], fn($r) => isset($mine[$r['id']]));
        $hidden = count($cats[$k] ?? []) - count($items);
        if (!$items && !$hidden) continue;
        if (!$items) continue;
        echo '<div class="card rep-cat" id="cat-' . $k . '" data-report-cat><div class="card-h"><h2><span class="rep-letter">' . $k . '</span> ' . e($name) . '</h2><span class="muted">' . count($items) . ' report' . (count($items) === 1 ? '' : 's') . '</span></div><p class="muted" style="margin:-.3rem 0 .6rem">' . e($desc) . '</p><div class="rep-list">';
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

$days = max(1, setting_int('report_default_days'));
$asOf = reporting_date();
$uses = $def['filters'];
$f = ['from' => get('from') ?: date('Y-m-d', strtotime("-$days days", strtotime($asOf))), 'to' => get('to') ?: $asOf, 'officer' => (int)get('officer'), 'cashier' => (int)get('cashier'), 'method' => get('method'),
    'client' => (int)get('client'), 'branch' => (int)get('branch'), 'loan' => trim((string)get('loan')), 'days' => (int)get('days')];
if (!is_date($f['from'])) $f['from'] = date('Y-m-d', strtotime("-$days days", strtotime($asOf)));
if (!is_date($f['to'])) $f['to'] = $asOf;
must($f['from'] <= $f['to'], 'The start date must not be after the end date.');
$periodic = str_contains($uses, 'f');
$params = array_filter(['r' => $id, 'from' => $periodic ? $f['from'] : '', 'to' => $periodic ? $f['to'] : '', 'officer' => $f['officer'] ?: '', 'cashier' => $f['cashier'] ?: '', 'method' => $f['method'], 'client' => $f['client'] ?: '',
    'branch' => $f['branch'] ?: '', 'loan' => $f['loan'], 'days' => $f['days'] ?: ''], fn($v) => $v !== '');
$rep = ReportEngines::run($def['engine'], $f);
$title = $def['title'];

$act = btn('Report Centre', url('reports')) . '<a class="btn" href="#" data-print>' . icon('file', 14) . ' Print / PDF</a>';
if (can('report.export')) $act .= btn('CSV', url('export', ['kind' => 'report', 'format' => 'csv'] + $params)) . btn('Excel', url('export', ['kind' => 'report', 'format' => 'xls'] + $params));
page_head($def['title'], $act, ReportCatalog::CATEGORIES[$def['cat']][0] . ' · ' . $def['id']);

$filters = [];
if ($periodic) { $filters['from'] = ['label' => 'From', 'type' => 'date']; $filters['to'] = ['label' => 'To', 'type' => 'date']; }
if (str_contains($uses, 'b') && count(accessible_branch_ids()) > 1) $filters['branch'] = ['label' => 'Branch', 'type' => 'select', 'all' => 'All my branches', 'options' => branch_options()];
if (str_contains($uses, 'o') && !portfolio_restricted()) { $o = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Branch Manager','COO') ORDER BY full_name") as $u) $o[$u['id']] = $u['full_name']; $filters['officer'] = ['label' => 'Loan officer', 'type' => 'select', 'options' => $o]; }
if (str_contains($uses, 'c') && !ReportEngines::ownCashierOnly()) { $o = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Cashier','Finance Officer','Finance Manager','Branch Manager') ORDER BY full_name") as $u) $o[$u['id']] = $u['full_name']; $filters['cashier'] = ['label' => 'Cashier', 'type' => 'select', 'options' => $o]; }
if (str_contains($uses, 'm')) $filters['method'] = ['label' => 'Payment channel', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)];
if (str_contains($uses, 'k')) $filters['client'] = ['label' => 'Client', 'type' => 'select', 'options' => client_select_options()];
if (str_contains($uses, 'l')) $filters['loan'] = ['label' => 'Loan number', 'placeholder' => 'e.g. BLM-2026-000001'];
if (str_contains($uses, 'd')) $filters['days'] = ['label' => 'Days', 'placeholder' => 'threshold'];
if ($filters) filter_bar($filters, ['from' => $f['from'], 'to' => $f['to'], 'officer' => $f['officer'] ?: '', 'cashier' => $f['cashier'] ?: '', 'method' => $f['method'], 'client' => $f['client'] ?: '', 'branch' => $f['branch'] ?: '', 'loan' => $f['loan'], 'days' => $f['days'] ?: ''], 'reports', ['r' => $id]);

// scope statement: a report must never present a partial total as institution-wide
$allBranches = count(all_branch_options(false));
$ids = accessible_branch_ids();
$chosen = $f['branch'] ? [$f['branch']] : $ids;
$scopeTxt = count($chosen) >= $allBranches && $allBranches > 0 ? 'All branches (' . count($chosen) . ')' : implode(', ', array_map('branch_name', $chosen)) . ' — ' . count($chosen) . ' of ' . $allBranches . ' branches';
$limits = [];
if (portfolio_restricted()) $limits[] = 'only loans assigned to you and clients you registered';
if (ReportEngines::ownCashierOnly()) $limits[] = 'only your own transactions';
if (str_contains($uses, 'o') && $f['officer']) $limits[] = 'loan officer: ' . (val('SELECT full_name FROM users WHERE id = ?', [$f['officer']]) ?: '');
if (str_contains($uses, 'c') && $f['cashier'] && !ReportEngines::ownCashierOnly()) $limits[] = 'cashier: ' . (val('SELECT full_name FROM users WHERE id = ?', [$f['cashier']]) ?: '');
if (str_contains($uses, 'm') && $f['method'] !== '') $limits[] = 'channel: ' . $f['method'];
if (str_contains($uses, 'k') && $f['client']) $limits[] = 'client: ' . (val('SELECT full_name FROM clients WHERE id = ?', [$f['client']]) ?: '');
if (str_contains($uses, 'l') && $f['loan'] !== '') $limits[] = 'loan: ' . $f['loan'];
if (str_contains($uses, 'd') && $f['days']) $limits[] = 'days: ' . $f['days'];

// drill-down: reference columns open the source record through the search page
foreach (['loan_no' => 'Loan', 'client_no' => 'Client ID', 'receipt_no' => 'Receipt'] as $k => $dl) {
    if (isset($rep['cols'][$k]) && !is_array($rep['cols'][$k])) {
        $label = $rep['cols'][$k];
        $rep['cols'][$k] = [$label, fn($r) => ($r[$k] ?? '') === '' ? '' : '<a href="' . e(url('search', ['q' => $r[$k]])) . '">' . e($r[$k]) . '</a>'];
    }
}

echo '<div class="card report-doc">' . letterhead() . '<div class="rep-meta"><div><b>' . e($def['title']) . '</b> <span class="rep-id">' . e($def['id']) . '</span></div>'
    . '<div>' . ($periodic ? 'Period: ' . e(fmt_date($f['from'])) . ' to ' . e(fmt_date($f['to'])) . ' (business dates)' : 'As at business date ' . e(fmt_date($asOf))) . '</div>'
    . '<div>Scope: ' . e($scopeTxt) . ($limits ? ' · ' . e(implode(' · ', $limits)) : '') . '</div>'
    . '<div>Currency: ' . e(setting('currency')) . ' · Generated ' . e(fmt_dt(now())) . ' by ' . e(user()['full_name']) . ' · ' . count($rep['rows']) . ' row(s)</div></div>';
if ($rep['note'] !== '' || $def['note'] !== '') echo '<div class="notice info"><b>Basis:</b> ' . e($rep['note'] !== '' ? $rep['note'] : $def['note']) . '</div>';
data_table($rep['cols'], $rep['rows'], ['totals' => $rep['totals'], 'empty' => 'No data for these filters.']);
echo '<div class="rep-foot muted">' . e($def['id']) . ' · ' . e(business_name()) . ' · ' . e(fmt_dt(now())) . '</div></div>';
