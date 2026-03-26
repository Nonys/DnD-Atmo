<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$urls = json_decode($_POST['urls'] ?? '[]', true);
if (!is_array($urls)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid urls']);
    exit;
}

$byDay = [];
foreach ($urls as $url) {
    if (preg_match('#^/sessions/(\d{4}-\d{2}-\d{2})/([^/]+)$#', $url, $m)) {
        $byDay[$m[1]][] = $m[2];
    }
}

foreach ($byDay as $date => $names) {
    $dayDir = SESSIONS_DIR . '/' . $date;
    if (is_dir($dayDir)) {
        file_put_contents($dayDir . '/order.json', json_encode($names));
    }
}

echo json_encode(['ok' => true]);
exit;
