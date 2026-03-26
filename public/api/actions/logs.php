<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$limit = min((int) ($_GET['limit'] ?? 100), 500);

if (!file_exists(ACTIVITY_LOG)) {
    echo json_encode(['entries' => []]);
    exit;
}

$lines   = file(ACTIVITY_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$slice   = array_slice($lines, -$limit);
$slice   = array_reverse($slice);

$entries = [];
foreach ($slice as $line) {
    $decoded = json_decode($line, true);
    if ($decoded !== null) $entries[] = $decoded;
}

echo json_encode(['entries' => $entries]);
exit;
