<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// --- Config ---
define('WHISPER_BIN',        '/usr/local/bin/whisper-cli');
define('WHISPER_MODEL',      '/app/models/ggml-model.bin');
define('OPENAI_API_KEY',     (string) getenv('OPENAI_API_KEY'));
define('IMAGE_COST_USD',     0.040);   // dall-e-3 standard 1024x1024
define('COSTS_FILE',         '/app/data/costs.json');
define('SESSIONS_DIR',       __DIR__ . '/sessions');
define('WHISPER_TIMEOUT_S',  120);     // seconds before transcription is killed
define('OPENAI_TIMEOUT_S',   120);     // seconds before DALL-E request is aborted

// Hidden guardrails appended to every prompt — not shown in UI
define('PROMPT_GUARDRAILS',
    'Render this as a first-person perspective view — exactly what the hero sees through their own eyes, ' .
    'fully immersed in the scene. No frames, no borders, no vignettes, no painting edges, no decorative ' .
    'elements, no picture-on-a-wall effect. Fill the entire image edge to edge. No text overlays, no UI, ' .
    'no fourth-wall breaks. The image must feel like standing inside the world, not looking at artwork.'
);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Gallery listing (GET ?action=gallery)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'gallery') {
    $isLocal = in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]), ['localhost', '127.0.0.1']);
    $images  = [];
    if (is_dir(SESSIONS_DIR)) {
        $days = glob(SESSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [];
        rsort($days); // newest day first
        foreach ($days as $dayDir) {
            $date  = basename($dayDir);
            $files = glob($dayDir . '/image_*.png') ?: [];
            rsort($files); // newest image within day first
            foreach ($files as $imgPath) {
                $name       = pathinfo($imgPath, PATHINFO_FILENAME);
                $hiddenPath = $dayDir . '/' . $name . '.hidden';
                $isHidden   = file_exists($hiddenPath);
                if (!$isLocal && $isHidden) continue; // players don't see hidden images
                $txtPath = $dayDir . '/' . $name . '.txt';
                $images[] = [
                    'url'    => '/sessions/' . $date . '/' . basename($imgPath),
                    'prompt' => file_exists($txtPath) ? file_get_contents($txtPath) : '',
                    'date'   => $date,
                    'hidden' => $isHidden,
                ];
            }
        }
    }
    echo json_encode(['images' => $images]);
    exit;
}

