<?php
/** Loan process roles: the System Administrator decides, at any time, which roles carry each step of the loan process. */
$title = 'Loan Process Roles';
$a = get('a');

if ($a === 'save') post_action(function () {
    require_perm('settings.manage');
    $assign = [];
    foreach (Process::ROLES as $role) foreach (array_keys(Process::STEPS) as $step) if (!empty($_POST['m'][$role][$step])) $assign[$role][] = $step;
    Process::apply($assign);
    set_setting('process_profile', 'custom', uid());
    $gaps = Process::uncovered();
    return [$gaps ? 'Saved, but no role holds: ' . implode('; ', $gaps) . '. The process will stall at those steps.' : 'Loan process roles saved. They take effect immediately.', url('process')];
});
if ($a === 'preset') post_action(function () {
    require_perm('settings.manage');
    Process::applyPreset(post('preset'));
    return ['Profile applied: ' . Process::PRESETS[post('preset')][0] . '.', url('process')];
});

page_head('Loan Process Roles', '', 'Who prepares, verifies, approves and releases a loan. Change it whenever the institution reorganises; menus and permissions follow immediately. Nobody can approve, authorise or verify their own work, whatever roles are chosen.');
$cur = setting('process_profile');
$m = Process::matrix();
$gaps = Process::uncovered();
if ($gaps) echo '<div class="notice bad">No role holds: ' . e(implode('; ', $gaps)) . '. The loan process cannot complete until a role is given these steps.</div>';

echo '<div class="card"><h2>Ready-made profiles</h2><div class="presets">';
foreach (Process::PRESETS as $k => [$name]) {
    echo '<form method="post" action="' . e(url('process', ['a' => 'preset'])) . '" style="display:inline" data-confirm="Apply this profile? It replaces the loan-process permissions of the roles below.">' . csrf_field()
        . '<input type="hidden" name="preset" value="' . e($k) . '"><button class="btn ' . ($cur === $k ? 'primary' : '') . '" type="submit">' . e($name) . ($cur === $k ? ' (current)' : '') . '</button></form> ';
}
echo '</div><p class="muted">' . ($cur === 'custom' ? 'The matrix below has been customised.' : 'Current profile: ' . e(Process::PRESETS[$cur][0] ?? 'custom') . '.') . '</p></div>';

echo '<div class="card"><h2>Who does what</h2>';
form_open(url('process', ['a' => 'save']));
echo '<div style="grid-column:1/-1;overflow-x:auto"><table class="tbl"><thead><tr><th>Step of the loan process</th>';
foreach (Process::ROLES as $r) echo '<th class="r">' . e($r) . '</th>';
echo '</tr></thead><tbody>';
foreach (Process::STEPS as $k => [$label, $about, $perms]) {
    echo '<tr><td><b>' . e($label) . '</b><br><small class="muted">' . e($about) . '</small></td>';
    foreach (Process::ROLES as $r) echo '<td class="r"><input type="checkbox" name="m[' . e($r) . '][' . e($k) . ']" value="1"' . (!empty($m[$r][$k]) ? ' checked' : '') . ' aria-label="' . e($r . ': ' . $label) . '"></td>';
    echo '</tr>';
}
echo '</tbody></table></div><p class="muted" style="grid-column:1/-1">The System Administrator keeps every right and is not shown here. Individual users can still be adjusted under Users &amp; access.</p>';
echo '<div class="form-actions">'; submit_btn('Save loan process roles'); echo '</div>'; form_close(); echo '</div>';
