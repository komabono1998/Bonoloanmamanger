<?php
/** Printable receipt. Expects $r (Payments::receipt) and $isCopy. Works on A4 or 80mm paper. */
$small = setting('receipt_paper') === '80mm';
?>
<div class="doc<?= $small ? ' receipt80' : '' ?><?= $r['receipt_status'] === 'Void' ? ' voided' : '' ?>">
  <?php if ($isCopy): ?><div class="copy">COPY</div><?php endif; ?>
  <?= letterhead() ?>
  <h2 style="text-align:center;border:0">PAYMENT RECEIPT</h2>
  <div class="kv2">
    <div><b>Receipt No.</b> <?= e($r['receipt_no']) ?></div>
    <div><b>Date</b> <?= e(fmt_date($r['business_date'])) ?> <small>(posted <?= e(fmt_dt($r['created_at'])) ?>)</small></div>
    <div><b>Client</b> <?= e($r['client_name']) ?></div>
    <div><b>Client ID</b> <?= e($r['client_no']) ?></div>
    <div><b>Loan ID</b> <?= e($r['loan_no']) ?></div>
    <div><b>Payment method</b> <?= e($r['method']) ?></div>
    <div><b>Reference</b> <?= e($r['reference']) ?: '—' ?></div>
    <div><b>Cashier</b> <?= e($r['cashier_name']) ?></div>
  </div>
  <table>
    <tr><th>Amount received</th><td class="r"><b><?= e(ugx($r['amount'])) ?></b></td></tr>
    <tr><td colspan="2"><i><?= e(amount_in_words((int)$r['amount'])) ?></i></td></tr>
    <tr><th>Previous balance</th><td class="r"><?= e(ugx($r['prev_balance'])) ?></td></tr>
    <?php foreach ($r['allocations'] as $al): ?>
      <tr><td>&nbsp;&nbsp;allocated to <?= e($al['component'] === 'credit' ? 'customer credit (overpayment)' : $al['component']) ?></td><td class="r"><?= e(ugx($al['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Remaining balance</th><td class="r"><b><?= e(ugx($r['remaining_balance'])) ?></b></td></tr>
  </table>
  <?php if ($r['remarks']): ?><p>Remarks: <?= e($r['remarks']) ?></p><?php endif; ?>
  <div class="sig"><div>Received by (<?= e($r['cashier_name']) ?>)</div><div>Client signature</div></div>
  <p style="text-align:center;margin-top:16px;color:#444"><?= e(setting('receipt_footer')) ?></p>
</div>
