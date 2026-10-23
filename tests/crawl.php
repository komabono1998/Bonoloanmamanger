<?php
/**
 * Smoke crawler: signs in as each account, follows every link it finds (menus, tabs, buttons that are plain links) and reports
 * pages that fail (HTTP 5xx, 403/404 for a link the interface offered, PHP errors, "Something went wrong").
 *
 *   php tests/crawl.php http://127.0.0.1:8098 accounts.json [--org=code] [--max=300]
 *
 * accounts.json: [{"user":"lo","pass":"...","new_pass":"... (used when a password change is forced)"}]
 * Run it against a throw-away database (for example `php -S 127.0.0.1:8098` with BONO_DB set), never against production.
 */
if (PHP_SAPI !== 'cli') exit('CLI only');
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8098', '/');
$accounts = json_decode(ltrim((string)file_get_contents($argv[2] ?? "accounts.json"), "﻿"), true) ?: [];
$opts = []; foreach (array_slice($argv, 3) as $a) if (preg_match('/^--(\w+)=(.*)$/', $a, $m)) $opts[$m[1]] = $m[2];
$max = (int)($opts['max'] ?? 300);

function http(string $url, string $jar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => false]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $r = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'body' => $body];
    curl_close($ch);
    return $r;
}
function title(string $b): string { return preg_match('~<title>([^<]*)</title>~', $b, $m) ? html_entity_decode($m[1]) : ''; }
function csrf(string $b): string { return preg_match('~name="_csrf" value="([^"]+)"~', $b, $m) ? $m[1] : ''; }
function links(string $b, string $base, string $cur): array
{
    $out = [];
    if (!preg_match_all('~<a\s[^>]*href="([^"#]+)"~i', $b, $m)) return $out;
    foreach ($m[1] as $h) {
        $h = html_entity_decode($h);
        if (str_starts_with($h, '/')) $h = preg_replace('~^(https?://[^/]+).*$~', '$1', $base) . $h;
        elseif (!str_starts_with($h, 'http')) $h = preg_replace('~/[^/]*$~', '/', strtok($cur, '?')) . $h;
        if (!str_starts_with($h, $base) && !preg_match('~^' . preg_quote(preg_replace('~^(https?://[^/]+).*$~', '$1', $base), '~') . '~', $h)) continue;
        if (!str_contains($h, 'index.php?') && !str_contains($h, 'p=')) continue;
        parse_str((string)parse_url($h, PHP_URL_QUERY), $q);
        if (in_array($q['p'] ?? '', ['logout', 'switch_branch', 'forgot', 'register'], true)) continue;
        ksort($q); $out[$h] = ($q['p'] ?? '') . '?' . http_build_query($q);
    }
    return $out;
}

