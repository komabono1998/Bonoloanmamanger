<?php
$title = 'Security monitoring';
$a = get('a');
if ($a === 'unlock') post_action(function () {
    require_perm('user.manage');
    $id = (int)post('user_id');
    must((bool)val('SELECT COUNT(*) FROM users WHERE id = ?', [$id]), 'User not found.');
    q('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    audit('Account unlocked', 'Security', 'user', $id, 'locked', 'unlocked', clean(post('reason')));
    return 'Account unlocked.';
});

$from = get('from') ?: date('Y-m-d', strtotime('-7 days')); $to = get('to') ?: today(); $res = get('result'); $q = get('q');
$w = ['substr(e.at,1,10) BETWEEN ? AND ?']; $p = [$from, $to];
if ($res === 'fail') $w[] = 'e.success = 0'; elseif ($res === 'ok') $w[] = 'e.success = 1'; elseif ($res === 'new_ip') $w[] = "e.reason LIKE 'new IP%'";
if ($q !== '') { $w[] = '(e.username LIKE ? OR e.ip LIKE ?)'; array_push($p, "%$q%", "%$q%"); }
$events = rows('SELECT e.*, u.role FROM login_events e LEFT JOIN users u ON u.id = e.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY e.id DESC LIMIT 500', $p);
page_head('Security monitoring', '', 'Sign-in activity, failures, lock-outs and unusual access. Login history is append-only.');

$h = System::health();
echo '<div class="kpis">' . kpi('Failed sign-ins (24 h)', num($h['security']['failed_24h']), '', $h['security']['failed_24h'] > 20 ? 'bad' : '') . kpi('Locked accounts', num($h['security']['locked']), '', $h['security']['locked'] ? 'warn' : 'ok')
    . kpi('Sign-ins from a new IP (24 h)', num($h['security']['new_ip_24h']), 'review privileged users', $h['security']['new_ip_24h'] ? 'warn' : '')
    . kpi('MFA enrolled', num($h['users']['mfa']) . ' / ' . num($h['users']['active']), '', $h['mfa_ok'] ? 'ok' : 'bad') . '</div>';
if ($h['mfa_missing']) echo '<div class="notice bad">MFA is required but not enrolled for: ' . e(implode(', ', $h['mfa_missing'])) . '</div>';

$locked = rows('SELECT id, username, full_name, role, locked_until, failed_attempts FROM users WHERE locked_until > ?', [now()]);
if ($locked) {
    echo '<div class="card"><h2>Locked accounts</h2>';
    data_table(['username' => 'User', 'role' => 'Role', 'locked_until' => ['Locked until', 'dt'],
        'act' => ['', fn($r) => can('user.manage') ? '<form method="post" action="' . e(url('security', ['a' => 'unlock'])) . '" style="display:flex;gap:.3rem">' . csrf_field() . '<input type="hidden" name="user_id" value="' . (int)$r['id'] . '"><input type="text" name="reason" placeholder="reason" required style="width:140px"><button class="btn sm">Unlock</button></form>' : '']], $locked, ['class' => 'nofilter']);
    echo '</div>';
}

$top = rows("SELECT ip, COUNT(*) AS n, COUNT(DISTINCT username) AS users FROM login_events WHERE success = 0 AND at > ? GROUP BY ip ORDER BY n DESC LIMIT 8", [date('Y-m-d H:i:s', time() - 86400)]);
echo '<div class="grid g2"><div class="card"><h2>Failed sign-ins by address (24 h)</h2>';
data_table(['ip' => 'IP address', 'n' => ['Failures', 'num'], 'users' => ['Distinct usernames', 'num']], $top, ['class' => 'nofilter', 'empty' => 'No failures.']);
echo '</div><div class="card"><h2>MFA status by user</h2>';
data_table(['username' => 'User', 'role' => 'Role', 'mfa_enabled' => ['MFA', fn($r) => $r['mfa_enabled'] ? badge('Active') : (mfa_required_for($r) ? badge('Pending') . ' required' : '<span class="muted">off</span>')], 'last_login' => ['Last login', 'dt'], 'last_ip' => 'Last IP'],
    rows("SELECT username, role, mfa_enabled, last_login, last_ip FROM users WHERE status = 'active' ORDER BY role, username"), ['class' => 'nofilter']);
echo '</div></div>';

filter_bar(['from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'], 'result' => ['label' => 'Result', 'type' => 'select', 'options' => ['fail' => 'Failures', 'ok' => 'Successful', 'new_ip' => 'New IP address']],
    'q' => ['label' => 'User / IP', 'placeholder' => 'search']], ['from' => $from, 'to' => $to, 'result' => $res, 'q' => $q], 'security');
echo '<div class="card"><h2>Sign-in log</h2>';
data_table(['at' => ['When', 'dt'], 'username' => 'Username', 'role' => 'Role', 'ip' => 'IP address', 'success' => ['Result', fn($r) => $r['success'] ? 'success' : '<b class="ico-bad">failed</b>'], 'reason' => 'Note', 'user_agent' => 'Device'], $events, ['empty' => 'No sign-ins in this range.']);
echo '</div>';
