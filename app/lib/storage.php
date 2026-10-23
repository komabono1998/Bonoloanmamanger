<?php
/**
 * Private file storage for photographs and documents.
 *   STORAGE_DRIVER=local  (default) files in STORAGE_PATH (keep it OUTSIDE the web root, on an encrypted/mounted cloud volume)
 *   STORAGE_DRIVER=s3     any S3-compatible private bucket (AWS S3, MinIO, DigitalOcean Spaces, Cloudflare R2 ...), SigV4 signed
 * Files are never served directly: pages/file.php checks the user's permission and branch first.
 */
final class Storage
{
    public static function driver(): string
    {
        return strtolower((string)env('STORAGE_DRIVER', 'local')) === 's3' ? 's3' : 'local';
    }

    public static function localRoot(): string
    {
        $p = (env('STORAGE_PATH') ?: BASE_PATH . '/storage/uploads') . tenant_dir_suffix();
        if (!is_dir($p)) @mkdir($p, 0775, true);
        return rtrim(str_replace('\\', '/', $p), '/');
    }

    public static function put(string $key, string $srcFile, string $mime): void
    {
        must(preg_match('/^[A-Za-z0-9._-]+$/', $key) === 1, 'Invalid storage key.');
        if (self::driver() === 'local') {
            $dest = self::localRoot() . '/' . $key;
            $ok = is_uploaded_file($srcFile) ? move_uploaded_file($srcFile, $dest) : copy($srcFile, $dest);
            must($ok, 'The file could not be saved.');
            return;
        }
        [$code, $body] = self::s3('PUT', $key, file_get_contents($srcFile), ['Content-Type' => $mime]);
        must($code >= 200 && $code < 300, 'The file could not be stored in the cloud bucket (HTTP ' . $code . ').');
    }

    /** Output the file body (headers are set by the caller). */
    public static function stream(string $key): bool
    {
        must(preg_match('/^[A-Za-z0-9._-]+$/', $key) === 1, 'Invalid storage key.');
        if (self::driver() === 'local') {
            $p = self::localRoot() . '/' . $key;
            if (!is_file($p)) return false;
            readfile($p);
            return true;
        }
        [$code, $body] = self::s3('GET', $key);
        if ($code !== 200) return false;
        echo $body;
        return true;
    }

    public static function size(string $key): ?int
    {
        if (self::driver() === 'local') { $p = self::localRoot() . '/' . $key; return is_file($p) ? filesize($p) : null; }
        return null;
    }

    public static function exists(string $key): bool
    {
        if (self::driver() === 'local') return is_file(self::localRoot() . '/' . $key);
        [$code] = self::s3('HEAD', $key);
        return $code === 200;
    }

    public static function writable(): bool
    {
        if (self::driver() === 'local') return is_writable(self::localRoot());
        return env('S3_BUCKET') && env('S3_KEY') && env('S3_SECRET');
    }

    // ---------------------------------------------------------------- AWS Signature Version 4
    /**
     * Pure function (unit-tested against the AWS documentation example).
     * @param array<string,string> $headers lower-case header names => values (must include host, x-amz-content-sha256, x-amz-date)
     */
    public static function sigv4(string $method, string $uri, string $query, array $headers, string $payloadHash, string $region, string $service,
                                 string $secret, string $amzDate): array
    {
        ksort($headers);
        $canonHeaders = '';
        foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim($v) . "\n";
        $signed = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $uri, $query, $canonHeaders, $signed, $payloadHash]);
        $date = substr($amzDate, 0, 8);
        $scope = "$date/$region/$service/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4' . $secret, true), true), true), true);
        return ['signature' => hash_hmac('sha256', $toSign, $k), 'signed_headers' => $signed, 'scope' => $scope, 'canonical' => $canonical];
    }

    /** @return array{0:int,1:string} HTTP status and body */
    private static function s3(string $method, string $key, string $body = '', array $extra = []): array
    {
        $endpoint = rtrim((string)env('S3_ENDPOINT', 'https://s3.' . env('S3_REGION', 'us-east-1') . '.amazonaws.com'), '/');
        $bucket = (string)env('S3_BUCKET');
        $region = (string)env('S3_REGION', 'us-east-1');
        $host = parse_url($endpoint, PHP_URL_HOST) . (parse_url($endpoint, PHP_URL_PORT) ? ':' . parse_url($endpoint, PHP_URL_PORT) : '');
        $uri = '/' . rawurlencode($bucket) . '/' . rawurlencode($key);
        $amz = gmdate('Ymd\THis\Z');
        $hash = hash('sha256', $body);
        $h = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $amz];
        $sig = self::sigv4($method, $uri, '', $h, $hash, $region, 's3', (string)env('S3_SECRET'), $amz);
        $auth = 'AWS4-HMAC-SHA256 Credential=' . env('S3_KEY') . '/' . $sig['scope'] . ', SignedHeaders=' . $sig['signed_headers'] . ', Signature=' . $sig['signature'];
        $ch = curl_init($endpoint . $uri);
        $hdr = ['Authorization: ' . $auth, 'x-amz-content-sha256: ' . $hash, 'x-amz-date: ' . $amz];
        foreach ($extra as $k => $v) $hdr[] = "$k: $v";
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_SSL_VERIFYPEER => true]);
        if ($method === 'PUT') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($out) ? $out : ''];
    }
}
