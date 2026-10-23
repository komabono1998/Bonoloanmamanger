<?php
/**
 * Minimal PDF writer (no external library): A4 pages, Helvetica text, filled rectangles. Enough for report tables.
 * Text is converted to Windows-1252; characters outside it are replaced.
 */
final class SimplePdf
{
    private const W = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    public float $w; public float $h;
    private array $pages = []; private string $cur = ''; private array $images = [];

    public function __construct(bool $landscape = true)
    {
        [$this->w, $this->h] = $landscape ? [841.89, 595.28] : [595.28, 841.89];
    }

    public function addPage(): void
    {
        if ($this->cur !== '' || $this->pages) $this->pages[] = $this->cur;
        $this->cur = '';
    }

    public static function enc(string $s): string
    {
        $s = strtr($s, ['…' => '...', '→' => '->', '−' => '-', '≥' => '>=', '≤' => '<=', '✔' => 'OK', '✖' => 'X', "\u{00A0}" => ' ']);
        $o = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $o === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $o;
    }

    public static function width(string $s, float $size, bool $bold = false): float
    {
        $t = 0; $e = self::enc($s);
        for ($i = 0, $n = strlen($e); $i < $n; $i++) { $c = ord($e[$i]); $t += ($c >= 32 && $c <= 126) ? self::W[$c] : 556; }
        return $t / 1000 * $size * ($bold ? 1.05 : 1);
    }

    public function fit(string $s, float $size, float $max, bool $bold = false): string
    {
        if (self::width($s, $size, $bold) <= $max) return $s;
        while ($s !== '' && self::width($s . '...', $size, $bold) > $max) $s = mb_substr($s, 0, mb_strlen($s) - 1);
        return $s . '...';
    }

    private static function rgb(array $c): string { return sprintf('%.3f %.3f %.3f', $c[0] / 255, $c[1] / 255, $c[2] / 255); }

    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->cur .= self::rgb($fill) . ' rg ' . sprintf('%.2f %.2f %.2f %.2f', $x, $y, $w, $h) . " re f\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $c = [200, 205, 215]): void
    {
        $this->cur .= self::rgb($c) . ' RG 0.5 w ' . sprintf('%.2f %.2f m %.2f %.2f l S', $x1, $y1, $x2, $y2) . "\n";
    }

