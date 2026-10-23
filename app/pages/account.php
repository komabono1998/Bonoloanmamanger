<?php
$title = 'My account';
if (get('a') === 'photo') post_action(function () {
    $att = save_upload('photo', 'user', uid(), 'photo');
    must((bool)$att, 'Choose or capture a photo first.');
    return 'Profile photo updated.';
});
post_action(function () {
    $u = row('SELECT * FROM users WHERE id = ?', [uid()]);
    must(password_verify($_POST['current'] ?? '', $u['password_hash']), 'Your current password is incorrect.');
    $new = $_POST['new'] ?? '';
    must($new === ($_POST['new2'] ?? ''), 'The new passwords do not match.');
    password_policy_check($new);
    must(!password_verify($new, $u['password_hash']), 'Choose a password different from the current one.');
    q('UPDATE users SET password_hash = ?, password_changed_at = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), now(), uid()]);
    audit('Password changed', 'Security', 'user', uid());
    return ['Password changed.', url('dashboard')];
});
$u = row('SELECT * FROM users WHERE id = ?', [uid()]);
page_head('My account & security');
?>
<?php if (!empty($u['must_change_password'])): ?><div class="notice"><b>One step before you start:</b> your password was set by an administrator (or has expired). Choose your own new password in the box on the right — the menu unlocks as soon as you save it.</div><?php endif; ?>
<div class="grid g2">
  <div class="card">
    <h2>Profile</h2>
    <?php form_open(url('account', ['a' => 'photo']), ['upload' => true, 'attrs' => 'style="grid-template-columns:1fr"']) ?>
      <?php photo_field('photo', 'My photo', ['span' => 1, 'current' => user_photo_map()[uid()] ?? null]); ?>
      <div class="form-actions"><?php submit_btn('Save photo', 'default') ?></div>
    <?php form_close() ?>
    <?php desc_list(['Name' => e($u['full_name']), 'Staff ID' => '<b>' . e($u['staff_no']) . '</b>', 'Username' => e($u['username']), 'Role' => e($u['role']), 'System date' => '<b>' . e(system_date_label()) . '</b>', 'Phone' => e($u['phone']), 'Branches' => e(implode(', ', branch_options()) ?: '—'),
        'Last login' => e(fmt_dt($u['last_login'])), 'Password changed' => e(fmt_dt($u['password_changed_at'])),
        'Multi-factor' => $u['mfa_enabled'] ? badge('Active') : (mfa_required_for($u) ? badge('Pending') . ' required for your role' : badge('Archived') . ' off')], 2); ?>
    <p><?= btn('Manage multi-factor authentication', url('mfa')) ?></p>
  </div>
  <div class="card">
    <h2>Change password</h2>
    <?php form_open(null, ['attrs' => 'style="grid-template-columns:1fr"']) ?>
      <?php field('current', 'Current password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"']); ?>
      <?php field('new', 'New password', ['type' => 'password', 'required' => true, 'help' => 'At least ' . max(8, setting_int('password_min_length')) . ' characters with letters and numbers.', 'attrs' => 'autocomplete="new-password"']); ?>
      <?php field('new2', 'Repeat new password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']); ?>
      <div class="form-actions"><?php submit_btn('Change password') ?></div>
    <?php form_close() ?>
  </div>
</div>
<div class="card"><h2>My recent sign-ins</h2>
<?php data_table(['at' => ['When', 'dt'], 'ip' => 'IP address', 'success' => ['Result', fn($r) => $r['success'] ? 'signed in' : '<b class="ico-bad">failed</b>'], 'reason' => 'Note'],
    rows('SELECT * FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT 15', [uid()]), ['class' => 'nofilter']); ?>
<p class="muted">If you do not recognise a sign-in, change your password and tell your administrator.</p></div>
