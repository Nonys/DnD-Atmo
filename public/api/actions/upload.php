<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$imageFile = $_FILES['image'] ?? null;
if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image file received']);
    exit;
}

$mime = mime_content_type($imageFile['tmp_name']);
if (!str_starts_with($mime, 'image/')) {
    http_response_code(400);
    echo json_encode(['error' => 'File must be an image']);
    exit;
}

$description = trim($_POST['description'] ?? '');
$startHidden = ($_POST['hidden'] ?? '0') === '1';

$today      = date('Y-m-d');
$sessionDir = SESSIONS_DIR . '/' . $today;
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0755, true);
}

$imageName = nextImageName($sessionDir);
$imagePath = "$sessionDir/$imageName.jpg";

$cmd = sprintf(
    'ffmpeg -y -i %s -frames:v 1 -q:v 3 %s 2>/dev/null',
    escapeshellarg($imageFile['tmp_name']),
    escapeshellarg($imagePath)
);
exec($cmd, $out, $rc);
@unlink($imageFile['tmp_name']);

if ($rc !== 0 || !file_exists($imagePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Image conversion failed']);
    exit;
}

if ($description !== '') {
    file_put_contents("$sessionDir/$imageName.txt", $description);
}
if ($startHidden) {
    file_put_contents("$sessionDir/$imageName.hidden", '');
}

echo json_encode([
    'image_url'   => '/sessions/' . $today . '/' . $imageName . '.jpg',
    'prompt_used' => $description,
]);
exit;