$logFile = __DIR__ . '/../storage/logs/php-error.log'; $logStart = is_file($logFile) ? filesize($logFile) : 0;
$total = 0; $problems = []; $rejected = [];
foreach ($accounts as $acc) {
    $jar = tempnam(sys_get_temp_dir(), 'crawl'); $who = $acc['user'];
    $loginUrl = $base . '/index.php?p=login' . (isset($opts['org']) ? '&org=' . $opts['org'] : '');
    $r = http($loginUrl, $jar);
    $r = http($base . '/index.php?p=login', $jar, ['_csrf' => csrf($r['body']), 'org' => $opts['org'] ?? '', 'username' => $who, 'password' => $acc['pass']]);
    $note = '';
    if (str_contains($r['body'], 'Incorrect username or password')) { $problems[] = "$who: sign-in failed"; continue; }
    if (str_contains($r['url'], 'p=account') || str_contains($r['body'], 'One step before you start')) {
        $note = ' (password change was forced)';
        $np = $acc['new_pass'] ?? $acc['pass'] . 'x9';
        $r = http($base . '/index.php?p=account', $jar, ['_csrf' => csrf($r['body']), 'current' => $acc['pass'], 'new' => $np, 'new2' => $np]);
        if (str_contains($r['body'], 'One step before you start')) { $problems[] = "$who: could not complete the forced password change"; continue; }
    }
    if (str_contains($r['url'], 'p=mfa')) { echo "$who: multi-factor enrolment required - skipped\n"; continue; }
    $postForms = []; $seen = []; $queue = [[$base . '/index.php?p=dashboard', 0, 'start']]; $n = 0; $forms = 0;
    while ($queue && $n < $max) {
        [$u, $depth, $from] = array_shift($queue);
        parse_str((string)parse_url($u, PHP_URL_QUERY), $q); ksort($q); $key = ($q['p'] ?? '') . '?' . http_build_query($q);
        if (isset($seen[$key])) continue; $seen[$key] = 1;
        $p = http($u, $jar); $n++; $total++;
        $bad = null;
        if ($p['code'] >= 500) $bad = 'HTTP ' . $p['code'];
        elseif ($p['code'] === 403) $bad = 'denied (403) - the interface offered this link';
        elseif ($p['code'] === 404) $bad = 'not found (404)';
        elseif (str_contains($p['body'], 'Something went wrong')) $bad = '"Something went wrong"';
        elseif (($q['p'] ?? '') !== 'system' && preg_match('~(Fatal error|Parse error|Warning|Notice|Deprecated): [^\n]{0,240}(on line \d+)~', strip_tags($p['body']), $m)) $bad = 'PHP message: ' . substr($m[0], 0, 200);
        elseif (str_contains($p['url'], 'p=login') && !str_contains($u, 'p=login')) $bad = 'bounced to sign-in (session lost)';
        elseif (preg_match('~<div class="card"><h2>Notice</h2><p>([^<]*)</p>~', $p['body'], $m)) $bad = 'notice: ' . $m[1];
        if ($bad) $problems[] = "$who: $key <- $from : $bad";
        $forms += substr_count($p['body'], '<form ');
        if (isset($opts['post']) && $p['code'] === 200) {
            $dom2 = new DOMDocument(); @$dom2->loadHTML('<?xml encoding="utf-8"?>' . $p['body']);
            foreach ($dom2->getElementsByTagName('form') as $fm) {
                if (strtolower($fm->getAttribute('method') ?: 'get') !== 'post') continue;
                $act = html_entity_decode($fm->getAttribute('action')) ?: $p['url'];
                if (str_starts_with($act, '/')) $act = preg_replace('~^(https?://[^/]+).*$~', '$1', $base) . $act;
                if (preg_match('~a=(restore|setstatus|delete|authorize_restore)|p=(login|logout|setup|register)~', $act)) continue;
                $fields = [];
                foreach ($fm->getElementsByTagName('input') as $i) { $n2 = $i->getAttribute('name'); $t = strtolower($i->getAttribute('type') ?: 'text'); if ($n2 === '' || in_array($t, ['submit', 'button', 'file'], true)) continue; if (in_array($t, ['checkbox', 'radio'], true) && !$i->hasAttribute('checked')) continue; $fields[$n2] = $i->getAttribute('value'); }
                foreach ($fm->getElementsByTagName('textarea') as $i) $fields[$i->getAttribute('name')] = $i->textContent;
                foreach ($fm->getElementsByTagName('select') as $sl) { $v = null; foreach ($sl->getElementsByTagName('option') as $o) { if ($v === null || $o->hasAttribute('selected')) $v = $o->getAttribute('value'); } $fields[$sl->getAttribute('name')] = $v ?? ''; }
                parse_str((string)parse_url($act, PHP_URL_QUERY), $aq); ksort($aq);
                $postForms[http_build_query($aq)] ??= [$act, $fields, $key];
            }
        }
        if ($depth < 3 && $p['code'] === 200) {
            // plain GET forms (filters, search boxes) are submitted with their default values
            $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8"?>' . $p['body']);
            foreach ($dom->getElementsByTagName('form') as $fm) {
                if (strtolower($fm->getAttribute('method') ?: 'get') !== 'get') continue;
                $q2 = [];
                foreach ($fm->getElementsByTagName('input') as $i) { $nm = $i->getAttribute('name'); $t = strtolower($i->getAttribute('type') ?: 'text'); if ($nm === '' || in_array($t, ['submit', 'button', 'checkbox', 'radio', 'file'], true)) continue; $q2[$nm] = $i->getAttribute('value'); }
                foreach ($fm->getElementsByTagName('select') as $sl) { $nm = $sl->getAttribute('name'); if ($nm === '') continue; $v = null; foreach ($sl->getElementsByTagName('option') as $o) { if ($v === null || $o->hasAttribute('selected')) $v = $o->getAttribute('value'); } $q2[$nm] = $v ?? ''; }
                if (($q2['p'] ?? '') === '' || in_array($q2['p'], ['logout', 'switch_branch'], true)) continue;
                if (isset($q2['q']) && $q2['q'] === '') $q2['q'] = 'a';
                $queue[] = [$base . '/index.php?' . http_build_query($q2), $depth + 1, $key . ' (filter form)'];
            }
        }
        if ($depth < 3 && $p['code'] === 200) foreach (links($p['body'], $base, $p['url']) as $href => $k) if (!isset($seen[$k])) $queue[] = [$href, $depth + 1, $key];
    }
    $posted = 0;
    foreach ($postForms as [$act, $fields, $from]) {
        $r = http($act, $jar, $fields); $posted++; $total++;
        $bad = null;
        if ($r['code'] >= 500) $bad = 'HTTP ' . $r['code'];
        elseif (str_contains($r['body'], 'Something went wrong')) $bad = '"Something went wrong"';
        elseif (preg_match('~(Fatal error|Parse error|Warning|Notice|Deprecated): [^\n]{0,240}(on line \d+)~', strip_tags($r['body']), $m)) $bad = 'PHP message: ' . substr($m[0], 0, 200);
        elseif (str_contains($r['url'], 'p=login') && !str_contains($act, 'p=login')) $bad = 'bounced to sign-in (session lost)';
        if (preg_match('~<div class="flash error">([^<]*)</div>~', $r['body'], $fe)) $rejected[] = "$who: " . preg_replace('~^.*index\.php\?~', '', $act) . ' -> ' . html_entity_decode($fe[1]);
        if ($bad) $problems[] = "$who: POST " . preg_replace('~^.*index\.php\?~', '', $act) . " (form on $from) : $bad";
    }
    echo sprintf("%-14s %3d pages, %3d forms, %3d submitted%s\n", $who, $n, $forms, $posted, $note);
    @unlink($jar);
}
$log = is_file($logFile) ? substr((string)file_get_contents($logFile), $logStart) : '';
echo "\nPages visited: $total\n";
if ($log !== '') { echo "\nPHP error log entries during the crawl:\n" . substr($log, 0, 3000) . "\n"; }
if ($rejected && isset($opts['show'])) echo "
Forms that answered with a validation message when sent with their default values (informational):
 - " . implode("
 - ", array_unique($rejected)) . "
";
if ($problems) { echo "\nPROBLEMS (" . count($problems) . "):\n - " . implode("\n - ", array_unique($problems)) . "\n"; exit(1); }
echo "No problems found.\n";
