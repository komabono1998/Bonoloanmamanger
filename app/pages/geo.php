<?php
/** JSON for the linked address drop-downs: the names under the chosen district / county / sub-county / parish. */
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');
$level = get('level');
$chain = ['district' => get('district'), 'county' => get('county'), 'subcounty' => get('subcounty'), 'parish' => get('parish')];
echo json_encode(in_array($level, ['district', 'county', 'subcounty', 'parish', 'village'], true) ? Uganda::children($level, $chain) : [], JSON_UNESCAPED_UNICODE);
exit;
