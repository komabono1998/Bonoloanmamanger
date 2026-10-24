<?php
/**
 * First-installation check over HTTP. Point it at an EMPTY installation (no organisation yet):
 *   BONO_DB=<new file> php -S 127.0.0.1:8099 -t .     then     php tests/firstrun.php http://127.0.0.1:8099
 */
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/http.php';
$base = $argv[1] ?? 'http://127.0.0.1:8099';
$pass = 0; $fail = 0;
function chk(string $name, bool $ok, string $info = ''): void { global $pass, $fail; echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n"; $ok ? $pass++ : $fail++; }
$w = new Web($base);
$w->get($base . '/');
chk('a fresh installation opens a sign-in page', $w->code === 200 && str_contains($w->body, 'name="username"') && str_contains($w->body, 'name="password"'), 'status ' . $w->code);
chk('the only way forward is "Set up a new organisation"', str_contains($w->body, 'Set up a new organisation') && str_contains($w->body, 'p=setup'));
chk('no organisation details are shown (no organisation name, logo, organisation code or forgot-password link)', !preg_match('~<h1[^>]*>\s*(?:<img[^>]*>)?\s*(?!Sign in)[^<]+</h1>~', $w->body) && !str_contains($w->body, 'inst-logo') && !str_contains($w->body, 'name="org"') && !str_contains($w->body, 'Forgot Password'));
chk('the page title names the product, not an organisation', preg_match('~<title>([^<]*)</title>~', $w->body, $m) && str_contains($m[1], 'Bono Loan Management System') && !str_contains($m[1], 'EMglo'));
$w->submit('/', ['username' => 'admin', 'password' => 'whatever1']);
chk('signing in before any organisation exists explains what to do', str_contains($w->flashText(), 'No organisation has been set up'), $w->flashText());
$w->get($w->url('setup'));
chk('the setup form opens with nothing pre-filled', $w->code === 200 && str_contains($w->body, 'Set up a new organisation') && !preg_match('~name="business_name"[^>]*value="[^"]+"~', $w->body) && !preg_match('~name="interest_rate_pct"[^>]*value="[^"]+"~', $w->body) && !preg_match('~name="branch_code"[^>]*value="[^"]+"~', $w->body) && !str_contains($w->body, 'Welcome to Bono Loan Manager'));
$w->submit('p=setup', ['business_name' => 'First Light Microfinance', 'full_name' => 'First Admin', 'username' => 'firstadmin', 'password' => 'Strong-pass-2026', 'password2' => 'Strong-pass-2026', 'branch_name' => 'Main Office', 'branch_code' => 'MO']);
chk('the first organisation is created', str_contains($w->flashText(), 'Setup complete'), $w->flashText());
$w->get($base . '/');
chk('after setup the sign-in page carries the new organisation name', str_contains($w->body, 'First Light Microfinance'));
$w->get($w->url('setup'));
chk('the first-run set-up form is no longer offered once the system is installed', !str_contains($w->body, 'name="business_name"'));
echo "Passed: $pass   Failed: $fail\n";
exit($fail ? 1 : 0);
