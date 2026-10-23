<?php
/** First-run setup: creates the database, default roles/settings and the initial administrator. */
$title = 'Setup';
post_action(function () {
    $pw = $_POST['password'] ?? '';
    must($pw === ($_POST['password2'] ?? ''), 'The two passwords do not match.');
    $id = install_system(clean(post('business_name')), clean(post('full_name')), post('username'), $pw);
    flash('ok', 'Setup complete. Please sign in with the administrator account you just created.');
    return [null, url('login')];
});
?>
<div class="card">
  <div class="logo-mark" style="background:linear-gradient(135deg,#14a085,#0b5c4d);border-radius:12px;display:grid;place-items:center">B</div>
  <h1 style="text-align:center">Welcome to Bono Loan Manager</h1>
  <p class="muted" style="text-align:center">First-time setup. Create the administrator account. Everything is stored locally on this computer — no internet needed.</p>
  <?php form_open(null) ?>
    <?php field('business_name', 'Business name', ['value' => 'Bono Loan Manager', 'required' => true]); ?>
    <?php field('full_name', 'Administrator full name', ['required' => true]); ?>
    <?php field('username', 'Username', ['required' => true, 'help' => 'Letters, numbers, dot, dash or underscore.', 'attrs' => 'autocomplete="username"']); ?>
    <?php field('password', 'Password', ['type' => 'password', 'required' => true, 'help' => 'At least 8 characters with letters and numbers.', 'attrs' => 'autocomplete="new-password"']); ?>
    <?php field('password2', 'Repeat password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']); ?>
    <div class="form-actions"><?php submit_btn('Create system', 'primary') ?></div>
  <?php form_close() ?>
</div>
