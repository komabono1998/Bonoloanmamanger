<?php
$title = 'Audit trail';
$from = get('from') ?: date('Y-m-d', strtotime('-7 days')); $to = get('to') ?: today();
$u = (int)get('user'); $mod = get('module'); $q = get('q');
$w = ["date(a.at) BETWEEN ? AND ?"]; $p = [$from, $to];
if ($u) { $w[] = 'a.user_id = ?'; $p[] = $u; }
if ($mod !== '') { $w[] = 'a.module = ?'; $p[] = $mod; }
if ($q !== '') { $w[] = '(a.action LIKE ? OR a.record_id LIKE ? OR a.reason LIKE ? OR a.new_value LIKE ? OR a.prev_value LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$list = rows('SELECT a.* FROM audit_logs a WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT 1000', $p);
page_head('Audit trail', can('report.export') ? btn('Export CSV', url('export', ['kind' => 'audit', 'from' => $from, 'to' => $to, 'user' => $u, 'module' => $mod, 'q' => $q])) : '', 'Append-only record of important actions. Entries cannot be edited or deleted by anyone, including administrators.');
$users = []; foreach (rows('SELECT id, full_name FROM users ORDER BY full_name') as $x) $users[$x['id']] = $x['full_name'];
$mods = []; foreach (rows('SELECT DISTINCT module FROM audit_logs ORDER BY module') as $x) $mods[$x['module']] = $x['module'];
filter_bar(['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'], 'user' => ['label' => 'User', 'type' => 'select', 'options' => $users],
    'module' => ['label' => 'Module', 'type' => 'select', 'options' => $mods], 'q' => ['label' => 'Search', 'placeholder' => 'action, record, reason']], ['from' => $from, 'to' => $to, 'user' => $u ?: '', 'module' => $mod, 'q' => $q], 'audit');
data_table(['at' => ['When', 'dt'], 'username' => 'User', 'business_date' => ['Bus. date', 'date'], 'module' => 'Module', 'action' => 'Action', 'record' => ['Record', fn($r) => e(trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? '')))],
    'prev_value' => ['Before', fn($r) => '<span style="max-width:220px;display:inline-block;overflow-wrap:anywhere">' . e($r['prev_value']) . '</span>'], 'new_value' => ['After', fn($r) => '<span style="max-width:220px;display:inline-block;overflow-wrap:anywhere">' . e($r['new_value']) . '</span>'],
    'reason' => 'Reason', 'approval_ref' => 'Approval ref', 'ip' => 'IP'], $list, ['empty' => 'No audit entries match.']);
