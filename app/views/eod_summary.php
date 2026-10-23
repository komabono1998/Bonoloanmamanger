<?php
/** EOD transaction summary sections A-F. Expects $sum (Eod::summary) and optional $printMode. */
$print = !empty($printMode);
$row = fn(string $label, $value, bool $bold = false) => '<tr><td>' . e($label) . '</td><td class="r">' . ($bold ? '<b>' : '') . $value . ($bold ? '</b>' : '') . '</td></tr>';
$t = fn(string $title, string $inner) => '<div class="' . ($print ? '' : 'card') . '"><' . ($print ? 'h2' : 'h2') . '>' . e($title) . '</' . ($print ? 'h2' : 'h2') . '><table class="' . ($print ? '' : 'tbl nofilter') . '"' . ($print ? '' : ' style="width:100%"') . '>' . $inner . '</table></div>';
$A = $sum['applications']; $B = $sum['disbursements']; $C = $sum['collections']; $D = $sum['expenses']; $E = $sum['cash']; $F = $sum['portfolio'];
?>
<div class="<?= $print ? '' : 'grid g2' ?>">
<?= $t('A. Loan applications',
    $row('Applications received', num($A['received'])) . $row('Total amount requested', ugx($A['requested'])) . $row('Approved', num($A['approved'])) . $row('Rejected', num($A['rejected'])) . $row('Awaiting approval (now)', num($A['awaiting']))) ?>
<?= $t('B. Disbursements',
    $row('Loans disbursed', num($B['count'])) . $row('Gross principal disbursed', ugx($B['principal'])) . $row('Fees deducted', ugx($B['fees'])) . $row('Net cash released (all methods)', ugx($B['net']), true)
    . $row('— by cash', ugx($B['cash'])) . $row('— by bank', ugx($B['bank'])) . $row('— by other methods', ugx($B['other']))) ?>
<?= $t('C. Collections (posted on this business date)',
    $row('Payments received', num($C['count'])) . $row('Total collected', ugx($C['total']), true) . $row('— cash', ugx($C['cash'])) . $row('— bank', ugx($C['bank'])) . $row('— mobile money (manual)', ugx($C['mobile'])) . $row('— other', ugx($C['other']))
    . $row('Principal collected', ugx($C['principal'])) . $row('Interest collected', ugx($C['interest'])) . $row('Fees collected', ugx($C['fees'])) . $row('Penalties collected', ugx($C['penalties'])) . $row('Overpayments held as credit', ugx($C['credits']))
    . $row('Reversed on this date (count / amount)', num($C['reversed_count']) . ' / ' . ugx($C['reversed_amount']))) ?>
<?php
$cats = '';
foreach ($D['categories'] as $n => $amt) $cats .= $row('— ' . $n, ugx($amt));
echo $t('D. Expenses',
    $row('Expenses paid', num($D['count'])) . $row('Cash expenses', ugx($D['cash'])) . $row('Non-cash expenses', ugx($D['noncash'])) . $cats . $row('Pending / unpaid expenses (now)', num($D['pending'])) . $row('Authorized (approver recorded)', num($D['authorized'])));
?>
<?= $t('E. Cash movements (all tills)',
    $row('Opening cash (floats)', ugx($E['opening'])) . $row('+ Cash collections', ugx($E['collections'])) . $row('+ Transfers in', ugx($E['transfers_in'])) . $row('+ Reversal receipts', ugx($E['reversals_in']))
    . $row('− Cash disbursements', ugx($E['disbursements'])) . $row('− Cash expenses', ugx($E['expenses'])) . $row('− Transfers out', ugx($E['transfers_out'])) . $row('− Collection reversals', ugx($E['reversals_out']))
    . $row('Expected closing cash', ugx($E['expected']), true) . $row('Actual counted cash (' . $E['sessions'] . ' session(s))', ugx($E['actual']), true)
    . $row('Cash variance (actual − expected)', '<span class="' . ($E['variance'] != 0 ? 'ico-bad' : 'ico-ok') . '">' . ugx($E['variance']) . '</span>', true)) ?>
<?= $t('F. Loan portfolio changes',
    $row('Loans opened (disbursed)', num($F['opened'])) . $row('Loans closed', num($F['closed'])) . $row('Loans becoming overdue', num($F['becoming_overdue'])) . $row('Total outstanding principal', ugx($F['principal_outstanding']))
    . $row('Total contractual (P+I) outstanding', ugx($F['contractual_outstanding'])) . $row('Penalties accrued', ugx($F['penalties_accrued'])) . $row('Penalties collected', ugx($F['penalties_collected']))) ?>
</div>