    public function text(float $x, float $y, string $s, float $size = 8, bool $bold = false, array $color = [20, 24, 36], string $align = 'L'): void
    {
        if ($s === '') return;
        $wd = self::width($s, $size, $bold);
        if ($align === 'R') $x -= $wd; elseif ($align === 'C') $x -= $wd / 2;
        $esc = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], self::enc($s));
        $this->cur .= 'BT ' . self::rgb($color) . ' rg /' . ($bold ? 'F2' : 'F1') . ' ' . $size . ' Tf ' . sprintf('%.2f %.2f Td', $x, $y) . ' (' . $esc . ") Tj ET\n";
    }


    /**
     * Register a JPEG or PNG (8-bit, not interlaced) for drawing; returns its number or null when the format is not supported.
     * No image library is needed: JPEG is embedded as is, PNG is unpacked and recompressed (with its transparency as a soft mask).
     */
    public function addImage(string $bytes): ?int
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info) return null;
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w * $h > 2000000) return null;
        if ($info[2] === IMAGETYPE_JPEG) {
            $ch = (int)($info['channels'] ?? 3);
            if (!in_array($ch, [1, 3], true)) return null;
            $this->images[] = ['w' => $w, 'h' => $h, 'cs' => $ch === 1 ? 'DeviceGray' : 'DeviceRGB', 'filter' => '/Filter /DCTDecode', 'data' => $bytes, 'alpha' => null];
            return count($this->images);
        }
        if ($info[2] !== IMAGETYPE_PNG) return null;
        $png = self::decodePng($bytes);
        if (!$png) return null;
        $this->images[] = ['w' => $png['w'], 'h' => $png['h'], 'cs' => $png['gray'] ? 'DeviceGray' : 'DeviceRGB', 'filter' => '/Filter /FlateDecode', 'data' => gzcompress($png['pix']),
            'alpha' => $png['alpha'] !== null ? gzcompress($png['alpha']) : null];
        return count($this->images);
    }

    public function drawImage(int $no, float $x, float $y, float $w, float $h): void
    {
        $this->cur .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im%d Do Q\n", $w, $h, $x, $y, $no);
    }

    public function imageSize(int $no): array { return [$this->images[$no - 1]['w'], $this->images[$no - 1]['h']]; }

    private static function decodePng(string $d): ?array
    {
        if (substr($d, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        $pos = 8; $idat = ''; $ihdr = null; $plte = ''; $trns = '';
        while ($pos + 8 <= strlen($d)) {
            $len = unpack('N', substr($d, $pos, 4))[1]; $type = substr($d, $pos + 4, 4); $body = substr($d, $pos + 8, $len);
            if ($type === 'IHDR') $ihdr = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilter/Cinter', $body);
            elseif ($type === 'PLTE') $plte = $body; elseif ($type === 'tRNS') $trns = $body; elseif ($type === 'IDAT') $idat .= $body; elseif ($type === 'IEND') break;
            $pos += 12 + $len;
        }
        if (!$ihdr || $ihdr['depth'] !== 8 || $ihdr['inter'] !== 0 || !in_array($ihdr['ctype'], [0, 2, 3, 4, 6], true)) return null;
        $raw = @gzuncompress($idat);
        if ($raw === false) return null;
        $w = $ihdr['w']; $h = $ihdr['h']; $ct = $ihdr['ctype'];
        $bpp = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct]; $stride = $w * $bpp;
        if (strlen($raw) < ($stride + 1) * $h) return null;
        $prev = array_fill(0, $stride, 0); $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $f = ord($raw[$y * ($stride + 1)]); $line = array_values(unpack('C*', substr($raw, $y * ($stride + 1) + 1, $stride)));
            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= $bpp ? $line[$i - $bpp] : 0; $b = $prev[$i]; $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $x = $line[$i];
                switch ($f) {
                    case 1: $x += $a; break;
                    case 2: $x += $b; break;
                    case 3: $x += ($a + $b) >> 1; break;
                    case 4: $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c); $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c); break;
                }
                $line[$i] = $x & 255;
            }
            $rows[] = $line; $prev = $line;
        }
        $pix = ''; $alpha = ($ct === 4 || $ct === 6 || ($ct === 3 && $trns !== '') || ($ct !== 3 && $trns !== '' && false)) ? '' : null;
        foreach ($rows as $line) {
            for ($i = 0; $i < $stride; $i += $bpp) {
                switch ($ct) {
                    case 0: $pix .= chr($line[$i]); break;
                    case 2: $pix .= chr($line[$i]) . chr($line[$i + 1]) . chr($line[$i + 2]); break;
                    case 3: $k = $line[$i]; $pix .= substr($plte, $k * 3, 3) ?: "\0\0\0"; if ($alpha !== null) $alpha .= $k < strlen($trns) ? $trns[$k] : "\xFF"; break;
                    case 4: $pix .= chr($line[$i]); $alpha .= chr($line[$i + 1]); break;
                    case 6: $pix .= chr($line[$i]) . chr($line[$i + 1]) . chr($line[$i + 2]); $alpha .= chr($line[$i + 3]); break;
                }
            }
        }
        return ['w' => $w, 'h' => $h, 'pix' => $pix, 'alpha' => $alpha, 'gray' => in_array($ct, [0, 4], true)];
    }

    public function pageCount(): int { return count($this->pages) + 1; }

    public function output(): string
    {
        $pages = array_merge($this->pages, [$this->cur]);
        $objs = [];
        $n = count($pages);
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (5 + $i * 2) . ' 0 R';
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5 + $n * 2; $xo = '';
        foreach ($this->images as $k => $im) {
            $id = $next++; $xo .= '/Im' . ($k + 1) . ' ' . $id . ' 0 R ';
            $mask = '';
            if ($im['alpha'] !== null) {
                $mid = $next++; $mask = ' /SMask ' . $mid . ' 0 R';
                $objs[$mid] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>', $im['w'], $im['h'], strlen($im['alpha'])) . "
stream
" . $im['alpha'] . "
endstream";
            }
            $objs[$id] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 %s%s /Length %d >>', $im['w'], $im['h'], $im['cs'], $im['filter'], $mask, strlen($im['data'])) . "
stream
" . $im['data'] . "
endstream";
        }
        foreach ($pages as $i => $content) {
            $p = 5 + $i * 2;
            $objs[$p] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . ($xo ? ' /XObject << ' . $xo . '>>' : '') . ' >> /Contents %d 0 R >>', $this->w, $this->h, $p + 1);
            $objs[$p + 1] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        ksort($objs);
        foreach ($objs as $id => $body) { $off[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        return $pdf . 'trailer << /Size ' . (count($objs) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF";
    }
}
