<?php
/** First-run setup: creates the database, default roles/settings, the first branch and the System Administrator. */
$title = 'Setup';
post_action(function () {
    $pw = $_POST['password'] ?? '';
    must($pw === ($_POST['password2'] ?? ''), 'The two passwords do not match.');
    install_system(clean(post('business_name')), clean(post('full_name')), post('username'), $pw, clean(post('branch_name')) ?: 'Head Office', post('branch_code') ?: 'HQ',
        array_map('clean', array_intersect_key($_POST, array_flip(['institution_code', 'business_address', 'business_city', 'business_phone', 'business_email', 'registration_no', 'tax_id', 'interest_rate_pct', 'processing_fee_pct']))));
    flash('ok', 'Setup complete. Sign in as the System Administrator, then create staff accounts and assign them to branches. You will be asked to set up multi-factor authentication.');
    return [null, url('login')];
});
?>
<div class="card">
  <img class="sys-logo" src="<?= e(asset('bono-logo.png')) ?>" alt="Bono Loan Management System">
  <h1 style="text-align:center">Welcome to Bono Loan Manager</h1>
  <p class="muted" style="text-align:center">First-time setup of the cloud system. This creates the first branch and the <b>System Administrator</b> (technical account — it cannot approve loans or post money).</p>
  <?php form_open(null) ?>
    <?php field('business_name', 'Institution name', ['value' => 'Bono Loan Manager', 'required' => true]); ?>
    <?php field('institution_code', 'Institution code (for staff IDs)', ['help' => 'Optional 2–6 letters, e.g. EMG → staff IDs like EMG-S-0001. Defaults to the initials of your name.']); ?>
    <?php field('business_address', 'Address'); field('business_city', 'Town / district'); field('business_phone', 'Telephone'); field('business_email', 'Email'); field('registration_no', 'Registration number'); field('tax_id', 'Tax ID (TIN)'); ?>
    <?php field('interest_rate_pct', 'Interest rate (% per month)', ['value' => setting('interest_rate_pct'), 'help' => 'You can change this later in Settings → Interest & fees.']); field('processing_fee_pct', 'Processing fee (%)', ['value' => setting('processing_fee_pct')]); ?>
    <?php field('branch_name', 'First branch name', ['value' => 'Head Office', 'required' => true]); ?>
    <?php field('branch_code', 'Branch code', ['value' => 'HQ', 'required' => true, 'help' => 'Short code used in till session numbers, e.g. HQ, KLA, GLU.']); ?>
    <?php field('full_name', 'Administrator full name', ['required' => true]); ?>
    <?php field('username', 'Username', ['required' => true, 'help' => 'Letters, numbers, dot, dash or underscore.', 'attrs' => 'autocomplete="username"']); ?>
    <?php field('password', 'Password', ['type' => 'password', 'required' => true, 'help' => 'At least ' . max(8, setting_int('password_min_length')) . ' characters with letters and numbers.', 'attrs' => 'autocomplete="new-password"']); ?>
    <?php field('password2', 'Repeat password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']); ?>
    <div class="form-actions"><?php submit_btn('Create system', 'primary') ?></div>
  <?php form_close() ?>
</div>
