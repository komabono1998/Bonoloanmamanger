<?php
/** Who can open which report: a matrix of reports (rows) by role (columns). Individual people are adjusted on their user page. */
$title = 'Report access';
$roles = array_column(rows('SELECT role FROM roles ORDER BY sort_order'), 'role');
$a = get('a');

if ($a === 'save') post_action(function () use ($roles) {
    require_perm('user.manage');
    $want = (array)($_POST['g'] ?? []);   // g[role][report_id] = 1
    $changes = [];
    tx(function () use ($roles, $want, &$changes) {
        $now = ReportCatalog::roleRows();
        foreach ($roles as $role) {
            foreach (ReportCatalog::all() as $id => $r) {
                if (!$r['available']) continue;
                $has = !empty($want[$role][$id]);
                $def = ReportCatalog::defaultAllows($role, $id);
                $cur = isset($now[$role][$id]) ? $now[$role][$id] === 1 : $def;
                if ($has === $cur) continue;
                $changes[] = ($has ? '+' : '-') . $role . ':' . $id;
                if ($has === $def) q('DELETE FROM report_role_access WHERE role = ? AND report_id = ?', [$role, $id]);
                else q('INSERT INTO report_role_access(role, report_id, allowed, updated_by, updated_at) VALUES (?,?,?,?,?) ON CONFLICT (role, report_id) DO UPDATE SET allowed = excluded.allowed, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
                    [$role, $id, $has ? 1 : 0, uid(), now()]);
            }
        }
        if ($changes) audit('Report access changed', 'Users', 'report_access', 'roles', null, $changes, clean(post('reason')));
    });
    ReportCatalog::reset();
    return $changes ? count($changes) . ' change(s) saved.' : 'Nothing changed.';
});
if ($a === 'reset') post_action(function () use ($roles) {
    require_perm('user.manage');
    $role = post('role');
    must(in_array($role, $roles, true), 'Choose a role.');
    q('DELETE FROM report_role_access WHERE role = ?', [$role]);
    audit('Report access changed', 'Users', 'report_access', $role, null, 'reset to recommended defaults', clean(post('reason')));
    ReportCatalog::reset();
    return $role . ' reset to the recommended report access.';
});

page_head('Report access', btn('Users & Access', url('users')), 'Choose which reports each role can open in the Report Centre. A user also needs the “View reports” permission. Reports always stay inside the user’s own branch and portfolio scope; you can add or remove reports for one person on their user page.');
echo '<div class="notice info">Defaults follow the reporting specification (for example a Loan Officer gets portfolio, client, application, collections and arrears reports for assigned records, and no finance or EOD reports). Changes are audited.</div>';
$now = ReportCatalog::roleRows();
form_open(url('reportaccess', ['a' => 'save']), ['attrs' => 'style="display:block"']);
echo '<div class="card"><div class="tbl-wrap"><table class="tbl nofilter matrix"><thead><tr><th>Report</th>';
foreach ($roles as $r) echo '<th class="c">' . e($r) . '</th>';
echo '</tr></thead><tbody>';
foreach (ReportCatalog::byCategory() as $cat => $items) {
    echo '<tr><td colspan="' . (count($roles) + 1) . '" class="cat-row">' . e($cat . '. ' . ReportCatalog::CATEGORIES[$cat][0]) . '</td></tr>';
    foreach ($items as $r) {
        echo '<tr><td><span class="rep-id">' . e($r['id']) . '</span> ' . e($r['title']) . (!$r['available'] ? ' <span class="badge b-muted">not available</span>' : '') . '</td>';
        foreach ($roles as $role) {
            if (!$r['available']) { echo '<td class="c muted">–</td>'; continue; }
            $on = ReportCatalog::roleAllows($role, $r['id'], $now);
            $custom = isset($now[$role][$r['id']]);
            echo '<td class="c' . ($custom ? ' custom' : '') . '"><input type="checkbox" name="g[' . e($role) . '][' . e($r['id']) . ']" value="1"' . ($on ? ' checked' : '') . ' aria-label="' . e($role . ' — ' . $r['id']) . '"></td>';
        }
        echo '</tr>';
    }
}
echo '</tbody></table></div>';
field('reason', 'Reason for the change (audited)', ['span' => 4]);
echo '<div class="form-actions">'; submit_btn('Save report access'); echo '</div></div>';
form_close();
echo '<div class="card"><h2>Reset a role to the recommended defaults</h2>';
form_open(url('reportaccess', ['a' => 'reset']), ['attrs' => 'style="grid-template-columns:1fr 1fr auto;align-items:end" data-confirm="Reset this role to the recommended report access?"']);
field('role', 'Role', ['type' => 'select', 'options' => array_combine($roles, $roles)]); field('reason', 'Reason');
echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Reset', 'danger'); echo '</div>'; form_close(); echo '</div>';
