<?php
$title = 'Sign in';
if (is_post()) {
    try {
        csrf_check();
        if (attempt_login(post('username'), $_POST['password'] ?? '')) {
            $to = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            try { Backup::autoIfDue(); } catch (Throwable $e) { error_log($e); }
            redirect(($to && str_contains($to, 'index.php') && !str_contains($to, 'p=login')) ? $to : url('dashboard'));
        }
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
  <p class="muted" style="text-align:center">Sign in to continue</p>
  <?php form_open(null) ?>
    <?php field('username', 'Username', ['required' => true, 'attrs' => 'autofocus autocomplete="username"']); ?>
    <?php field('password', 'Password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"']); ?>
    <div class="form-actions"><?php submit_btn('Sign in', 'primary') ?></div>
  <?php form_close() ?>
</div>
