<?php
/** Public health probe for load balancers / uptime monitors. Reveals no records, users or configuration. */
$s = System::publicStatus();
http_response_code($s['status'] === 'down' ? 503 : 200);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($s);
