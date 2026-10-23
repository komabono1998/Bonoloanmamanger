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
    private array $pages = []; private string $cur = '';

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
        foreach ($pages as $i => $content) {
            $p = 5 + $i * 2;
            $objs[$p] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $p + 1);
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
