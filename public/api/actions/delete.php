<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$url  = trim($_POST['url'] ?? '');
$path = realpath(__DIR__ . '/../../' . $url);
$base = realpath(SESSIONS_DIR);

if (!$path || !$base || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid path']);
    exit;
}

@unlink($path);
@unlink(preg_replace('/\.(jpg|png)$/', '.txt',    $path));
@unlink(preg_replace('/\.(jpg|png)$/', '.hidden', $path));

echo json_encode(['ok' => true]);
exit;
