<?php
/** Time-based one-time passwords (RFC 6238, SHA-1, 6 digits, 30 s) for multi-factor authentication. No external library. */
final class Mfa
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $base32Secret, ?int $time = null, int $digits = 6, int $period = 30): string
    {
        $counter = intdiv($time ?? time(), $period);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), self::base32Decode($base32Secret), true);
        $o = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Accepts the current code and one period either side (clock drift). Returns the matching time-step or null (replay guard uses it). */
    public static function verify(string $base32Secret, string $input, ?int $time = null): ?int
    {
        $input = preg_replace('/\D/', '', $input);
        if (strlen($input) !== 6) return null;
        $t = $time ?? time();
        foreach ([0, -30, 30] as $d) {
            if (hash_equals(self::code($base32Secret, $t + $d), $input)) return intdiv($t + $d, 30);
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return array{0:list<string>,1:string} plain codes to show once, and the JSON of their hashes to store */
    public static function recoveryCodes(int $n = 8): array
    {
        $plain = []; $hashes = [];
        for ($i = 0; $i < $n; $i++) {
            $c = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $plain[] = $c;
            $hashes[] = password_hash($c, PASSWORD_DEFAULT);
        }
        return [$plain, json_encode($hashes)];
    }

    /** Consume a recovery code if valid. Returns the new JSON list (with the code removed) or null. */
    public static function consumeRecovery(?string $json, string $input): ?string
    {
        $list = $json ? json_decode($json, true) : [];
        $input = strtoupper(trim($input));
        foreach ($list as $i => $h) {
            if (password_verify($input, $h)) { unset($list[$i]); return json_encode(array_values($list)); }
        }
        return null;
    }
}
