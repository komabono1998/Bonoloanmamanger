<?php
/** Page chrome. Expects $content (HTML), $title, $layout, $page, $ROUTES. */
$flashes = take_flash();
$bd = null; $eodStatus = null; $branches = []; $inboxN = 0;
if (is_installed() && user()) {
    try {
        $branches = branch_options();
        $bd = current_bd(); $eodStatus = $bd ? Eod::status($bd) : null;
        if (can_any('loan.approve|payment.reverse|expense.approve|till.reconcile|eod.approve|backup.authorize')) $inboxN = count(Approvals::inbox());
    } catch (Throwable $e) {}
}
$pageTitle = ($title ? $title . ' · ' : '') . business_name();
$curBranch = (is_installed() && user()) ? branch_id() : 0;
$business = can_any('client.view|loan.view|payment.view|eod.view|till.use');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="<?= $layout === 'bare' ? 'bare' : 'app' ?>"<?= $layout === 'bare' ? '' : ' data-idle-ms="' . (int)(setting_int('session_timeout_min') * 60000) . '"' ?>>
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
        <div><b><?= e(business_name()) ?></b><small>Cloud Loan Management</small></div>
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
            <a class="nav-link<?= $on ?>" href="<?= e(url($key)) ?>"><?= e($label) ?><?php if ($key === 'inbox' && $inboxN): ?> <span class="count"><?= (int)$inboxN ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="nav-group">Session</div>
        <a class="nav-link<?= $page === 'account' ? ' on' : '' ?>" href="<?= e(url('account')) ?>">My Account &amp; Security</a>
        <a class="nav-link" href="<?= e(url('logout')) ?>">Logout</a>
      </nav>
    </aside>
    <div class="main">
      <header class="top">
        <button class="burger" type="button" data-nav-toggle aria-label="Menu">☰</button>
        <div class="bd">
          <?php if ($business && $curBranch): ?>
            <?php if (count($branches) > 1): ?>
              <form method="post" action="<?= e(url('switch_branch')) ?>" class="branch-switch"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                <label class="sr" for="branch-select">Branch</label>
                <select id="branch-select" name="branch" data-autosubmit><?php foreach ($branches as $bid => $bn): ?><option value="<?= (int)$bid ?>"<?= $bid === $curBranch ? ' selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select>
              </form>
            <?php else: ?>
              <span class="chip">Branch <b><?= e(branch_name($curBranch)) ?></b></span>
            <?php endif; ?>
            <?php if ($bd): ?>
              <span class="chip">Business date <b><?= e(fmt_date($bd)) ?></b></span>
              <span class="chip"><?= badge($eodStatus) ?></span>
            <?php else: ?>
              <span class="chip warn">No business day open</span>
              <?php if (can('eod.manage')): ?><a class="chip link" href="<?= e(url('eod')) ?>">Start business day →</a><?php endif; ?>
            <?php endif; ?>
          <?php elseif ($business): ?>
            <span class="chip warn">You are not assigned to a branch</span>
          <?php endif; ?>
        </div>
        <div class="who"><span><?= e(user()['full_name']) ?> <small>· <?= e(user()['role']) ?></small></span></div>
      </header>
      <main class="content">
        <?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
        <?= $content ?>
      </main>
      <footer class="foot"><?= e(business_name()) ?> · Bono Loan Manager (cloud) · all amounts in <?= e(setting('currency')) ?> · signed in over <?= request_is_https() ? 'HTTPS' : 'HTTP (local)' ?></footer>
    </div>
  </div>
<?php endif; ?>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
