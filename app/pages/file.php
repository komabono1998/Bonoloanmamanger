<?php
/** Serves an uploaded attachment to signed-in users with the right to see the owning record. */
$att = row('SELECT * FROM attachments WHERE id = ?', [(int)get('id')]);
if (!$att) { http_response_code(404); throw new AppError('File not found.'); }
$need = match ($att['entity_type']) {
    'client', 'guarantor' => 'client.view',
    'loan', 'security' => 'loan.view',
    'business', 'user' => 'dashboard.view',
    default => 'loan.view',
};
if ($att['branch_id'] && !in_array((int)$att['branch_id'], accessible_branch_ids(), true)) { http_response_code(403); throw new AppError('You do not have access to records of that branch.'); }
if ($att['entity_type'] === 'client') { $cl = row('SELECT * FROM clients WHERE id = ?', [(int)$att['entity_id']]); if ($cl) assert_portfolio_client($cl); }
if ($att['entity_type'] === 'loan') { $ln = row('SELECT * FROM loans WHERE id = ?', [(int)$att['entity_id']]); if ($ln) assert_portfolio_loan($ln); }
if (!can($need)) { http_response_code(403); throw new AppError('You do not have permission to perform this action.'); }
$key = basename($att['stored_name']);
if (!Storage::exists($key)) { http_response_code(404); throw new AppError('File not found in storage.'); }
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $att['mime']);
if (($sz = Storage::size($key)) !== null) header('Content-Length: ' . $sz);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $att['original_name']) . '"');
header('Cache-Control: private, max-age=3600');
Storage::stream($key);
exit;
