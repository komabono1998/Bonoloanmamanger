<?php
/** Public: create a new organisation workspace (its own private database, branch and administrator). */
$title = 'Set up your organisation';
if (!Tenancy::primaryInstalled()) { redirect(url('setup')); }
$enabled = Tenancy::enabled();
if ($enabled) post_action(function () {
    $pw = $_POST['password'] ?? '';
    must($pw === ($_POST['password2'] ?? ''), 'The two passwords do not match.');
    must(!empty($_POST['agree']), 'Please confirm that you are authorised to set up this organisation.');
    $slug = Tenancy::create(clean(post('name')), strtolower(post('slug')) ?: Tenancy::slugFromName(post('name')), clean(post('full_name')), strtolower(post('username')), clean(post('email')), $pw,
        clean(post('branch_name')) ?: 'Head Office', post('branch_code') ?: 'HQ',
        array_map('clean', array_intersect_key($_POST, array_flip(['business_address', 'business_city', 'business_phone', 'business_email', 'registration_no', 'tax_id', 'interest_rate_pct', 'processing_fee_pct']))));
    flash('ok', 'Your organisation is ready. Sign in with the organisation code "' . $slug . '" and the administrator account you just created.');
    return [null, url('login', ['org' => $slug])];
});
?>
<div class="card wide">
  <img class="sys-logo" src="<?= e(asset('bono-logo.png')) ?>" alt="Bono Loan Management System">
  <h1 style="text-align:center">Set up a new organisation</h1>
  <?php if (!$enabled): ?>
    <div class="notice">Self-service registration is switched off on this installation. Ask the platform administrator to create your organisation.</div>
    <p style="text-align:center"><a class="btn" href="<?= e(url('login')) ?>">Back to sign in</a></p>
  <?php else: ?>
  <p class="muted" style="text-align:center">Your organisation gets its own private workspace: separate database, users, branches, settings, files and backups. Nobody from another organisation can see your data.</p>
  <?php form_open(null) ?>
    <h3 class="full">Organisation</h3>
    <?php field('name', 'Organisation name', ['required' => true, 'span' => 2, 'attrs' => 'autofocus data-slug-source']); ?>
    <?php field('slug', 'Organisation code', ['help' => 'Staff type this when signing in. 3-24 lowercase letters, numbers or dashes. Leave blank to use the name.', 'attrs' => 'data-slug-target autocapitalize="none" spellcheck="false"']); ?>
    <?php field('registration_no', 'Registration number'); field('tax_id', 'Tax ID (TIN)'); field('business_phone', 'Telephone'); field('business_email', 'Organisation email'); ?>
    <?php field('business_address', 'Address', ['span' => 2]); field('business_city', 'Town / district'); ?>
    <?php field('interest_rate_pct', 'Interest rate (% per month)', ['placeholder' => 'e.g. 20', 'help' => 'Optional; changeable later in Settings.']); field('processing_fee_pct', 'Processing fee (%)', ['placeholder' => 'e.g. 3']); ?>
    <h3 class="full">First branch</h3>
    <?php field('branch_name', 'Branch name', ['placeholder' => 'e.g. Head Office']); field('branch_code', 'Branch code', ['placeholder' => 'e.g. HQ']); ?>
    <h3 class="full">Administrator account</h3>
    <?php field('full_name', 'Your full name', ['required' => true, 'span' => 2]); field('email', 'Your email (for password reset)', ['type' => 'email']); ?>
    <?php field('username', 'Username', ['required' => true, 'help' => 'Letters, numbers, dot, dash or underscore.', 'attrs' => 'autocomplete="username"']); ?>
    <?php field('password', 'Password', ['type' => 'password', 'required' => true, 'help' => 'At least ' . max(8, setting_int('password_min_length')) . ' characters with letters and numbers.', 'attrs' => 'autocomplete="new-password"']); field('password2', 'Repeat password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']); ?>
    <?php field('agree', 'I am authorised to set up this organisation and accept responsibility for its users and data.', ['type' => 'checkbox', 'span' => 4]); ?>
    <div class="form-actions"><?php submit_btn('Create organisation', 'primary') ?> <a class="btn" href="<?= e(url('login')) ?>">Back to sign in</a></div>
  <?php form_close() ?>
  <?php endif; ?>
</div>
