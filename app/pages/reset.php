<?php
$title = 'Choose a password';
$token = (string)get('token');
post_action(function () {
    $pw = $_POST['password'] ?? '';
    must($pw === ($_POST['password2'] ?? ''), 'The two passwords do not match.');
    PasswordReset::consume((string)post('token'), $pw);
    return ['Your password has been set. You can sign in now.', url('login')];
}, url('reset', ['token' => $token]));
$valid = PasswordReset::find($token) !== null;
?>
<div class="card">
  <?php if (logo_url()): ?><img src="<?= e(logo_url()) ?>" alt="" style="display:block;margin:0 auto 1rem;max-height:70px;max-width:200px"><?php endif; ?>
  <h1 style="text-align:center">Choose a password</h1>
  <?php if (!$valid): ?>
    <div class="notice bad">This link is invalid, has already been used, or has expired.</div>
    <p style="text-align:center"><a class="btn primary" href="<?= e(url('forgot')) ?>">Request a new link</a></p>
  <?php else: ?>
    <?php form_open(null) ?>
      <?php field('token', '', ['type' => 'hidden', 'value' => $token]); ?>
      <?php field('password', 'New password', ['type' => 'password', 'required' => true, 'help' => 'At least ' . max(8, setting_int('password_min_length')) . ' characters with letters and numbers.', 'attrs' => 'autofocus autocomplete="new-password"']); ?>
      <?php field('password2', 'Repeat password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"']); ?>
      <div class="form-actions"><?php submit_btn('Set password', 'primary') ?></div>
    <?php form_close() ?>
  <?php endif; ?>
</div>