// Status check (GET ?action=status)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    $costs = loadCosts();
    $raw = trim((string) getenv('HOST_IPS'));
    if ($raw === '') {
        $raw = trim((string) shell_exec('hostname -I 2>/dev/null'));
        // Filter out loopback and Docker bridge ranges (172.16–31.x.x)
        $all = $raw !== '' ? array_filter(preg_split('/[\s,]+/', $raw)) : [];
        $ips = array_values(array_filter($all, function (string $ip): bool {
            return !preg_match('/^(127\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip);
        }));
    } else {
        $ips = array_values(array_filter(explode(',', $raw)));
    }
    echo json_encode([
        'model_ready'   => file_exists(WHISPER_MODEL),
        'api_key_set'   => OPENAI_API_KEY !== '',
        'session_cost'  => $costs['session_cost']  ?? 0.0,
        'lifetime_cost' => $costs['lifetime_cost'] ?? 0.0,
        'server_ips'    => $ips,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

// ============================================================
// Action: transcribe
// Receives: audio file (multipart) + base_prompt
// Returns:  { transcription, final_prompt }
// ============================================================
if ($action === 'transcribe') {
    $basePrompt = trim($_POST['base_prompt'] ?? '');
    if ($basePrompt === '') {
        $basePrompt = 'fantasy tabletop RPG atmosphere, dramatic lighting, detailed environment art, digital painting';
    }

    $audioFile = $_FILES['audio'] ?? null;
    if (!$audioFile || $audioFile['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No audio file received']);
        exit;
    }

    // Save & convert to 16kHz mono WAV
    $tmpId   = uniqid('dnd_', true);
    $tmpDir  = sys_get_temp_dir();
    $tmpOrig = "$tmpDir/{$tmpId}.webm";
    $tmpWav  = "$tmpDir/{$tmpId}.wav";

    if (!move_uploaded_file($audioFile['tmp_name'], $tmpOrig)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save audio upload']);
        exit;
    }

    $cmd = sprintf(
        'ffmpeg -y -i %s -ar 16000 -ac 1 -f wav %s 2>/dev/null',
        escapeshellarg($tmpOrig),
        escapeshellarg($tmpWav)
    );
    exec($cmd, $out, $rc);
    @unlink($tmpOrig);

    if ($rc !== 0 || !file_exists($tmpWav)) {
        http_response_code(500);
        echo json_encode(['error' => 'Audio conversion failed (ffmpeg)']);
        exit;
    }

    // Transcribe
    $transcription = '';
    if (file_exists(WHISPER_MODEL)) {
        $whisperCmd = sprintf(
            'timeout %d %s -m %s -f %s -l cs -nt 2>/dev/null',
            WHISPER_TIMEOUT_S,
            escapeshellarg(WHISPER_BIN),
            escapeshellarg(WHISPER_MODEL),
            escapeshellarg($tmpWav)
        );
        exec($whisperCmd, $whisperOut, $whisperRc);
        if ($whisperRc === 124) {
            @unlink($tmpWav);
            http_response_code(504);
            echo json_encode(['error' => 'Transcription timed out after 2 minutes']);
            exit;
        }
        if ($whisperRc === 0) {
            $transcription = trim(implode(' ', $whisperOut));
        }
    }
    @unlink($tmpWav);

    echo json_encode([
        'transcription' => $transcription,
    ]);
    exit;
}

// ============================================================
// Action: generate
// Receives: base_prompt + scene (POST fields)
// Returns:  { image_url, prompt_used, cost_image, cost_session, cost_lifetime }
// ============================================================
if ($action === 'generate') {
    $basePrompt = trim($_POST['base_prompt'] ?? '');
    $scene      = trim($_POST['scene']       ?? '');
    $allowed    = ['1024x1024', '1792x1024', '1024x1792'];
    $size       = in_array($_POST['size'] ?? '', $allowed) ? $_POST['size'] : '1024x1024';

    if ($basePrompt === '' && $scene === '') {
        http_response_code(400);
        echo json_encode(['error' => 'base_prompt or scene is required']);
        exit;
    }

    $finalPrompt = buildPrompt($basePrompt, $scene);

    // Generate image (up to 3 retries)

    $imageB64  = null;
    $lastError = 'Unknown error';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $result = callOpenAI($finalPrompt, $size);
        if ($result['ok']) {
            $imageB64 = $result['b64'];
            break;
        }
        $lastError = $result['error'];
        if ($attempt < 3) sleep(3);
    }

    if ($imageB64 === null) {
        http_response_code(502);
        echo json_encode(['error' => "Image generation failed: $lastError"]);
        exit;
    }

    // Save image
    $today      = date('Y-m-d');
    $sessionDir = SESSIONS_DIR . '/' . $today;
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0755, true);
    }

    $imageName = nextImageName($sessionDir);
    $imagePath = "$sessionDir/$imageName.png";

    file_put_contents($imagePath, base64_decode($imageB64));

    // Also save the prompt alongside the image
    file_put_contents("$sessionDir/$imageName.txt", $finalPrompt);

    if (($_POST['hidden'] ?? '0') === '1') {
        file_put_contents("$sessionDir/$imageName.hidden", '');
    }

    // Update costs
    $costs = loadCosts();
    if (($costs['session_date'] ?? '') !== $today) {
        $costs['session_cost'] = 0.0;
        $costs['session_date'] = $today;
    }
    $costs['session_cost']  = round(($costs['session_cost']  ?? 0) + IMAGE_COST_USD, 4);
    $costs['lifetime_cost'] = round(($costs['lifetime_cost'] ?? 0) + IMAGE_COST_USD, 4);
    saveCosts($costs);

    echo json_encode([
        'image_url'     => '/sessions/' . $today . '/' . $imageName . '.png',
        'prompt_used'   => $finalPrompt,
        'cost_image'    => IMAGE_COST_USD,
        'cost_session'  => $costs['session_cost'],
        'cost_lifetime' => $costs['lifetime_cost'],
    ]);
    exit;
}

