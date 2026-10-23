<?php
/**
 * Dashboard data. Every figure is calculated from live database records, limited to the user's branches
 * (and portfolio, for loan originators without portfolio.all) and only for data the user's permissions allow.
 */
final class Dashboard
{
    /** Scope fragments for loans (alias l) and branches. */
    private static function loans(?array $only): string
    {
        return branch_scope('l.branch_id', $only) . loan_scope('l');
    }

    public static function summary(?array $only): array
    {
        $asOf = reporting_date();
        $L = self::loans($only);
        $monthStart = date('Y-m-01', strtotime($asOf));
        $prevMonthEnd = date('Y-m-d', strtotime('-1 day', strtotime($monthStart)));
        $cnt = fn(string $statuses) => (int)val("SELECT COUNT(*) FROM loans l WHERE $L AND l.status IN ($statuses)");
        $o = [];
        $o['clients'] = (int)val('SELECT COUNT(*) FROM clients WHERE ' . branch_scope('branch_id', $only) . client_scope('clients'));
        $o['clients_prev'] = (int)val('SELECT COUNT(*) FROM clients WHERE substr(registered_at,1,10) <= ? AND ' . branch_scope('branch_id', $only) . client_scope('clients'), [$prevMonthEnd]);
        $o['active'] = $cnt("'Active','Overdue'");
        $o['overdue'] = $cnt("'Overdue'");
        $o['new_loans_month'] = (int)val("SELECT COUNT(*) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE $L AND d.business_date BETWEEN ? AND ?", [$monthStart, $asOf]);
        $o['disbursed_total'] = (int)val("SELECT COALESCE(SUM(d.approved_principal),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE $L");
        $o['disbursed_month'] = (int)val("SELECT COALESCE(SUM(d.approved_principal),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE $L AND d.business_date BETWEEN ? AND ?", [$monthStart, $asOf]);
        $b = row("SELECT COALESCE(SUM(b.principal_out),0) AS pr FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE $L AND l.status IN ('Active','Overdue')");
        $o['principal_out'] = (int)$b['pr'];
        $o['overdue_balance'] = (int)val("SELECT COALESCE(SUM(b.total_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE $L AND l.status = 'Overdue'");
        $o['pending_apps'] = $cnt("'Submitted','Under Review'");
        $o['due_today'] = (int)val("SELECT COUNT(*) FROM loans l WHERE $L AND l.status IN ('Active','Overdue') AND l.due_date = ?", [$asOf]);
        if (can('payment.view')) {
            $coll = fn(string $d) => (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status = 'Posted' AND p.business_date = ? AND " . branch_scope('p.branch_id', $only), [$d]);
            $o['coll_today'] = $coll($asOf);
            $prevDay = val("SELECT MAX(business_date) FROM payments WHERE status = 'Posted' AND business_date < ? AND " . branch_scope('branch_id', $only), [$asOf]);
            $o['coll_prev_day'] = $prevDay ? $coll($prevDay) : 0;
            $o['coll_prev_day_date'] = $prevDay;
        }
        return $o;
    }

    /** Collections per bucket for the chosen granularity, with the previous equal-length period and a target. */
    public static function collectionSeries(?array $only, string $mode): array
    {
        $asOf = reporting_date();
        $mode = in_array($mode, ['d', 'w', 'm'], true) ? $mode : 'd';
        $buckets = [];   // [label, from, to, prevFrom, prevTo]
        if ($mode === 'd') {
            $ms = date('Y-m-01', strtotime($asOf)); $dim = (int)date('t', strtotime($ms));
            for ($d = 1; $d <= $dim; $d++) {
                $cur = date('Y-m-', strtotime($ms)) . sprintf('%02d', $d);
                $pm = date('Y-m-01', strtotime('-1 month', strtotime($ms)));
                $pd = date('Y-m-', strtotime($pm)) . sprintf('%02d', min($d, (int)date('t', strtotime($pm))));
                $buckets[] = [(string)$d, $cur, $cur, $pd, $pd];
            }
        } elseif ($mode === 'w') {
            $mon = strtotime('monday this week', strtotime($asOf));
            for ($i = 7; $i >= 0; $i--) {
                $s = strtotime("-$i week", $mon);
                $buckets[] = [date('j M', $s), date('Y-m-d', $s), date('Y-m-d', strtotime('+6 day', $s)), date('Y-m-d', strtotime('-8 week', $s)), date('Y-m-d', strtotime('-8 week +6 day', $s))];
            }
        } else {
            $m0 = strtotime(date('Y-m-01', strtotime($asOf)));
            for ($i = 5; $i >= 0; $i--) {
                $s = strtotime("-$i month", $m0); $pS = strtotime('-6 month', $s);
                $buckets[] = [date('M', $s), date('Y-m-d', $s), date('Y-m-t', $s), date('Y-m-d', $pS), date('Y-m-t', $pS)];
            }
        }
        $from = min(array_merge(array_column($buckets, 1), array_column($buckets, 3)));
        $to = max(array_merge(array_column($buckets, 2), array_column($buckets, 4)));
        $map = [];
        foreach (rows("SELECT p.business_date AS d, SUM(p.amount) AS s FROM payments p WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND " . branch_scope('p.branch_id', $only) . ' GROUP BY p.business_date', [$from, $to]) as $r) $map[$r['d']] = (int)$r['s'];
        $sum = function (string $a, string $b) use ($map) { $t = 0; foreach ($map as $d => $v) if ($d >= $a && $d <= $b) $t += $v; return $t; };
        $monthly = (int)setting('collection_target_monthly');
        $target = match ($mode) { 'd' => (int)round($monthly / max(1, (int)date('t', strtotime($asOf)))), 'w' => (int)round($monthly * 12 / 52), default => $monthly };
        $out = [];
        foreach ($buckets as [$label, $a, $b, $pa, $pb]) $out[] = ['label' => $label, 'cur' => $a > $asOf ? null : $sum($a, $b), 'prev' => $sum($pa, $pb), 'from' => $a, 'to' => $b];
        return ['mode' => $mode, 'rows' => $out, 'target' => $target, 'as_of' => $asOf];
    }

    /** Loan portfolio by status: number of loans and the relevant value. */
    public static function statusBreakdown(?array $only): array
    {
        $L = self::loans($only);
        $groups = ['Active' => ['n' => 0, 'v' => 0], 'Overdue' => ['n' => 0, 'v' => 0], 'Pending' => ['n' => 0, 'v' => 0], 'Closed' => ['n' => 0, 'v' => 0]];
        foreach (rows("SELECT l.status AS s, COUNT(*) AS n, COALESCE(SUM(b.principal_out),0) AS out, COALESCE(SUM(COALESCE(l.approved_principal, l.amount_requested)),0) AS req
                       FROM loans l LEFT JOIN v_loan_balances b ON b.loan_id = l.id WHERE $L GROUP BY l.status") as $r) {
            $g = match ($r['s']) { 'Active' => 'Active', 'Overdue' => 'Overdue', 'Closed' => 'Closed', 'Rejected', 'Cancelled' => null, default => 'Pending' };
            if (!$g) continue;
            $groups[$g]['n'] += (int)$r['n'];
            $groups[$g]['v'] += in_array($g, ['Active', 'Overdue'], true) ? (int)$r['out'] : ($g === 'Pending' ? (int)$r['req'] : 0);
        }
        return $groups;
    }

    /** Expected (scheduled) versus actual collections for the last six months. */
    public static function repayment(?array $only): array
    {
        $asOf = reporting_date();
        $first = date('Y-m-01', strtotime('-5 month', strtotime(date('Y-m-01', strtotime($asOf)))));
        $L = self::loans($only);
        $exp = []; $act = [];
        foreach (rows("SELECT substr(s.due_date,1,7) AS m, SUM(s.total_due) AS v FROM repayment_schedules s JOIN loans l ON l.id = s.loan_id WHERE s.status = 'Current' AND s.due_date BETWEEN ? AND ? AND $L GROUP BY substr(s.due_date,1,7)", [$first, $asOf]) as $r) $exp[$r['m']] = (int)$r['v'];
        foreach (rows("SELECT substr(p.business_date,1,7) AS m, SUM(p.amount) AS v FROM payments p JOIN loans l ON l.id = p.loan_id WHERE p.status = 'Posted' AND p.business_date BETWEEN ? AND ? AND $L GROUP BY substr(p.business_date,1,7)", [$first, $asOf]) as $r) $act[$r['m']] = (int)$r['v'];
        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $t = strtotime("-$i month", strtotime(date('Y-m-01', strtotime($asOf)))); $k = date('Y-m', $t);
            $e = $exp[$k] ?? 0; $a = $act[$k] ?? 0;
            $out[] = ['label' => date('M', $t), 'expected' => $e, 'actual' => $a, 'rate' => $e > 0 ? (int)round($a / $e * 100) : null];
        }
        return $out;
    }

    /** Arrears by branch (several branches) or by loan officer (single branch). */
    public static function arrearsBreakdown(?array $only): array
    {
        $L = self::loans($only);
        $byBranch = count(accessible_branch_ids()) > 1 && !$only;
        $col = $byBranch ? 'br.name' : 'u.full_name';
        return rows("SELECT $col AS name, COUNT(*) AS loans, COALESCE(SUM(b.principal_out),0) AS principal, COALESCE(SUM(b.total_out),0) AS total
                     FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id JOIN users u ON u.id = l.officer_id JOIN branches br ON br.id = l.branch_id
                     WHERE l.status = 'Overdue' AND $L GROUP BY $col ORDER BY total DESC LIMIT 12");
    }

    public static function arrearsGrouping(?array $only): string
    {
        return count(accessible_branch_ids()) > 1 && !$only ? 'Branch' : 'Loan officer';
    }

    /** Chronological feed: [time, icon, title, reference, user, status]. */
    public static function activities(?array $only, int $limit = 8): array
    {
        $asOf = reporting_date();
        $L = self::loans($only);
        $acts = [];
        if (can('client.view')) foreach (rows('SELECT c.registered_at AS t, c.client_no AS ref, c.full_name AS who, u.full_name AS usr FROM clients c LEFT JOIN users u ON u.id = c.registered_by WHERE ' . branch_scope('c.branch_id', $only) . client_scope('c') . ' ORDER BY c.id DESC LIMIT 5') as $r)
            $acts[] = [$r['t'], 'user-plus', 'Client registered — ' . $r['who'], $r['ref'], $r['usr'], 'Registered'];
        if (can('loan.view')) {
            foreach (rows("SELECT l.application_date AS t, l.loan_no, l.status, u.full_name AS usr FROM loans l JOIN users u ON u.id = l.officer_id WHERE $L ORDER BY l.id DESC LIMIT 5") as $r)
                $acts[] = [$r['t'], 'clipboard', in_array($r['status'], ['Draft'], true) ? 'Loan application started' : 'Loan application submitted', $r['loan_no'], $r['usr'], $r['status']];
            foreach (rows("SELECT a.decided_at AS t, a.decision, l.loan_no, l.status, u.full_name AS usr FROM loan_approvals a JOIN loans l ON l.id = a.loan_id JOIN users u ON u.id = a.approver_id WHERE $L ORDER BY a.id DESC LIMIT 5") as $r)
                $acts[] = [$r['t'], $r['decision'] === 'Approved' ? 'check' : 'clipboard', match ($r['decision']) { 'Approved' => 'Loan approved' . ($r['status'] === 'Approved' ? ' — awaiting authorized disbursement' : ''), 'Returned' => 'Application returned — corrections requested', 'Rejected' => 'Loan application rejected', default => 'Loan escalated to the COO' }, $r['loan_no'], $r['usr'], $r['status']];
        }
        if (can('payment.view')) foreach (rows("SELECT p.created_at AS t, p.amount, p.status, r.receipt_no, u.full_name AS usr FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id JOIN users u ON u.id = p.cashier_id WHERE " . branch_scope('p.branch_id', $only) . ' ORDER BY p.id DESC LIMIT 5') as $r)
            $acts[] = [$r['t'], 'card', 'Repayment received — ' . ugx($r['amount']) . ' collected', $r['receipt_no'] ?: '—', $r['usr'], $r['status']];
        if (can('portfolio.view')) foreach (rows("SELECT l.overdue_since AS t, l.loan_no, u.full_name AS usr FROM loans l JOIN users u ON u.id = l.officer_id WHERE l.status = 'Overdue' AND l.overdue_since IS NOT NULL AND $L ORDER BY l.overdue_since DESC LIMIT 4") as $r)
            $acts[] = [$r['t'], 'alert', 'Loan overdue — follow-up required', $r['loan_no'], $r['usr'], 'Overdue'];
        if (can('eod.view')) foreach (rows('SELECT e.submitted_at AS t, e.business_date, e.status, u.full_name AS usr FROM eod_records e LEFT JOIN users u ON u.id = e.submitted_by WHERE ' . branch_scope('e.branch_id', $only) . ' AND e.submitted_at IS NOT NULL ORDER BY e.id DESC LIMIT 3') as $r)
            $acts[] = [$r['t'], 'calendar', 'EOD reconciliation — ' . ($r['status'] === 'Closed' ? 'closed' : 'awaiting finance / manager action'), fmt_date($r['business_date']), $r['usr'] ?: '—', $r['status']];
        usort($acts, fn($a, $b) => strcmp((string)$b[0], (string)$a[0]));
        return array_slice($acts, 0, $limit);
    }

    /** Role-specific focus blocks, chosen from the user's permissions (not from role names). */
    public static function focus(?array $only): array
    {
        $asOf = reporting_date(); $blocks = [];
        $L = self::loans($only); $BP = branch_scope('p.branch_id', $only);
        $monthStart = date('Y-m-01', strtotime($asOf));
        if (can('till.use')) {
            $s = Cash::mySession();
            $k = [];
            if ($s) {
                $recv = row("SELECT COALESCE(SUM(amount),0) AS a, COUNT(*) AS n FROM payments WHERE session_id = ? AND status = 'Posted'", [$s['id']]);
                $rc = (int)val("SELECT COUNT(*) FROM receipts r JOIN payments p ON p.id = r.payment_id WHERE p.session_id = ? AND p.status = 'Posted'", [$s['id']]);
                $k = [['Assigned till', e($s['session_no']), 'open since ' . substr($s['opened_at'], 11, 5)], ['Opening float', ugx($s['opening_float']), ''], ['Payments received', ugx($recv['a']), $recv['n'] . ' payment(s)'], ['Receipts issued', num($rc), 'this session'], ['Expected closing cash', ugx(Cash::expected((int)$s['id'])), 'cash only', 'info']];
            } else $k = [['Assigned till', '<span class="muted">none open</span>', 'open a till to receive payments', 'warn']];
            $blocks[] = ['title' => 'My till', 'kpis' => $k, 'link' => [url('till'), 'Open till page']];
        }
        if (can('till.reconcile') || can('expense.approve')) {
            $bd = current_bd();
            $t = row("SELECT COALESCE(SUM(p.amount),0) AS a, COUNT(*) AS n FROM payments p WHERE p.status = 'Posted' AND p.business_date = ? AND $BP", [$asOf]);
            $dis = (int)val("SELECT COALESCE(SUM(d.approved_principal),0) FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE d.business_date = ? AND " . branch_scope('l.branch_id', $only), [$asOf]);
            $rc = (int)val("SELECT COUNT(*) FROM receipts r JOIN payments p ON p.id = r.payment_id WHERE p.business_date = ? AND $BP", [$asOf]);
            $sessions = rows("SELECT s.id, u.full_name, s.status FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.business_date = ? AND s.status IN ('Open','Submitted','Investigation') AND " . branch_scope('s.branch_id', $only), [$asOf]);
            $bal = 0; foreach ($sessions as $s) $bal += Cash::expected((int)$s['id']);
            $pend = (int)val("SELECT COUNT(*) FROM cashier_sessions s WHERE s.status IN ('Submitted','Investigation') AND " . branch_scope('s.branch_id', $only));
            $blocks[] = ['title' => 'Finance today', 'kpis' => [['Collections today', ugx($t['a']), $t['n'] . ' payment(s)'], ['Disbursements today', ugx($dis)], ['Cashier balances', ugx($bal), count($sessions) . ' open / unreconciled till(s)', 'info'], ['Receipts issued', num($rc), 'today'], ['Pending reconciliation', num($pend), 'till(s) awaiting review', $pend ? 'warn' : '']], 'link' => [url('eod'), 'End of Day']];
        }
        if (can('loan.approve') && !can('branch.all')) {
            $inbox = count(Approvals::inbox());
            $by = rows("SELECT u.full_name AS name, COUNT(*) AS loans, SUM(CASE WHEN l.status = 'Overdue' THEN 1 ELSE 0 END) AS overdue FROM loans l JOIN users u ON u.id = l.officer_id WHERE l.status IN ('Active','Overdue') AND $L GROUP BY u.full_name ORDER BY loans DESC LIMIT 8");
            $blocks[] = ['title' => 'Branch management', 'kpis' => [['Awaiting my decision', num($inbox), 'approvals and reviews', $inbox ? 'warn' : ''], ['Overdue loans', num((int)val("SELECT COUNT(*) FROM loans l WHERE l.status = 'Overdue' AND $L")), '', 'bad']],
                'table' => ['cols' => ['name' => 'Staff performance — loan officer', 'loans' => ['Active loans', 'num'], 'overdue' => ['Overdue', 'num']], 'rows' => $by], 'link' => [url('inbox'), 'My approvals']];
        }
        if (can('branch.all')) {
            $ov = Eod::overview();
            $closed = count(array_filter($ov, fn($r) => $r['eod_status'] === 'Closed'));
            $par = row("SELECT COALESCE(SUM(CASE WHEN l.overdue_since IS NOT NULL AND " . sql_daydiff('?', 'l.overdue_since') . " > 30 THEN b.principal_out ELSE 0 END),0) AS par, COALESCE(SUM(b.principal_out),0) AS tot FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.status IN ('Active','Overdue') AND $L", [$asOf]);
            $pct = (int)$par['tot'] > 0 ? round($par['par'] / $par['tot'] * 100, 1) : 0;
            $rows = [];
            foreach (accessible_branch_ids() as $bid) {
                $pr = (int)val("SELECT COALESCE(SUM(b.principal_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.branch_id = ? AND l.status IN ('Active','Overdue')", [$bid]);
                $cm = (int)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'Posted' AND branch_id = ? AND business_date BETWEEN ? AND ?", [$bid, $monthStart, $asOf]);
                $od = (int)val("SELECT COALESCE(SUM(b.total_out),0) FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id WHERE l.branch_id = ? AND l.status = 'Overdue'", [$bid]);
                $e = current_bd($bid) ? Eod::status(current_bd($bid), $bid) : 'No day open';
                $rows[] = ['name' => branch_name($bid), 'principal' => $pr, 'collected' => $cm, 'overdue' => $od, 'eod' => $e];
            }
            $blocks[] = ['title' => 'Institution overview', 'kpis' => [['Portfolio at risk (>30 days)', $pct . '%', 'of outstanding principal', $pct > 10 ? 'bad' : ($pct > 5 ? 'warn' : 'ok')], ['Branch EOD completion', $closed . ' of ' . count($ov), 'closed for the business date', $closed < count($ov) ? 'warn' : 'ok'], ['Outstanding principal', ugx($par['tot']), 'all branches']],
                'table' => ['cols' => ['name' => 'Branch comparison', 'principal' => ['Outstanding principal', 'money'], 'collected' => ['Collected this month', 'money'], 'overdue' => ['Overdue balance', 'money'], 'eod' => ['EOD', 'badge']], 'rows' => $rows], 'link' => [url('compliance'), 'Branch compliance']];
        }
        if (can('loan.create')) {
            $mine = fn(string $in) => (int)val("SELECT COUNT(*) FROM loans l WHERE l.officer_id = ? AND l.status IN ($in)", [uid()]);
            $dueMine = (int)val("SELECT COUNT(*) FROM loans l WHERE l.officer_id = ? AND l.status IN ('Active','Overdue') AND l.due_date = ?", [uid(), $asOf]);
            $assess = (int)val("SELECT COUNT(*) FROM loans l WHERE l.officer_id = ? AND l.status = 'Submitted' AND NOT EXISTS (SELECT 1 FROM credit_assessments a WHERE a.loan_id = l.id)", [uid()]);
            $blocks[] = ['title' => 'My assigned work', 'kpis' => [['Drafts / returned', num($mine("'Draft','Returned for Correction'")), 'not yet submitted', 'info'], ['Pending assessments', num($assess), 'submitted, not yet assessed', $assess ? 'warn' : ''], ['Awaiting decision', num($mine("'Under Review'")), 'with the approver'],
                ['Loans due today', num($dueMine), 'follow up with clients', $dueMine ? 'warn' : ''], ['Overdue follow-ups', num($mine("'Overdue'")), 'see Arrears', $mine("'Overdue'") ? 'bad' : '']], 'link' => [url('arrears'), 'Arrears & follow-up']];
        }
        return $blocks;
    }

    // ------------------------------------------------------------------ rendering helpers
    public static function trend(int $cur, int $prev, string $suffix = 'from last month'): string
    {
        if ($prev <= 0) return '<div class="trend">' . ($cur > 0 ? '<i class="up">new</i>' : 'no change yet') . '</div>';
        $pc = round(($cur - $prev) / $prev * 100);
        return '<div class="trend"><i class="' . ($pc >= 0 ? 'up' : 'down') . '">' . ($pc >= 0 ? '↑ ' : '↓ ') . abs($pc) . '%</i> ' . e($suffix) . '</div>';
    }

    private static function short(float $v): string
    {
        return $v >= 1000000 ? round($v / 1000000, 1) . 'M' : ($v >= 1000 ? round($v / 1000) . 'k' : (string)(int)$v);
    }

    /** Bars (current) + line (previous period) + dashed target. */
    public static function collectionsChart(array $series): string
    {
        $rows = $series['rows']; $n = count($rows);
        $vals = array_merge(array_filter(array_column($rows, 'cur'), fn($x) => $x !== null), array_column($rows, 'prev'), [$series['target']]);
        $max = max(1, ...$vals); $mag = 10 ** max(0, strlen((string)(int)$max) - 1); $top = (int)(ceil($max / $mag) * $mag);
        $W = 640; $H = 250; $L = 46; $B = 28; $T = 10; $cw = ($W - $L - 8) / $n; $ph = $H - $B - $T;
        $y = fn($v) => $T + $ph - $ph * $v / $top;
        $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Collections trend">';
        for ($i = 0; $i <= 4; $i++) $svg .= '<line class="gl" x1="' . $L . '" x2="' . $W . '" y1="' . $y($top * $i / 4) . '" y2="' . $y($top * $i / 4) . '"/><text x="' . ($L - 6) . '" y="' . ($y($top * $i / 4) + 4) . '" text-anchor="end">' . self::short($top * $i / 4) . '</text>';
        $pts = [];
        foreach ($rows as $i => $r) {
            $x = $L + $i * $cw;
            if ($r['cur']) $svg .= '<rect class="bar" rx="2" x="' . round($x + $cw * .18, 1) . '" y="' . round($y($r['cur']), 1) . '" width="' . round($cw * .64, 1) . '" height="' . round($T + $ph - $y($r['cur']), 1) . '"><title>' . e($r['label'] . ': ' . ugx($r['cur']) . ' (previous period ' . ugx($r['prev']) . ')') . '</title></rect>';
            $pts[] = round($x + $cw / 2, 1) . ',' . round($y($r['prev']), 1);
            if ($n <= 12 || $i === 0 || ($i + 1) % 5 === 0) $svg .= '<text x="' . round($x + $cw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($r['label']) . '</text>';
        }
        $svg .= '<polyline fill="none" stroke="var(--side)" stroke-width="2" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>';
        if ($series['target'] > 0) $svg .= '<line x1="' . $L . '" x2="' . $W . '" y1="' . $y($series['target']) . '" y2="' . $y($series['target']) . '" stroke="var(--bad)" stroke-width="1.5" stroke-dasharray="6 4"/>';
        return $svg . '</svg>';
    }

    /** Grouped bars: expected versus actual per month. */
    public static function repaymentChart(array $rows): string
    {
        $max = max(1, ...array_map(fn($r) => max($r['expected'], $r['actual']), $rows)); $mag = 10 ** max(0, strlen((string)$max) - 1); $top = (int)(ceil($max / $mag) * $mag);
        $W = 640; $H = 230; $L = 46; $B = 36; $T = 10; $n = count($rows); $cw = ($W - $L - 8) / $n; $ph = $H - $B - $T;
        $y = fn($v) => $T + $ph - $ph * $v / $top;
        $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Expected versus actual collections">';
        for ($i = 0; $i <= 4; $i++) $svg .= '<line class="gl" x1="' . $L . '" x2="' . $W . '" y1="' . $y($top * $i / 4) . '" y2="' . $y($top * $i / 4) . '"/><text x="' . ($L - 6) . '" y="' . ($y($top * $i / 4) + 4) . '" text-anchor="end">' . self::short($top * $i / 4) . '</text>';
        foreach ($rows as $i => $r) {
            $x = $L + $i * $cw;
            foreach ([['expected', .14, 'var(--side)'], ['actual', .5, 'var(--brand)']] as [$k, $off, $fill]) if ($r[$k]) $svg .= '<rect rx="2" fill="' . $fill . '" x="' . round($x + $cw * $off, 1) . '" y="' . round($y($r[$k]), 1) . '" width="' . round($cw * .34, 1) . '" height="' . round($T + $ph - $y($r[$k]), 1) . '"><title>' . e($r['label'] . ' ' . $k . ': ' . ugx($r[$k])) . '</title></rect>';
            $svg .= '<text x="' . round($x + $cw / 2, 1) . '" y="' . ($H - 18) . '" text-anchor="middle">' . e($r['label']) . '</text><text x="' . round($x + $cw / 2, 1) . '" y="' . ($H - 5) . '" text-anchor="middle">' . ($r['rate'] === null ? '–' : $r['rate'] . '%') . '</text>';
        }
        return $svg . '</svg>';
    }
}
