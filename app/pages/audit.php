<?php
$title = 'Audit trail';
$from = get('from') ?: date('Y-m-d', strtotime('-7 days')); $to = get('to') ?: today();
$u = (int)get('user'); $mod = get('module'); $q = get('q'); $chosen = (int)get('branch');

// ---- audit visibility follows the role: own activity / financial scope / branch / institution / technical
$scopes = []; $sp = [];
if (can('audit.all')) $scopes[] = '1=1';
if (can('audit.branch')) $scopes[] = '(' . branch_scope('a.branch_id') . ')';
if (can('audit.financial')) { $scopes[] = "(a.module IN ('Payments','Disbursements','Cashier','Expenses','End of Day','Penalties','Loans','Export') AND " . branch_scope('a.branch_id') . ')'; }
if (can('audit.technical')) $scopes[] = "a.module IN ('Security','Users','Backup','Settings','Branches','Administration','Documents')";
if (can('audit.own')) { $scopes[] = '(a.user_id = ?)'; $sp[] = uid(); }
$scopeSql = $scopes ? '(' . implode(' OR ', $scopes) . ')' : '1=0';

$w = ['substr(a.at,1,10) BETWEEN ? AND ?', $scopeSql]; $p = array_merge([$from, $to], $sp);
if ($u) { $w[] = 'a.user_id = ?'; $p[] = $u; }
if ($mod !== '') { $w[] = 'a.module = ?'; $p[] = $mod; }
if ($chosen) { $w[] = 'a.branch_id = ?'; $p[] = $chosen; }
if ($q !== '') { $w[] = '(a.action LIKE ? OR a.record_id LIKE ? OR a.reason LIKE ? OR a.new_value LIKE ? OR a.prev_value LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$list = rows('SELECT a.* FROM audit_logs a WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT 1000', $p);
$brn = array_column(rows('SELECT id, name FROM branches'), 'name', 'id');
foreach ($list as &$r) $r['branch'] = $brn[$r['branch_id']] ?? '';
unset($r);
$scopeName = can('audit.all') ? 'institution-wide' : (can('audit.branch') ? 'branch scope' : (can('audit.technical') ? 'technical & security scope' : (can('audit.financial') ? 'financial scope' : 'your own activity')));
page_head('Audit trail', can('report.export') && can('audit.all') ? btn('Export CSV', url('export', ['kind' => 'audit', 'from' => $from, 'to' => $to, 'user' => $u, 'module' => $mod])) : '',
    'Append-only record of important actions (' . $scopeName . '). Entries cannot be edited or deleted by anyone, including administrators and the database owner role.');
$users = []; foreach (rows('SELECT id, full_name FROM users ORDER BY full_name') as $x) $users[$x['id']] = $x['full_name'];
$mods = []; foreach (rows('SELECT DISTINCT module FROM audit_logs ORDER BY module') as $x) $mods[$x['module']] = $x['module'];
$f = ['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'], 'user' => ['label' => 'User', 'type' => 'select', 'options' => $users],
    'module' => ['label' => 'Module', 'type' => 'select', 'options' => $mods]];
if (count(accessible_branch_ids()) > 1) $f['branch'] = ['label' => 'Branch', 'type' => 'select', 'options' => branch_options()];
$f['q'] = ['label' => 'Search', 'placeholder' => 'action, record, reason'];
filter_bar($f, ['from' => $from, 'to' => $to, 'user' => $u ?: '', 'module' => $mod, 'q' => $q, 'branch' => $chosen ?: ''], 'audit');
data_table(['at' => ['When', 'dt'], 'username' => 'User', 'branch' => 'Branch', 'business_date' => ['Bus. date', 'date'], 'module' => 'Module', 'action' => 'Action', 'record' => ['Record', fn($r) => e(trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? '')))],
    'prev_value' => ['Before', fn($r) => '<span style="max-width:220px;display:inline-block;overflow-wrap:anywhere">' . e($r['prev_value']) . '</span>'], 'new_value' => ['After', fn($r) => '<span style="max-width:220px;display:inline-block;overflow-wrap:anywhere">' . e($r['new_value']) . '</span>'],
    'reason' => 'Reason', 'approval_ref' => 'Approval ref', 'ip' => 'IP'], $list, ['empty' => 'No audit entries match.']);
