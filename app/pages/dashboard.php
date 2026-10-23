<?php
$title = 'Dashboard';
Loans::refreshStatuses();
$asOf = reporting_date();
$f = [
    'from' => get('from') ?: date('Y-m-01', strtotime($asOf)),
    'to' => get('to') ?: $asOf,
    'officer' => (int)get('officer'), 'cashier' => (int)get('cashier'), 'status' => get('status'), 'method' => get('method'),
];
if (!is_date($f['from'])) $f['from'] = date('Y-m-01', strtotime($asOf));
if (!is_date($f['to'])) $f['to'] = $asOf;

// loan filters (officer / status) apply to every loan-based figure
$lw = ['1=1']; $lp = [];
if ($f['officer']) { $lw[] = 'l.officer_id = ?'; $lp[] = $f['officer']; }
if ($f['status'] !== '') { $lw[] = 'l.status = ?'; $lp[] = $f['status']; }
$LW = implode(' AND ', $lw);
$openLoans = "l.status IN ('Active','Overdue')";
$cnt = fn(string $extra, array $p = []) => (int)val("SELECT COUNT(*) FROM loans l WHERE $LW AND $extra", array_merge($lp, $p));

// payment filters (cashier / method / date range)
$pw = ["p.status = 'Posted'", 'p.business_date BETWEEN ? AND ?']; $pp = [$f['from'], $f['to']];
if ($f['cashier']) { $pw[] = 'p.cashier_id = ?'; $pp[] = $f['cashier']; }
if ($f['method'] !== '') { $pw[] = 'p.method = ?'; $pp[] = $f['method']; }
if ($f['officer']) { $pw[] = 'l.officer_id = ?'; $pp[] = $f['officer']; }
$PW = implode(' AND ', $pw);
$collected = fn(string $comp = '') => (int)val("SELECT COALESCE(SUM(" . ($comp ? "CASE WHEN a.component = '$comp' THEN a.amount END" : "CASE WHEN a.component <> 'credit' THEN a.amount END") . "),0)
    FROM payment_allocations a JOIN payments p ON p.id = a.payment_id JOIN loans l ON l.id = p.loan_id WHERE $PW", $pp);

$todayColl = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status='Posted' AND p.business_date = ?", [$asOf]);
$monthColl = (int)val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status='Posted' AND p.business_date BETWEEN ? AND ?", [date('Y-m-01', strtotime($asOf)), $asOf]);
$bal = row("SELECT COALESCE(SUM(b.principal_out),0) pr, COALESCE(SUM(b.interest_out),0) it, COALESCE(SUM(b.penalty_out),0) pn
            FROM v_loan_balances b JOIN loans l ON l.id = b.loan_id WHERE $LW AND $openLoans", $lp);
$disb = row("SELECT COALESCE(SUM(d.approved_principal),0) pr, COALESCE(SUM(d.processing_fee+d.application_fee+d.inspection_fee+d.other_deductions),0) fees
             FROM disbursements d JOIN loans l ON l.id = d.loan_id WHERE $LW AND d.business_date BETWEEN ? AND ?", array_merge($lp, [$f['from'], $f['to']]));
$feesPaid = $collected('fee');
$tillCash = (int)val("SELECT COALESCE(SUM(m.direction*m.amount),0) FROM cash_movements m JOIN cashier_sessions s ON s.id = m.session_id WHERE m.is_cash = 1 AND s.status IN ('Open','Submitted','Investigation')");
$varSum = (int)val("SELECT COALESCE(SUM(variance),0) FROM cashier_sessions WHERE business_date BETWEEN ? AND ?" . ($f['cashier'] ? ' AND cashier_id = ' . (int)$f['cashier'] : ''), [$f['from'], $f['to']]);
$bdNow = current_bd();
$sc = $bdNow ? Eod::sessionCounts($bdNow) : ['Open' => 0, 'Submitted' => 0, 'Investigation' => 0, 'Reconciled' => 0];
$lastEod = row("SELECT business_date, reviewed_at FROM eod_records WHERE status = 'Closed' ORDER BY business_date DESC, version DESC LIMIT 1");
$awaitingEod = (int)val("SELECT COUNT(*) FROM eod_records e WHERE e.status = 'Awaiting Manager Review' AND e.version = (SELECT MAX(version) FROM eod_records WHERE business_date = e.business_date)");

page_head('Dashboard', '', 'Overview as at ' . fmt_date($asOf) . ' (business date). Accrued amounts are shown separately from cash actually received.');
$officers = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Loan Officer','Manager','Administrator') ORDER BY full_name") as $u) $officers[$u['id']] = $u['full_name'];
$cashiers = []; foreach (rows("SELECT id, full_name FROM users WHERE role IN ('Cashier','Manager','Accountant','Administrator') ORDER BY full_name") as $u) $cashiers[$u['id']] = $u['full_name'];
filter_bar([
    'from' => ['label' => 'From', 'type' => 'date'], 'to' => ['label' => 'To', 'type' => 'date'],
    'officer' => ['label' => 'Loan officer', 'type' => 'select', 'options' => $officers],
    'cashier' => ['label' => 'Cashier', 'type' => 'select', 'options' => $cashiers],
    'status' => ['label' => 'Loan status', 'type' => 'select', 'options' => array_combine(['Draft', 'Submitted', 'Under Review', 'Approved', 'Active', 'Overdue', 'Closed', 'Rejected'], ['Draft', 'Submitted', 'Under Review', 'Approved', 'Active', 'Overdue', 'Closed', 'Rejected'])],
    'method' => ['label' => 'Payment method', 'type' => 'select', 'options' => array_combine(Payments::METHODS, Payments::METHODS)],
], $f, 'dashboard');

$lastBk = Backup::lastSuccess();
if (can('backup.manage') && (!$lastBk || strtotime($lastBk['at']) < time() - 3 * 86400)) {
    echo '<div class="notice bad">No verified backup in the last 3 days. <a href="' . e(url('backup')) . '">Back up now →</a></div>';
}
$failedEod = (int)val("SELECT COUNT(*) FROM eod_records WHERE backup_status LIKE 'FAILED%' AND business_date = (SELECT MAX(business_date) FROM eod_records)");
if ($failedEod && can('backup.manage')) echo '<div class="notice bad">The backup taken at the last End-of-Day <b>failed</b>. <a href="' . e(url('backup')) . '">Review backups →</a></div>';
?>
<div class="section-t">Clients</div>
<div class="kpis">
  <?= kpi('Registered clients', num(val('SELECT COUNT(*) FROM clients'))) ?>
  <?= kpi('New today', num(val("SELECT COUNT(*) FROM clients WHERE date(registered_at) = ?", [today()])), '', 'info') ?>
  <?= kpi('New this month', num(val("SELECT COUNT(*) FROM clients WHERE strftime('%Y-%m', registered_at) = ?", [date('Y-m')])), '', 'info') ?>
  <?= kpi('Active borrowers', num(val("SELECT COUNT(DISTINCT client_id) FROM loans l WHERE $LW AND $openLoans", $lp))) ?>
  <?= kpi('Clients with overdue loans', num(val("SELECT COUNT(DISTINCT client_id) FROM loans l WHERE $LW AND l.status = 'Overdue'", $lp)), '', 'bad') ?>
</div>
<div class="section-t">Loans</div>
<div class="kpis">
  <?= kpi('Applications awaiting review', num($cnt("l.status IN ('Draft','Submitted','Returned for Correction')")), 'Draft, submitted or returned', 'warn') ?>
  <?= kpi('Loans awaiting approval', num($cnt("l.status = 'Under Review'")), 'Assessed, with the manager', 'warn') ?>
  <?= kpi('Approved, awaiting disbursement', num($cnt("l.status = 'Approved'")), '', 'info') ?>
  <?= kpi('Active loans', num($cnt($openLoans))) ?>
  <?= kpi('Due today', num($cnt("$openLoans AND l.due_date = ?", [$asOf])), '', 'warn') ?>
  <?= kpi('Due this week', num($cnt("$openLoans AND l.due_date BETWEEN ? AND ?", [$asOf, Calc::addDays($asOf, 7)]))) ?>
  <?= kpi('Overdue loans', num($cnt("l.status = 'Overdue'")), '', 'bad') ?>
  <?= kpi('Fully settled', num($cnt("l.status = 'Closed'")), '', 'muted') ?>
</div>
<div class="section-t">Financial position <span class="muted" style="text-transform:none;letter-spacing:0">· cash flows for <?= e(fmt_date($f['from'])) ?> – <?= e(fmt_date($f['to'])) ?></span></div>
<div class="kpis">
  <?= kpi('Principal disbursed', ugx($disb['pr']), 'gross, in period') ?>
  <?= kpi('Outstanding principal', ugx($bal['pr']), 'active portfolio') ?>
  <?= kpi('Interest receivable', ugx($bal['it']), 'accrued, NOT yet received', 'warn') ?>
  <?= kpi('Interest collected', ugx($collected('interest')), 'cash received in period', 'ok') ?>
  <?= kpi('Collections today', ugx($todayColl), 'posted ' . fmt_date($asOf)) ?>
  <?= kpi('Collections this month', ugx($monthColl)) ?>
  <?= kpi('Fees collected', ugx($disb['fees'] + $feesPaid), 'deducted at disbursement + paid') ?>
  <?= kpi('Penalties collected', ugx($collected('penalty')), 'accrued outstanding: ' . ugx($bal['pn'])) ?>
  <?= kpi('Cash in tills now', ugx($tillCash), 'open / unreconciled sessions', 'info') ?>
  <?= kpi('Cashier variances', ugx($varSum), 'in period', $varSum != 0 ? 'bad' : '') ?>
</div>
<div class="section-t">End of Day</div>
<div class="kpis">
  <?= kpi('Business date', $bdNow ? e(fmt_date($bdNow)) : '<span class="muted">none open</span>') ?>
  <?= kpi('EOD status', $bdNow ? badge(Eod::status($bdNow)) : badge('Not Started')) ?>
  <?= kpi('Cashiers with open tills', num($sc['Open']), '', $sc['Open'] ? 'warn' : '') ?>
  <?= kpi('Awaiting reconciliation', num($sc['Submitted'] + $sc['Investigation']), '', ($sc['Submitted'] + $sc['Investigation']) ? 'warn' : '') ?>
  <?= kpi('Cashiers reconciled', num($sc['Reconciled']), '', 'ok') ?>
  <?= kpi('EOD awaiting approval', num($awaitingEod), '', $awaitingEod ? 'warn' : '') ?>
  <?= kpi('Last completed EOD', $lastEod ? e(fmt_date($lastEod['business_date'])) : '—') ?>
</div>

<div class="grid g2">
  <div class="card">
    <h2>Collections — last 14 business dates</h2>
    <?php
    $series = array_reverse(rows("SELECT business_date d, SUM(amount) s FROM payments WHERE status = 'Posted' GROUP BY business_date ORDER BY business_date DESC LIMIT 14"));
    $max = max(1, ...array_map(fn($r) => (int)$r['s'], $series ?: [['s' => 1]]));
    if (!$series) echo '<p class="muted">No collections recorded yet.</p>';
    else {
        echo '<div class="bars">';
        foreach ($series as $r) echo '<div class="bar" style="height:' . max(2, round($r['s'] / $max * 100)) . '%" title="' . e(fmt_date($r['d']) . ': ' . ugx($r['s'])) . '"><span>' . e(number_format($r['s'] / 1000)) . 'k</span></div>';
        echo '</div><div class="bar-lbl">';
        foreach ($series as $r) echo '<span>' . e(date('d/m', strtotime($r['d']))) . '</span>';
        echo '</div>';
    }
    ?>
  </div>
  <div class="card">
    <h2>Overdue ageing (outstanding principal)</h2>
    <?php
    $buckets = ['1–7 days' => [1, 7], '8–30 days' => [8, 30], '31–60 days' => [31, 60], '61–90 days' => [61, 90], 'Over 90 days' => [91, 99999]];
    $out = [];
    foreach ($buckets as $label => [$lo, $hi]) {
        $r = row("SELECT COUNT(*) n, COALESCE(SUM(b.principal_out),0) pr, COALESCE(SUM(b.total_out),0) tot FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id
                  WHERE l.status = 'Overdue' AND l.overdue_since IS NOT NULL AND (julianday(?) - julianday(l.overdue_since)) BETWEEN ? AND ?", [$asOf, $lo, $hi]);
        $out[] = ['bucket' => $label, 'n' => $r['n'], 'pr' => $r['pr'], 'tot' => $r['tot']];
    }
    data_table(['bucket' => 'Days overdue', 'n' => ['Loans', 'num'], 'pr' => ['Principal', 'money'], 'tot' => ['Total owed', 'money']], $out, ['class' => 'nofilter', 'totals' => ['n', 'pr', 'tot']]);
    ?>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Loans due soon or overdue</h2><a class="btn sm" href="<?= e(url('arrears')) ?>">Open arrears →</a></div>
  <?php
  $due = rows("SELECT l.id, l.loan_no, c.full_name, c.phone, l.due_date, l.status, b.total_out FROM loans l JOIN clients c ON c.id = l.client_id JOIN v_loan_balances b ON b.loan_id = l.id
               WHERE $LW AND l.status IN ('Active','Overdue') AND l.due_date <= ? ORDER BY l.due_date LIMIT 12", array_merge($lp, [Calc::addDays($asOf, 7)]));
  data_table([
      'loan_no' => 'Loan', 'full_name' => 'Client', 'phone' => 'Phone', 'due_date' => ['Due', 'date'], 'status' => ['Status', 'badge'], 'total_out' => ['Outstanding', 'money'],
  ], $due, ['link' => fn($r) => url('loan', ['id' => $r['id']]), 'empty' => 'No loans due in the next 7 days.', 'class' => 'nofilter']);
  ?>
</div>
