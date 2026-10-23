<?php
/**
 * Runs a report for the Report Centre and for downloads, so the screen and every file (PDF, Excel, CSV, Word, JSON) show exactly
 * the same rows. Handles the filters (period presets, branch, officer, cashier, channel, client, loan, status, amount, text) and
 * the descriptive header (scope, filters, who generated it and when).
 */
final class ReportRun
{
    public const PRESETS = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'last7' => 'Last 7 days', 'month' => 'This month', 'lastmonth' => 'Last month',
        'last30' => 'Last 30 days', 'quarter' => 'This quarter', 'last90' => 'Last 90 days', 'year' => 'This year'];
    public const FORMATS = ['pdf' => ['PDF', 'file'], 'xlsx' => ['Excel (.xlsx)', 'bar'], 'csv' => ['CSV', 'layers'], 'doc' => ['Word', 'clipboard'], 'json' => ['JSON (data)', 'hash']];
    private const AMOUNT_COLS = ['total_out', 'amount', 'approved_principal', 'principal_out', 'total', 'net_amount', 'amount_requested', 'principal', 'balance'];

    public static function presetRange(string $p, string $asOf): ?array
    {
        $t = strtotime($asOf);
        return match ($p) {
            'today' => [$asOf, $asOf],
            'yesterday' => [date('Y-m-d', strtotime('-1 day', $t)), date('Y-m-d', strtotime('-1 day', $t))],
            'week' => [date('Y-m-d', strtotime('monday this week', $t)), $asOf],
            'last7' => [date('Y-m-d', strtotime('-6 days', $t)), $asOf],
            'month' => [date('Y-m-01', $t), $asOf],
            'lastmonth' => [date('Y-m-01', strtotime('first day of last month', $t)), date('Y-m-t', strtotime('first day of last month', $t))],
            'last30' => [date('Y-m-d', strtotime('-29 days', $t)), $asOf],
            'quarter' => [date('Y-m-d', mktime(0, 0, 0, (int)floor((date('n', $t) - 1) / 3) * 3 + 1, 1, (int)date('Y', $t))), $asOf],
            'last90' => [date('Y-m-d', strtotime('-89 days', $t)), $asOf],
            'year' => [date('Y-01-01', $t), $asOf],
            default => null,
        };
    }

    /** Normalised filters from a query string / request array. */
    public static function params(array $src): array
    {
        $g = fn(string $k) => trim((string)($src[$k] ?? ''));
        $asOf = reporting_date();
        $days = max(1, setting_int('report_default_days'));
        $p = ['from' => date('Y-m-d', strtotime("-$days days", strtotime($asOf))), 'to' => $asOf, 'preset' => '',
            'branch' => (int)$g('branch'), 'officer' => (int)$g('officer'), 'cashier' => (int)$g('cashier'), 'method' => $g('method'), 'client' => (int)$g('client'),
            'loan' => $g('loan'), 'days' => (int)$g('days'), 'status' => $g('status'), 'view' => $g('view'), 'q' => $g('q'), 'min' => $g('min'), 'max' => $g('max')];
        if (is_date($g('from'))) $p['from'] = $g('from');
        if (is_date($g('to'))) $p['to'] = $g('to');
        if (isset(self::PRESETS[$g('preset')]) && ($r = self::presetRange($g('preset'), $asOf))) { [$p['from'], $p['to']] = $r; $p['preset'] = $g('preset'); }
        must($p['from'] <= $p['to'], 'The start date must not be after the end date.');
        return $p;
    }

    /** Parameters worth keeping in a URL (non-empty). */
    public static function query(array $p, bool $periodic = true): array
    {
        $o = [];
        foreach (['branch', 'officer', 'cashier', 'method', 'client', 'loan', 'days', 'status', 'view', 'q', 'min', 'max'] as $k) if ($p[$k] !== '' && $p[$k] !== 0) $o[$k] = $p[$k];
        if ($periodic) { $o['from'] = $p['from']; $o['to'] = $p['to']; if ($p['preset']) $o['preset'] = $p['preset']; }
        return $o;
    }

    /** Run the engine, then apply the filters that the engine itself does not handle. */
    public static function execute(array $def, array $p): array
    {
        $rep = ReportEngines::run($def['engine'], ['from' => $p['from'], 'to' => $p['to'], 'officer' => $p['officer'], 'cashier' => $p['cashier'], 'method' => $p['method'],
            'client' => $p['client'], 'branch' => $p['branch'], 'loan' => $p['loan'], 'days' => $p['days'], 'view' => $p['view']]);
        $cols = $rep['cols']; $rows = $rep['rows']; $total = count($rows);
        $fmt = fn(string $k) => is_array($cols[$k] ?? null) ? ($cols[$k][1] ?? 'text') : 'text';
        $amountCol = null; foreach (self::AMOUNT_COLS as $k) if (isset($cols[$k]) && in_array($fmt($k), ['money', 'num'], true)) { $amountCol = $k; break; }
        $statuses = [];
        if (isset($cols['status'])) foreach ($rows as $r) if (($r['status'] ?? '') !== '') $statuses[(string)$r['status']] = 1;
        ksort($statuses);
        $applicable = ['loan' => isset($cols['loan_no']), 'client' => isset($cols['client_no']) || isset($cols['full_name']), 'officer' => isset($cols['officer']), 'cashier' => isset($cols['cashier']),
            'method' => isset($cols['method']), 'branch' => isset($cols['branch']), 'status' => array_keys($statuses), 'amount' => $amountCol, 'q' => $total > 0];

        $name = fn(string $sql, int $id) => (string)(val($sql, [$id]) ?: '');
        $m = [];
        if ($p['loan'] !== '' && $applicable['loan']) $m[] = fn($r) => stripos((string)($r['loan_no'] ?? ''), $p['loan']) !== false;
        if ($p['client'] && $applicable['client']) {
            $c = row('SELECT client_no, full_name FROM clients WHERE id = ?', [$p['client']]);
            if ($c) $m[] = fn($r) => (($r['client_no'] ?? null) === $c['client_no']) || (!isset($r['client_no']) && ($r['full_name'] ?? null) === $c['full_name']);
        }
        if ($p['officer'] && $applicable['officer']) { $n = $name('SELECT full_name FROM users WHERE id = ?', $p['officer']); $m[] = fn($r) => ($r['officer'] ?? '') === $n; }
        if ($p['cashier'] && $applicable['cashier']) { $n = $name('SELECT full_name FROM users WHERE id = ?', $p['cashier']); $m[] = fn($r) => ($r['cashier'] ?? '') === $n; }
        if ($p['method'] !== '' && $applicable['method']) $m[] = fn($r) => ($r['method'] ?? '') === $p['method'];
        if ($p['branch'] && $applicable['branch']) { $n = branch_name($p['branch']); $m[] = fn($r) => ($r['branch'] ?? '') === $n; }
        if ($p['status'] !== '' && $cols && isset($cols['status'])) $m[] = fn($r) => (string)($r['status'] ?? '') === $p['status'];
        if ($amountCol && $p['min'] !== '') { $lo = (float)str_replace(',', '', $p['min']); $m[] = fn($r) => (float)($r[$amountCol] ?? 0) >= $lo; }
        if ($amountCol && $p['max'] !== '') { $hi = (float)str_replace(',', '', $p['max']); $m[] = fn($r) => (float)($r[$amountCol] ?? 0) <= $hi; }
        if ($p['q'] !== '') { $needle = mb_strtolower($p['q']); $m[] = function ($r) use ($needle) { foreach ($r as $v) if (is_scalar($v) && str_contains(mb_strtolower((string)$v), $needle)) return true; return false; }; }
        if ($m) $rows = array_values(array_filter($rows, function ($r) use ($m) { foreach ($m as $f) if (!$f($r)) return false; return true; }));
        $rep['rows'] = $rows;
        return ['rep' => $rep, 'total' => $total, 'shown' => count($rows), 'applicable' => $applicable];
    }

    /** Scope statement: which branches / portfolio the figures cover. */
    public static function scopeText(array $p): string
    {
        $allBranches = count(all_branch_options(false));
        $chosen = $p['branch'] ? [$p['branch']] : accessible_branch_ids();
        $t = count($chosen) >= $allBranches && $allBranches > 0 ? 'All branches (' . count($chosen) . ')' : implode(', ', array_map('branch_name', $chosen)) . ' — ' . count($chosen) . ' of ' . $allBranches . ' branches';
        if (portfolio_restricted()) $t .= ' · only loans assigned to you and clients you registered';
        if (ReportEngines::ownCashierOnly()) $t .= ' · only your own transactions';
        return $t;
    }

    /** Human-readable list of the filters in force: [label => value]. */
    public static function filterText(array $def, array $p): array
    {
        $o = [];
        if (str_contains($def['filters'], 'f')) $o['Period'] = fmt_date($p['from']) . ' to ' . fmt_date($p['to']) . ($p['preset'] ? ' (' . self::PRESETS[$p['preset']] . ')' : '');
        if ($p['branch']) $o['Branch'] = branch_name($p['branch']);
        if ($p['officer']) $o['Loan officer'] = (string)val('SELECT full_name FROM users WHERE id = ?', [$p['officer']]);
        if ($p['cashier'] && !ReportEngines::ownCashierOnly()) $o['Cashier'] = (string)val('SELECT full_name FROM users WHERE id = ?', [$p['cashier']]);
        if ($p['method'] !== '') $o['Channel'] = $p['method'];
        if ($p['client']) $o['Client'] = (string)val('SELECT full_name FROM clients WHERE id = ?', [$p['client']]);
        if ($p['loan'] !== '') $o['Loan'] = $p['loan'];
        if ($p['days']) $o['Days'] = (string)$p['days'];
        if ($p['status'] !== '') $o['Status'] = $p['status'];
        if ($p['view'] !== '' && isset(Gl::VIEWS[$p['view']])) $o['Statement'] = Gl::VIEWS[$p['view']];
        if ($p['min'] !== '') $o['Amount from'] = $p['min'];
        if ($p['max'] !== '') $o['Amount to'] = $p['max'];
        if ($p['q'] !== '') $o['Text'] = '"' . $p['q'] . '"';
        return $o;
    }

    /** Lines printed above a downloaded report. */
    public static function headerLines(array $def, array $p, array $run): array
    {
        $f = self::filterText($def, $p);
        $lines = [business_name(), $def['id'] . ' · ' . $def['title']];
        $lines[] = ($def['filters'] !== '' && str_contains($def['filters'], 'f') ? 'Period: ' . fmt_date($p['from']) . ' to ' . fmt_date($p['to']) . ' (business dates)' : 'As at business date ' . fmt_date(reporting_date())) . ' · Currency: ' . setting('currency');
        $lines[] = 'Scope: ' . self::scopeText($p);
        if ($f) $lines[] = 'Filters: ' . implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($f), $f));
        $lines[] = 'Rows: ' . $run['shown'] . ($run['shown'] !== $run['total'] ? ' of ' . $run['total'] : '') . ' · Generated on ' . fmt_dt(now()) . ' by ' . user()['full_name'] . ' (' . user()['role'] . ')';
        $note = $run['rep']['note'] !== '' ? $run['rep']['note'] : $def['note'];
        if ($note !== '') $lines[] = 'Basis: ' . $note;
        return $lines;
    }

    /** Plain table for downloads: head, keys, formats, rows (raw values) and a totals row. */
    public static function table(array $rep): array
    {
        $head = []; $keys = []; $fmt = [];
        foreach ($rep['cols'] as $k => $c) {
            $c = is_array($c) ? $c : [$c];
            if (isset($c[1]) && !is_string($c[1])) continue;
            $head[] = $c[0]; $keys[] = $k; $fmt[] = $c[1] ?? 'text';
        }
        $rows = []; foreach ($rep['rows'] as $r) $rows[] = array_map(fn($k) => $r[$k] ?? '', $keys);
        $tot = null;
        if ($rep['totals']) {
            $tot = []; $first = true;
            foreach ($keys as $i => $k) {
                if (in_array($k, $rep['totals'], true)) $tot[] = array_sum(array_map(fn($r) => (float)($r[$i] ?? 0), $rows));
                else { $tot[] = $first ? 'Total' : null; }
                $first = false;
            }
        }
        return ['head' => $head, 'keys' => $keys, 'fmt' => $fmt, 'rows' => $rows, 'totals' => $tot];
    }

    private static function display($v, string $fmt): string
    {
        return match ($fmt) { 'money', 'num' => $v === '' || $v === null ? '' : number_format((float)$v), 'date' => $v === '' || $v === null ? '' : fmt_date($v), 'dt' => $v === '' || $v === null ? '' : fmt_dt($v), default => (string)$v };
    }

    // ---------------------------------------------------------------- file formats
    public static function pdf(array $def, array $run, array $lines): string
    {
        $t = self::table($run['rep']); $nc = max(1, count($t['head']));
        $pdf = new SimplePdf($nc > 5);
        $mx = 36; $avail = $pdf->w - 2 * $mx; $size = 7.5; $pad = 7; $rowH = 13.5; $thH = 17;
        $cells = []; foreach ($t['rows'] as $r) { $line = []; foreach ($r as $i => $v) $line[] = self::display($v, $t['fmt'][$i]); $cells[] = $line; }
        if ($t['totals']) { $line = []; foreach ($t['totals'] as $i => $v) $line[] = $v === null ? '' : (is_numeric($v) ? number_format((float)$v) : (string)$v); $cells[] = $line; }
        $nat = [];
        foreach ($t['head'] as $i => $h) {
            $w = SimplePdf::width($h, $size, true);
            foreach (array_slice($cells, 0, 150) as $r) $w = max($w, SimplePdf::width($r[$i] ?? '', $size));
            $nat[$i] = min(230, $w + $pad * 2);
        }
        $sum = array_sum($nat); $cw = $nat;
        if ($sum > $avail) { $f = $avail / $sum; foreach ($cw as $i => $w) $cw[$i] = max(36, $w * $f); $s2 = array_sum($cw); if ($s2 > $avail) foreach ($cw as $i => $w) $cw[$i] = $w * $avail / $s2; }
        elseif ($sum < $avail * 0.7) { /* narrow tables stay narrow */ }
        $brand = brand(); $bc = brand_rgb(); $meta = array_slice($lines, 2); $orgL = org_lines();
        $logo = null; if ($lf = brand_logo_file()) { $logo = $pdf->addImage($lf[0]); }
        $brandH = 56;
        $y1 = $pdf->h - (36 + $brandH + 28 + 10.5 * count($meta) + 6);                      // first-page table top
        $y2 = $pdf->h - 70;                                                                  // later pages
        $bottom = 44;
        $cap1 = max(1, (int)floor(($y1 - $thH - $bottom) / $rowH)); $cap2 = max(1, (int)floor(($y2 - $thH - $bottom) / $rowH));
        $pages = []; $i = 0; $n = count($cells);
        while ($i < $n || !$pages) { $cap = $pages ? $cap2 : $cap1; $pages[] = [$i, min($n, $i + $cap)]; $i += $cap; if ($i >= $n) break; }
        $totalPages = count($pages);
        $hasTotals = (bool)$t['totals'];
        foreach ($pages as $pi => [$a, $b]) {
            $pdf->addPage();
            if ($pi === 0) {
                // letterhead: logo, organisation, address and contacts, with the report reference on the right
                $tx = $mx; $topY = $pdf->h - 30;
                if ($logo) {
                    [$iw, $ih] = $pdf->imageSize($logo); $sc = min(120 / $iw, 46 / $ih); $lw = $iw * $sc; $lh = $ih * $sc;
                    $pdf->drawImage($logo, $mx, $topY - $lh, $lw, $lh); $tx = $mx + $lw + 12;
                }
                $pdf->text($tx, $topY - 12, $pdf->fit($brand['name'], 14, $avail * 0.62, true), 14, true, $bc);
                if ($brand['tagline'] !== '') $pdf->text($tx, $topY - 23, $pdf->fit($brand['tagline'], 7.5, $avail * 0.6), 7.5, false, [110, 118, 135]);
                foreach (array_slice($orgL, 0, 3) as $oi => $ol) $pdf->text($tx, $topY - 34 - 9.5 * $oi, $pdf->fit($ol, 7.5, $avail * 0.62), 7.5, false, [80, 88, 105]);
                $pdf->text($pdf->w - $mx, $topY - 12, $def['id'], 12, true, $bc, 'R');
                $pdf->text($pdf->w - $mx, $topY - 24, 'Generated ' . fmt_dt(now()), 7.5, false, [80, 88, 105], 'R');
                $pdf->text($pdf->w - $mx, $topY - 34, 'by ' . user()['full_name'], 7.5, false, [80, 88, 105], 'R');
                $ruleY = $pdf->h - 30 - $brandH + 4;
                $pdf->rect($mx, $ruleY, $avail, 2.2, $bc);
                $pdf->text($mx, $ruleY - 20, $lines[1] ?? '', 14, true);
                foreach ($meta as $li => $ln) $pdf->text($mx, $ruleY - 34 - 10.5 * $li, $pdf->fit($ln, 7.5, $avail), 7.5, false, [90, 98, 115]);
                $top = $y1;
            } else {
                $pdf->text($mx, $pdf->h - 40, $pdf->fit($brand['name'], 10, $avail * 0.6, true), 10, true, $bc);
                $pdf->text($pdf->w - $mx, $pdf->h - 40, $def['id'], 9, true, $bc, 'R');
                $pdf->rect($mx, $pdf->h - 46, $avail, 1.2, $bc);
                $pdf->text($mx, $pdf->h - 58, $def['title'] . ' (continued)', 9, false, [90, 98, 115]); $top = $y2;
            }
            // table header
            $pdf->rect($mx, $top - $thH + 4, $avail, $thH, $bc);
            $x = $mx;
            foreach ($t['head'] as $c => $h) {
                $right = in_array($t['fmt'][$c], ['money', 'num'], true);
                $pdf->text($right ? $x + $cw[$c] - $pad : $x + $pad, $top - $thH + 9.5, $pdf->fit(strtoupper($h), 6.8, $cw[$c] - 2 * $pad, true), 6.8, true, [255, 255, 255], $right ? 'R' : 'L');
                $x += $cw[$c];
            }
            $y = $top - $thH;
            for ($r = $a; $r < $b; $r++) {
                $isTot = $hasTotals && $r === $n - 1;
                if ($isTot) $pdf->rect($mx, $y - $rowH + 4, $avail, $rowH, [235, 238, 244]);
                elseif (($r - $a) % 2 === 1) $pdf->rect($mx, $y - $rowH + 4, $avail, $rowH, [246, 247, 250]);
                $x = $mx;
                foreach ($cells[$r] as $c => $v) {
                    $right = in_array($t['fmt'][$c], ['money', 'num'], true) || ($isTot && is_numeric(str_replace(',', '', $v)) && $v !== '');
                    $pdf->text($right ? $x + $cw[$c] - $pad : $x + $pad, $y - $rowH + 8, $pdf->fit($v, $size, $cw[$c] - 2 * $pad, $isTot), $size, $isTot, [20, 24, 36], $right ? 'R' : 'L');
                    $x += $cw[$c];
                }
                $y -= $rowH;
            }
            if (!$cells) $pdf->text($mx + $pad, $y - 10, 'No data for these filters.', 8, false, [90, 98, 115]);
            $pdf->line($mx, 34, $pdf->w - $mx, 34);
            $pdf->rect($mx, 34, $avail, 1.2, $bc);
            $pdf->text($mx, 22, 'Generated ' . fmt_dt(now()) . ' by ' . user()['full_name'] . ' (' . user()['role'] . ') · ' . business_name() . ' · ' . implode(' · ', array_slice($orgL, 1, 1)), 7, false, [110, 118, 135]);
            $pdf->text($pdf->w - $mx, 22, 'Page ' . ($pi + 1) . ' of ' . $totalPages, 7, false, [110, 118, 135], 'R');
        }
        return $pdf->output();
    }

    /** Name and title first, then address / telephone / e-mail / registration lines, then the report details. */
    private static function withOrg(array $lines): array
    {
        return array_merge(array_slice($lines, 0, 2), org_lines(), array_slice($lines, 2));
    }

    public static function xlsx(array $def, array $run, array $lines): string
    {
        $lines = self::withOrg($lines);
        $t = self::table($run['rep']);
        $numeric = []; foreach ($t['fmt'] as $i => $f) $numeric[$i] = in_array($f, ['money', 'num'], true);
        return Xlsx::build($def['id'], $lines, $t['head'], $t['rows'], $numeric, $t['totals'], brand()['color'], brand_logo_file());
    }

    public static function csv(array $def, array $run, array $lines): string
    {
        $lines = self::withOrg($lines);
        $t = self::table($run['rep']);
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($lines as $l) fputcsv($f, [$l]);
        fputcsv($f, []);
        fputcsv($f, $t['head']);
        $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
        foreach ($t['rows'] as $r) fputcsv($f, array_map($safe, $r));
        if ($t['totals']) fputcsv($f, array_map(fn($v) => $v ?? '', $t['totals']));
        rewind($f); $s = stream_get_contents($f); fclose($f);
        return $s;
    }

    public static function doc(array $def, array $run, array $lines): string
    {
        $t = self::table($run['rep']);
        $b = brand(); $c = e($b['color']); $org = org_lines();
        $logo = ''; if ($lf = brand_logo_file()) $logo = '<img src="data:' . e($lf[1]) . ';base64,' . base64_encode($lf[0]) . '" height="56" alt="">';
        $h = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"><title>' . e($def['title']) . '</title>'
            . '<style>body{font-family:Calibri,Arial,sans-serif;font-size:10pt}table{border-collapse:collapse;width:100%}th{background:' . $c . ';color:#fff;text-align:left;padding:4px 6px;font-size:8pt;text-transform:uppercase}td{border-bottom:1px solid #d5dae3;padding:3px 6px;font-size:9pt}td.r{text-align:right}.org td{border:0;padding:0 8px 0 0;font-size:9pt}</style></head><body>'
            . '<table class="org" style="border-bottom:3px solid ' . $c . '"><tr>' . ($logo ? '<td width="1%">' . $logo . '</td>' : '') . '<td><div style="font-size:16pt;font-weight:bold;color:' . $c . '">' . e($b['name']) . '</div>'
            . ($b['tagline'] !== '' ? '<div style="color:#666;font-style:italic">' . e($b['tagline']) . '</div>' : '');
        foreach ($org as $l) $h .= '<div style="color:#444;font-size:9pt">' . e($l) . '</div>';
        $h .= '</td><td style="text-align:right;vertical-align:top"><b style="color:' . $c . ';font-size:12pt">' . e($def['id']) . '</b><br><span style="color:#444;font-size:8pt">Generated ' . e(fmt_dt(now())) . '<br>by ' . e(user()['full_name']) . '</span></td></tr></table>'
            . '<h2 style="margin:10px 0 4px;color:' . $c . '">' . e($lines[1] ?? '') . '</h2>';
        foreach (array_slice($lines, 2) as $l) $h .= '<p style="margin:1px 0;color:#555;font-size:8pt">' . e($l) . '</p>';
        $h .= '<br><table><thead><tr>';
        foreach ($t['head'] as $c2) $h .= '<th>' . e($c2) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($t['rows'] as $r) { $h .= '<tr>'; foreach ($r as $i => $v) $h .= '<td' . (in_array($t['fmt'][$i], ['money', 'num'], true) ? ' class="r"' : '') . '>' . e(self::display($v, $t['fmt'][$i])) . '</td>'; $h .= '</tr>'; }
        if ($t['totals']) { $h .= '<tr>'; foreach ($t['totals'] as $v) $h .= '<td class="r"><b>' . e($v === null ? '' : (is_numeric($v) ? number_format((float)$v) : (string)$v)) . '</b></td>'; $h .= '</tr>'; }
        return $h . '</tbody></table></body></html>';
    }

    public static function json(array $def, array $p, array $run, array $lines): string
    {
        $t = self::table($run['rep']);
        $rows = []; foreach ($t['rows'] as $r) $rows[] = array_combine($t['keys'], $r);
        return json_encode(['report' => ['id' => $def['id'], 'title' => $def['title'], 'category' => ReportCatalog::CATEGORIES[$def['cat']][0], 'organisation' => ['name' => business_name(), 'details' => org_lines()], 'scope' => self::scopeText($p),
            'filters' => self::filterText($def, $p), 'rows_shown' => $run['shown'], 'rows_total' => $run['total'], 'generated_at' => now(), 'generated_by' => user()['full_name'], 'basis' => $run['rep']['note'] ?: $def['note']],
            'columns' => array_combine($t['keys'], $t['head']), 'rows' => $rows, 'totals' => $t['totals'] ? array_combine($t['keys'], $t['totals']) : null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
