<?php
/**
 * Outgoing email. SMTP (plain sockets, STARTTLS or implicit TLS) when configured in Settings -> Email or the environment;
 * otherwise messages are written to storage/logs/mail.log so nothing is lost during local evaluation.
 */
function mail_setting(string $key): string
{
    $env = env(strtoupper($key));
    return $env !== null && $env !== '' ? (string)$env : setting($key);
}

function mail_configured(): bool { return mail_setting('smtp_host') !== ''; }

/** Absolute link to a page, for emails. APP_URL (e.g. https://loans.example.com) wins; otherwise the current request. */
function app_url(string $page, array $params = []): string
{
    $root = rtrim((string)env('APP_URL', ''), '/');
    if ($root === '') $root = (request_is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (tenant()) $params['org'] = tenant_slug();
    return $root . parse_url(url($page, $params), PHP_URL_PATH) . '?' . http_build_query(['p' => $page] + $params);
}

function mail_from(): array
{
    $addr = mail_setting('mail_from_email') ?: (setting('business_email') ?: 'no-reply@localhost');
    return [$addr, mail_setting('mail_from_name') ?: business_name()];
}

function smtp_read($fp): array
{
    $code = 0; $text = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line; $code = (int)substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return [$code, $text];
}

function smtp_cmd($fp, string $cmd, array $ok): string
{
    if ($cmd !== '') fwrite($fp, $cmd . "\r\n");
    [$code, $text] = smtp_read($fp);
    must(in_array($code, $ok, true), 'Mail server said: ' . trim(substr($text, 0, 160)));
    return $text;
}

/** Returns true when handed to the mail server, false when only logged (not configured). Throws AppError on SMTP failure. */
function send_mail(string $to, string $subject, string $html, string $text = ''): bool
{
    must(filter_var($to, FILTER_VALIDATE_EMAIL) !== false, 'The recipient email address is not valid.');
    [$fromAddr, $fromName] = mail_from();
    $text = $text ?: trim(html_entity_decode(strip_tags(preg_replace('~<br\s*/?>|</p>~i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $b = 'b' . bin2hex(random_bytes(8));
    $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $host0 = parse_url(app_url('x'), PHP_URL_HOST) ?: 'localhost';
    $msg = "From: " . $enc($fromName) . " <$fromAddr>\r\nTo: <$to>\r\nSubject: " . $enc($subject) . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . "@" . $host0 . ">\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
        . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";

    if (!mail_configured()) {
        @mkdir(BASE_PATH . '/storage/logs', 0775, true);
        @file_put_contents(BASE_PATH . '/storage/logs/mail.log', '[' . date('c') . "] (not sent: SMTP not configured)\nTo: $to\nSubject: $subject\n$text\n\n", FILE_APPEND | LOCK_EX);
        return false;
    }
    $host = mail_setting('smtp_host'); $port = (int)(mail_setting('smtp_port') ?: 587); $sec = mail_setting('smtp_security') ?: 'tls';
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 15);
    must((bool)$fp, 'Could not connect to the mail server (' . $host . ':' . $port . ').');
    stream_set_timeout($fp, 15);
    try {
        smtp_cmd($fp, '', [220]);
        $ehlo = 'EHLO ' . $host0;
        smtp_cmd($fp, $ehlo, [250]);
        if ($sec === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            must(stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT), 'Secure connection to the mail server failed.');
            smtp_cmd($fp, $ehlo, [250]);
        }
        $user = mail_setting('smtp_user');
        if ($user !== '') {
            $pass = env('SMTP_PASS') ?: (decrypt_secret(setting('smtp_pass')) ?? '');
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode($user), [334]);
            smtp_cmd($fp, base64_encode($pass), [235]);
        }
        smtp_cmd($fp, "MAIL FROM:<$fromAddr>", [250]);
        smtp_cmd($fp, "RCPT TO:<$to>", [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        smtp_cmd($fp, str_replace("\r\n.", "\r\n..", $msg) . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally { fclose($fp); }
    return true;
}

function mail_template(string $heading, string $body, string $buttonText = '', string $link = ''): string
{
    $c = preg_match('/^#[0-9A-Fa-f]{6}$/', setting('brand_color')) ? setting('brand_color') : '#0b5c4d';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:auto;color:#1c2733"><h2 style="color:' . $c . ';margin:0 0 .3rem">' . e(business_name()) . '</h2><h3>' . e($heading) . '</h3>'
        . $body . ($link ? '<p style="margin:1.4rem 0"><a href="' . e($link) . '" style="background:' . $c . ';color:#fff;padding:.7rem 1.2rem;border-radius:6px;text-decoration:none;display:inline-block">' . e($buttonText) . '</a></p><p style="font-size:12px;color:#6b7a8a">If the button does not work, copy this link into your browser:<br>' . e($link) . '</p>' : '')
        . '<hr style="border:0;border-top:1px solid #dfe5ec"><p style="font-size:12px;color:#6b7a8a">If you did not expect this email you can ignore it.</p></div>';
}
