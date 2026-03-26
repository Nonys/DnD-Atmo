<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');

// Sanitize: only allow lowercase letters and underscores
$safe = preg_replace('/[^a-z_]/', '', strtolower($action));

$actionFile = __DIR__ . '/actions/' . $safe . '.php';

if ($safe === '' || !file_exists($actionFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

require $actionFile;
