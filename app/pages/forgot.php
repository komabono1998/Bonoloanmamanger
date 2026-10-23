<?php
$title = 'Forgot password';
post_action(function () {
    PasswordReset::requestFor((string)post('identifier'));
    return ['If that account exists and has an email address, a reset link has been sent. It is valid for 2 hours.', url('login')];
});
?>
<div class="card">
  <?php if (logo_url()): ?><img src="<?= e(logo_url()) ?>" alt="" style="display:block;margin:0 auto 1rem;max-height:70px;max-width:200px"><?php endif; ?>
  <h1 style="text-align:center">Reset your password</h1>
  <p class="muted" style="text-align:center">Enter your username, email or Staff ID. We will email you a link to choose a new password.</p>
  <?php form_open(null) ?>
    <?php field('identifier', 'Username, email or Staff ID', ['required' => true, 'attrs' => 'autofocus']); ?>
    <div class="form-actions"><?php submit_btn('Email me a reset link', 'primary') ?> <a class="btn" href="<?= e(url('login')) ?>">Back to sign in</a></div>
  <?php form_close() ?>
</div>
