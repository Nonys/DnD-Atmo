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

$hiddenFile = preg_replace('/\.(jpg|png)$/', '.hidden', $path);
if (file_exists($hiddenFile)) {
    @unlink($hiddenFile);
    echo json_encode(['hidden' => false]);
} else {
    file_put_contents($hiddenFile, '');
    echo json_encode(['hidden' => true]);
}
exit;
