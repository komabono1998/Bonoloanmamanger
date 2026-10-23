<?php
/** JSON: existing clients matching a search, for attaching one to a new loan application. */
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!can_any('loan.create|client.view|client.manage')) { echo '[]'; exit; }
$q = trim(get('q'));
$out = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    foreach (rows("SELECT c.id, c.client_no, c.full_name, c.phone, c.status, c.kyc_status, b.name AS branch FROM clients c JOIN branches b ON b.id = c.branch_id
                   WHERE " . branch_scope('c.branch_id') . client_scope('c') . " AND (c.full_name LIKE ? ESCAPE '!' OR c.client_no LIKE ? ESCAPE '!' OR c.phone LIKE ? ESCAPE '!' OR c.phone2 LIKE ? ESCAPE '!' OR c.nin LIKE ? ESCAPE '!')
                   ORDER BY (c.status = 'Active') DESC, c.full_name LIMIT 15", [$like, $like, $like, $like, $like]) as $c) {
        $out[] = ['id' => (int)$c['id'], 'no' => $c['client_no'], 'name' => $c['full_name'], 'phone' => $c['phone'], 'status' => $c['status'], 'kyc' => $c['kyc_status'], 'branch' => $c['branch'], 'url' => url('loans', ['a' => 'new', 'client_id' => $c['id']])];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