// ============================================================
// Action: upload
// Receives: image file (multipart) + optional description
// Returns:  { image_url, prompt_used }
// ============================================================
if ($action === 'upload') {
    $imageFile = $_FILES['image'] ?? null;
    if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No image file received']);
        exit;
    }

    // Validate it's actually an image
    $mime = mime_content_type($imageFile['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'])) {
        http_response_code(400);
        echo json_encode(['error' => 'File must be an image (PNG, JPEG, GIF, WEBP)']);
        exit;
    }

    $description = trim($_POST['description'] ?? '');
    $startHidden = ($_POST['hidden'] ?? '0') === '1';

    // Save to sessions dir
    $today      = date('Y-m-d');
    $sessionDir = SESSIONS_DIR . '/' . $today;
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0755, true);
    }

    $imageName = nextImageName($sessionDir);
    $imagePath = "$sessionDir/$imageName.png";

    // Convert to PNG if needed, otherwise copy to destination
    if ($mime === 'image/png') {
        if (!copy($imageFile['tmp_name'], $imagePath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save uploaded image']);
            exit;
        }
        @unlink($imageFile['tmp_name']);
    } else {
        $cmd = sprintf(
            'ffmpeg -y -i %s %s 2>/dev/null',
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
    }

    if ($description !== '') {
        file_put_contents("$sessionDir/$imageName.txt", $description);
    }
    if ($startHidden) {
        file_put_contents("$sessionDir/$imageName.hidden", '');
    }

    echo json_encode([
        'image_url'   => '/sessions/' . $today . '/' . $imageName . '.png',
        'prompt_used' => $description,
    ]);
    exit;
}

// ============================================================
// Action: toggle_hidden
// Receives: url (POST field)
// Returns:  { hidden: bool }
// ============================================================
if ($action === 'toggle_hidden') {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        http_response_code(403);
        echo json_encode(['error' => 'Only allowed from localhost']);
        exit;
    }

    $url  = trim($_POST['url'] ?? '');
    $path = realpath(__DIR__ . $url);
    $base = realpath(SESSIONS_DIR);

    if (!$path || !$base || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid path']);
        exit;
    }

    $hiddenFile = preg_replace('/\.png$/', '.hidden', $path);
    if (file_exists($hiddenFile)) {
        @unlink($hiddenFile);
        echo json_encode(['hidden' => false]);
    } else {
        file_put_contents($hiddenFile, '');
        echo json_encode(['hidden' => true]);
    }
    exit;
}

// ============================================================
// Action: delete
// Receives: url (POST field, e.g. /sessions/2026-03-16/image_01.png)
// Returns:  { ok: true }
// ============================================================
if ($action === 'delete') {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        http_response_code(403);
        echo json_encode(['error' => 'Delete is only allowed from localhost']);
        exit;
    }

    $url  = trim($_POST['url'] ?? '');
    $path = realpath(__DIR__ . $url);
    $base = realpath(SESSIONS_DIR);

    if (!$path || !$base || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid path']);
        exit;
    }

    @unlink($path);
    @unlink(preg_replace('/\.png$/', '.txt',    $path));
    @unlink(preg_replace('/\.png$/', '.hidden', $path));

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);


// ============================================================
// Helpers
// ============================================================

function nextImageName(string $sessionDir): string
{
    $existing = glob($sessionDir . '/image_*.png') ?: [];
    $max = 0;
    foreach ($existing as $f) {
        if (preg_match('/image_(\d+)\.png$/', $f, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return sprintf('image_%02d', $max + 1);
}

function buildPrompt(string $style, string $scene): string
{
    $parts = [];

    if ($style !== '') {
        $parts[] = $style;
    }

    if ($scene !== '') {
        $parts[] = "Scene to depict: {$scene}";
    }

//    $parts[] = PROMPT_GUARDRAILS;

    return implode("\n\n", $parts);
}

function callOpenAI(string $prompt, string $size = '1024x1024'): array
{
    $apiKey = OPENAI_API_KEY;
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY not set'];
    }

    $payload = json_encode([
        'model'           => 'dall-e-3',
        'prompt'          => $prompt,
        'n'               => 1,
        'size'            => $size,
        'response_format' => 'b64_json',
    ]);

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => OPENAI_TIMEOUT_S,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => "curl: $err"];
    }

    $data = json_decode($raw, true);

    if ($code !== 200 || empty($data['data'][0]['b64_json'])) {
        $msg = $data['error']['message'] ?? "HTTP $code";
        return ['ok' => false, 'error' => $msg];
    }

    return ['ok' => true, 'b64' => $data['data'][0]['b64_json']];
}

function loadCosts(): array
{
    if (file_exists(COSTS_FILE)) {
        $decoded = json_decode(file_get_contents(COSTS_FILE), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return ['lifetime_cost' => 0.0, 'session_cost' => 0.0, 'session_date' => ''];
}

function saveCosts(array $costs): void
{
    file_put_contents(COSTS_FILE, json_encode($costs, JSON_PRETTY_PRINT));
}
