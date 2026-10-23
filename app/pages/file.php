<?php
/** Serves an uploaded attachment to signed-in users with the right to see the owning record. */
$att = row('SELECT * FROM attachments WHERE id = ?', [(int)get('id')]);
if (!$att) { http_response_code(404); throw new AppError('File not found.'); }
$need = match ($att['entity_type']) {
    'client', 'guarantor' => 'client.view',
    'loan', 'security' => 'loan.view',
    'business' => 'dashboard.view',
    default => 'loan.view',
};
if (!can($need)) { http_response_code(403); throw new AppError('You do not have permission to perform this action.'); }
$path = BASE_PATH . '/storage/uploads/' . basename($att['stored_name']);
if (!is_file($path)) { http_response_code(404); throw new AppError('File not found on disk.'); }
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $att['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $att['original_name']) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
