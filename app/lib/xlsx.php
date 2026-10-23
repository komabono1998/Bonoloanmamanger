<?php
/** Minimal .xlsx writer (SpreadsheetML in a "stored" ZIP, no extension needed): one sheet, header styling, numeric cells, thousands separators. */
final class Xlsx
{
    private static function col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
    private static function x(string $s): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    /**
     * @param string[] $titleLines lines above the table (institution, report title, scope ...)
     * @param string[] $head       column labels
     * @param array[]  $rows       values; $numeric[col] = true makes the column a number
     * @param array    $totals     optional totals row (same width, null = blank)
     */
    public static function build(string $sheetName, array $titleLines, array $head, array $rows, array $numeric = [], ?array $totals = null, ?string $color = null, ?array $logo = null): string
    {
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$color) ? strtoupper(ltrim($color, '#')) : '131A2B';
        $img = null;
        if ($logo && ($sz = @getimagesizefromstring($logo[0])) && in_array($sz[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) $img = ['bytes' => $logo[0], 'ext' => $sz[2] === IMAGETYPE_PNG ? 'png' : 'jpeg', 'w' => $sz[0], 'h' => $sz[1]];
        $r = 0; $sheet = '';
        $cell = function (int $c, int $row, $v, int $style = 0, bool $num = false): string {
            $ref = self::col($c) . $row;
            if ($v === null || $v === '') return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '/>';
            if ($num && is_numeric($v)) return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . $v . '</v></c>';
            $v = is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : (string)$v;
            return '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
        };
        foreach ($titleLines as $i => $t) { $r++; $sheet .= '<row r="' . $r . '">' . $cell(0, $r, $t, $i === 0 ? 4 : ($i === 1 ? 3 : 0)) . '</row>'; }
        $r++; $sheet .= '<row r="' . $r . '"/>';
        $r++; $hr = $r; $sheet .= '<row r="' . $r . '">'; foreach ($head as $c => $h) $sheet .= $cell($c, $r, $h, 1); $sheet .= '</row>';
        foreach ($rows as $row) { $r++; $sheet .= '<row r="' . $r . '">'; foreach (array_values($row) as $c => $v) $sheet .= $cell($c, $r, $v, !empty($numeric[$c]) ? 2 : 0, !empty($numeric[$c])); $sheet .= '</row>'; }
        if ($totals) { $r++; $sheet .= '<row r="' . $r . '">'; foreach (array_values($totals) as $c => $v) $sheet .= $cell($c, $r, $v, 5, $v !== null && is_numeric($v)); $sheet .= '</row>'; }
        $cols = '<cols>' . implode('', array_map(fn($c) => '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . min(48, max(12, mb_strlen((string)($head[$c] ?? '')) + 4)) . '" customWidth="1"/>', array_keys($head))) . '</cols>';
        $ws = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $hr . '" topLeftCell="A' . ($hr + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . $cols . '<sheetData>' . $sheet . '</sheetData>' . ($img ? '<drawing r:id="rId1"/>' : '') . '</worksheet>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="13"/><color rgb="FF' . $color . '"/><name val="Calibri"/></font><font><b/><sz val="15"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="3" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/></cellXfs></styleSheet>';
        $name = preg_replace('/[\[\]\*\?:\/\\\\]/', '', mb_substr($sheetName, 0, 31)) ?: 'Report';
        $extra = [];
        if ($img) {
            $hpx = 56; $wpx = min(220, (int)round($img['w'] * $hpx / $img['h'])); $emu = 9525;
            $col = max(2, count($head) - 1);
            $extra = [
                'xl/media/logo.' . $img['ext'] => $img['bytes'],
                'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>',
                'xl/drawings/_rels/drawing1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.' . $img['ext'] . '"/></Relationships>',
                'xl/drawings/drawing1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    . '<xdr:oneCellAnchor><xdr:from><xdr:col>' . $col . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="' . ($wpx * $emu) . '" cy="' . ($hpx * $emu) . '"/>'
                    . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                    . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($wpx * $emu) . '" cy="' . ($hpx * $emu) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>',
            ];
        }
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . ($img ? '<Default Extension="' . $img['ext'] . '" ContentType="image/' . $img['ext'] . '"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '') . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::x($name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $styles, 'xl/worksheets/sheet1.xml' => $ws,
        ];
        return self::zip($files + $extra);
    }

    /** ZIP archive using the "stored" method (no compression). */
    public static function zip(array $files): string
    {
        $out = ''; $cd = ''; $n = 0;
        [$dt, $dd] = [0, (1 << 5) | 1 | ((2026 - 1980) << 9)];
        foreach ($files as $name => $data) {
            $crc = crc32($data); $len = strlen($data); $off = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dt, $dd, $crc, $len, $len, strlen($name), 0) . $name . $data;
            $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dt, $dd, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $n++;
        }
        return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
    }
}
