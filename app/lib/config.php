<?php
/**
 * Configuration: real environment variables first, then an optional .env file (never committed).
 * Secrets (database password, APP_KEY, S3 keys) must come from the environment / secret manager in production.
 */
function env(string $key, $default = null)
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    return $file[$key] ?? $default;
}

function is_production(): bool
{
    return strtolower((string)env('APP_ENV', 'local')) === 'production';
}

/** True when the request reached us over HTTPS (directly, or via a trusted reverse proxy / load balancer). */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (env('TRUST_PROXY') === '1' && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

/** Key used to encrypt secrets stored in the database (e.g. MFA seeds). 32 bytes derived from APP_KEY. */
function app_key(): string
{
    $k = (string)env('APP_KEY', '');
    if ($k === '') {
        if (is_production()) throw new RuntimeException('APP_KEY is not set.');
        $k = 'development-only-key-change-me';    // local development convenience only
    }
    return hash('sha256', $k, true);
}

function encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}

function decrypt_secret(?string $blob): ?string
{
    if (!$blob || !str_starts_with($blob, 'v1:')) return null;
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}
