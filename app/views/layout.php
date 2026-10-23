<?php
/** Page chrome. Expects $content (HTML), $title, $layout, $page, $ROUTES. */
$flashes = take_flash();
$bd = null; $eodStatus = null;
if (is_installed() && user()) {
    try { $bd = current_bd(); $eodStatus = $bd ? Eod::status($bd) : null; } catch (Throwable $e) {}
}
$pageTitle = ($title ? $title . ' · ' : '') . business_name();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="<?= $layout === 'bare' ? 'bare' : 'app' ?>">
<?php if ($layout === 'bare'): ?>
  <main class="bare-main">
    <?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
    <?= $content ?>
  </main>
<?php else: ?>
  <div class="shell">
    <aside class="side" id="side">
      <div class="brand">
        <?php if (setting('business_logo')): ?><img src="<?= e(attachment_url((int)setting('business_logo'))) ?>" alt="" class="logo"><?php else: ?><div class="logo-mark">B</div><?php endif; ?>
        <div><b><?= e(business_name()) ?></b><small>Loan Management</small></div>
      </div>
      <nav>
        <?php
        $groups = [];
        foreach ($ROUTES as $key => [$f, $perm, $group, $label]) {
            if (!$group || ($perm && !can_any($perm))) continue;
            $groups[$group][] = [$key, $label];
        }
        foreach ($groups as $g => $items): ?>
          <div class="nav-group"><?= e($g) ?></div>
          <?php foreach ($items as [$key, $label]):
              $on = $page === $key || ($page === 'loan' && $key === 'loans') ? ' on' : ''; ?>
            <a class="nav-link<?= $on ?>" href="<?= e(url($key)) ?>"><?= e($label) ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="nav-group">Session</div>
        <a class="nav-link<?= $page === 'account' ? ' on' : '' ?>" href="<?= e(url('account')) ?>">My Account</a>
        <a class="nav-link" href="<?= e(url('logout')) ?>">Logout</a>
      </nav>
    </aside>
    <div class="main">
      <header class="top">
        <button class="burger" type="button" onclick="document.getElementById('side').classList.toggle('open')" aria-label="Menu">☰</button>
        <div class="bd">
          <?php if ($bd): ?>
            <span class="chip">Business date <b><?= e(fmt_date($bd)) ?></b></span>
            <span class="chip"><?= badge($eodStatus) ?></span>
          <?php else: ?>
            <span class="chip warn">No business day open</span>
            <?php if (can('eod.manage')): ?><a class="chip link" href="<?= e(url('eod')) ?>">Start business day →</a><?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="who"><span><?= e(user()['full_name']) ?> <small>· <?= e(user()['role']) ?></small></span></div>
      </header>
      <main class="content">
        <?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
        <?= $content ?>
      </main>
      <footer class="foot"><?= e(business_name()) ?> · Offline Loan Management · all amounts in <?= e(setting('currency')) ?></footer>
    </div>
  </div>
  <script>window.IDLE_MS = <?= (int)setting_int('session_timeout_min') * 60000 ?>;</script>
<?php endif; ?>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
