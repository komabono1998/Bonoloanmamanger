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
<style><?= brand_theme_css() ?></style>
</head>
<body class="<?= $layout === 'bare' ? 'bare' : 'app' ?>"<?= $layout === 'bare' ? '' : ' data-idle-ms="' . (int)(setting_int('session_timeout_min') * 60000) . '"' ?>>
<?php if ($layout === 'bare'): ?>
  <main class="bare-main">
    <?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
    <?= $content ?>
  </main>
<?php else: ?>
  <?php
  $quick = [];
  foreach ([['client.manage', 'clients', ['a' => 'new'], 'New client', 'user-plus'], ['loan.create', 'loans', ['a' => 'new'], 'New loan application', 'clipboard'], ['payment.record', 'payments', [], 'Receive payment', 'card'],
            ['till.use', 'till', [], 'Open / use till', 'clock'], ['expense.create', 'cashbook', [], 'Record expense', 'layers'], ['user.manage', 'users', ['a' => 'new'], 'New user', 'users'],
            ['branch.manage', 'branches', ['a' => 'new'], 'New branch', 'building'], ['report.view', 'reports', [], 'Reports', 'bar'], ['settings.manage', 'settings', ['tab' => 'business'], 'Institution profile', 'settings']] as [$qp, $qk, $qa, $ql, $qi])
      if (can($qp)) $quick[] = [$qk, $qa, $ql, $qi];
  $canSearch = can_any('client.view|loan.view|payment.view');
  ?>
  <header class="topbar">
    <button class="burger" type="button" data-nav-toggle aria-label="Menu"><?= icon('menu', 20) ?></button>
    <a class="brand" href="<?= e(url('dashboard')) ?>">
      <?php if (logo_url()): ?><img src="<?= e(logo_url()) ?>" alt="" class="logo"><?php else: ?><div class="logo-mark"><?= e(strtoupper(substr(business_name(), 0, 1))) ?></div><?php endif; ?>
      <div><b><?= e(business_name()) ?></b><small><?= e(setting('business_tagline') ?: 'Loan Management System') ?></small></div>
    </a>
    <?php if ($canSearch): ?>
      <form class="gsearch" method="get" action="<?= e(base_url() . '/index.php') ?>" role="search">
        <input type="hidden" name="p" value="search"><?= icon('search', 18) ?>
        <input type="search" name="q" value="<?= e($page === 'search' ? (string)get('q') : '') ?>" placeholder="Search clients, loans, receipts…" aria-label="Search" autocomplete="off">
      </form>
    <?php else: ?><div class="gsearch-gap"></div><?php endif; ?>
    <?php if ($quick): ?>
      <details class="quick"><summary class="btn primary sm"><?= icon('plus', 15) ?> New</summary>
        <div class="quick-menu"><?php foreach ($quick as [$qk, $qa, $ql, $qi]): ?><a href="<?= e(url($qk, $qa)) ?>"><?= icon($qi, 16) ?> <?= e($ql) ?></a><?php endforeach; ?></div>
      </details>
    <?php endif; ?>
    <?php if ($inboxN || can_any('loan.approve|payment.reverse|expense.approve|till.reconcile|eod.approve|backup.authorize')): ?>
      <a class="bell" href="<?= e(url('inbox')) ?>" title="My approvals"><?= icon('bell', 20) ?><?php if ($inboxN): ?><span class="bell-n"><?= (int)$inboxN ?></span><?php endif; ?></a>
    <?php endif; ?>
    <details class="me"><summary><span class="avatar"><?= e(strtoupper(substr(user()['full_name'], 0, 1))) ?></span><span class="me-t"><b><?= e(user()['full_name']) ?></b><small><?= e(user()['role']) ?><?= !empty(user()['staff_no']) ? ' · ' . e(user()['staff_no']) : '' ?></small></span><?= icon('down', 16) ?></summary>
      <div class="quick-menu"><a href="<?= e(url('account')) ?>"><?= icon('user', 16) ?> My account &amp; security</a><a href="<?= e(url('logout')) ?>"><?= icon('logout', 16) ?> Sign out</a></div>
    </details>
  </header>
  <div class="shell">
    <aside class="side" id="side">
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
            <a class="nav-link<?= $on ?>" href="<?= e(url($key)) ?>"><?= icon(route_icon($key), 18) ?><span><?= e($label) ?></span><?php if ($key === 'inbox' && $inboxN): ?> <span class="count"><?= (int)$inboxN ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="nav-group">Session</div>
        <a class="nav-link<?= $page === 'account' ? ' on' : '' ?>" href="<?= e(url('account')) ?>"><?= icon('user', 18) ?><span>My Account &amp; Security</span></a>
        <a class="nav-link" href="<?= e(url('logout')) ?>"><?= icon('logout', 18) ?><span>Logout</span></a>
      </nav>
    </aside>
    <div class="main">
      <?php if ($business): ?>
      <div class="subbar">
          <?php if ($curBranch): ?>
            <?php if (count($branches) > 1): ?>
              <form method="post" action="<?= e(url('switch_branch')) ?>" class="branch-switch"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                <label class="sr" for="branch-select">Branch</label>
                <select id="branch-select" name="branch" data-autosubmit><?php foreach ($branches as $bid => $bn): ?><option value="<?= (int)$bid ?>"<?= $bid === $curBranch ? ' selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select>
              </form>
            <?php else: ?>
              <span class="chip"><?= icon('building', 14) ?> Branch <b><?= e(branch_name($curBranch)) ?></b></span>
            <?php endif; ?>
            <?php if ($bd): ?>
              <span class="chip"><?= icon('calendar', 14) ?> Business date <b><?= e(fmt_date($bd)) ?></b></span>
              <span class="chip"><?= badge($eodStatus) ?></span>
            <?php else: ?>
              <span class="chip warn">No business day open</span>
              <?php if (can('eod.manage')): ?><a class="chip link" href="<?= e(url('eod')) ?>">Start business day →</a><?php endif; ?>
            <?php endif; ?>
          <?php else: ?>
            <span class="chip warn">You are not assigned to a branch</span>
          <?php endif; ?>
      </div>
      <?php endif; ?>
      <main class="content">
        <?php foreach ($flashes as [$t, $m]): ?><div class="flash <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
        <?= $content ?>
      </main>
      <footer class="foot"><?= e(business_name()) ?> · all amounts in <?= e(setting('currency')) ?> · signed in over <?= request_is_https() ? 'HTTPS' : 'HTTP (local)' ?></footer>
    </div>
  </div>
<?php endif; ?>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
