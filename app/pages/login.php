<?php
$title = 'Sign in';

function finish_login(): void
{
    $to = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    try { Backup::autoIfDue(); } catch (Throwable $e) { error_log($e); }
    $ok = $to && str_starts_with($to, '/') && !str_starts_with($to, '//') && str_contains($to, 'index.php') && !str_contains($to, 'p=login') && !str_contains($to, 'p=logout');
    redirect($ok ? $to : url('dashboard'));
}

$mfaStep = !empty($_SESSION['mfa_uid']) && time() - (int)($_SESSION['mfa_started'] ?? 0) <= 300;
if (is_post()) {
    try {
        csrf_check();
        if (post('step') === 'mfa') {
            if (verify_mfa_login((string)post('code'))) finish_login();
            flash('error', 'That code is not valid. Check the time on your phone or use a recovery code.');
            redirect(url('login'));
        }
        unset($_SESSION['mfa_uid']);
        $r = attempt_login(post('username'), $_POST['password'] ?? '');
        if ($r === 'ok') finish_login();
        if ($r === 'mfa') redirect(url('login'));
        flash('error', 'Incorrect username or password.');
    } catch (AppError $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('login'));
}
?>
<div class="card">
  <div class="logo-mark" style="background:linear-gradient(135deg,#14a085,#0b5c4d);border-radius:12px;display:grid;place-items:center">B</div>
  <h1 style="text-align:center"><?= e(business_name()) ?></h1>
  <?php if ($mfaStep): ?>
    <p class="muted" style="text-align:center">Enter the 6-digit code from your authenticator app (or a recovery code).</p>
    <?php form_open(null) ?>
      <?php field('step', '', ['type' => 'hidden', 'value' => 'mfa']); ?>
      <?php field('code', 'Authentication code', ['required' => true, 'attrs' => 'autofocus autocomplete="one-time-code" inputmode="numeric"']); ?>
      <div class="form-actions"><?php submit_btn('Verify', 'primary') ?> <a class="btn" href="<?= e(url('login')) ?>?cancel=1">Cancel</a></div>
    <?php form_close() ?>
    <?php if (get('cancel')) { unset($_SESSION['mfa_uid']); redirect(url('login')); } ?>
  <?php else: ?>
    <p class="muted" style="text-align:center">Sign in to continue</p>
    <?php form_open(null) ?>
      <?php field('username', 'Username', ['required' => true, 'attrs' => 'autofocus autocomplete="username"']); ?>
      <?php field('password', 'Password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"']); ?>
      <div class="form-actions"><?php submit_btn('Sign in', 'primary') ?></div>
    <?php form_close() ?>
  <?php endif; ?>
</div>
