<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// --- Config ---
define('WHISPER_BIN',        '/usr/local/bin/whisper-cli');
define('WHISPER_MODEL',      '/app/models/ggml-model.bin');
define('OPENAI_API_KEY',     (string) getenv('OPENAI_API_KEY'));
// IMAGE_MODEL and IMAGE_QUALITY are read from env — see imageConfig() / imageCost()
define('COSTS_FILE',         '/app/data/costs.json');
define('SESSIONS_DIR',       __DIR__ . '/sessions');
define('WHISPER_TIMEOUT_S',  120);     // seconds before transcription is killed
define('OPENAI_TIMEOUT_S',   120);     // seconds before DALL-E request is aborted

// Hidden guardrails appended to every prompt — not shown in UI
const PROMPT_GUARDRAILS = 'The scene is viewed naturally through human eyes.

    This is a real environment, not an artwork, not a cinematic frame.
    No stylized illustration.
    No dramatic film composition.
    
    No borders, no text, no subtitles, no UI elements.
    No watermarks.
    
    Everything appears physically present and grounded in reality.';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Gallery listing (GET ?action=gallery)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'gallery') {
    $isLocal = ($_GET['dm'] ?? '') === '1'
        || in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]), ['localhost', '127.0.0.1']);
    $images  = [];
    if (is_dir(SESSIONS_DIR)) {
        $days = glob(SESSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [];
        rsort($days); // newest day first
        foreach ($days as $dayDir) {
            $date  = basename($dayDir);
            $allFiles = array_merge(
                glob($dayDir . '/image_*.jpg') ?: [],
                glob($dayDir . '/image_*.png') ?: []
            );

            // Apply custom order if it exists
            $orderFile = $dayDir . '/order.json';
            if (file_exists($orderFile)) {
                $order = json_decode(file_get_contents($orderFile), true) ?: [];
                $byName = [];
                foreach ($allFiles as $f) $byName[basename($f)] = $f;
                $files = [];
                foreach ($order as $name) {
                    if (isset($byName[$name])) {
                        $files[] = $byName[$name];
                        unset($byName[$name]);
                    }
                }
                // New images not yet in order.json go at the front
                $remaining = array_values($byName);
                rsort($remaining);
                $files = array_merge($remaining, $files);
            } else {
                $files = $allFiles;
                rsort($files);
            }

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
    $cfg = imageConfig();
    echo json_encode([
        'model_ready'   => file_exists(WHISPER_MODEL),
        'api_key_set'   => OPENAI_API_KEY !== '',
        'session_cost'  => $costs['session_cost']  ?? 0.0,
        'lifetime_cost' => $costs['lifetime_cost'] ?? 0.0,
        'server_ips'    => $ips,
        'image_model'   => $cfg['model'],
        'image_quality' => $cfg['quality'],
        'image_sizes'   => $cfg['sizes'],
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
    $cfg        = imageConfig();
    $allowed    = array_column($cfg['sizes'], 'value');
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
        $result = callOpenAI($finalPrompt, $size, $cfg);
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
    $imagePath = "$sessionDir/$imageName.jpg";

    $tmpPng = tempnam(sys_get_temp_dir(), 'dnd_') . '.png';
    file_put_contents($tmpPng, base64_decode($imageB64));
    exec(sprintf('ffmpeg -y -i %s -q:v 3 %s 2>/dev/null', escapeshellarg($tmpPng), escapeshellarg($imagePath)));
    @unlink($tmpPng);

    // Also save the prompt alongside the image
    file_put_contents("$sessionDir/$imageName.txt", $finalPrompt);

    if (($_POST['hidden'] ?? '0') === '1') {
        file_put_contents("$sessionDir/$imageName.hidden", '');
    }

    // Update costs
    $costImage = imageCost($cfg['model'], $cfg['quality'], $size);
    $costs = loadCosts();
    if (($costs['session_date'] ?? '') !== $today) {
        $costs['session_cost'] = 0.0;
        $costs['session_date'] = $today;
    }
    $costs['session_cost']  = round(($costs['session_cost']  ?? 0) + $costImage, 4);
    $costs['lifetime_cost'] = round(($costs['lifetime_cost'] ?? 0) + $costImage, 4);
    saveCosts($costs);

    echo json_encode([
        'image_url'     => '/sessions/' . $today . '/' . $imageName . '.jpg',
        'prompt_used'   => $finalPrompt,
        'cost_image'    => $costImage,
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
    if (!str_starts_with($mime, 'image/')) {
        http_response_code(400);
        echo json_encode(['error' => 'File must be an image']);
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
}

// ============================================================
// Action: toggle_hidden
// Receives: url (POST field)
// Returns:  { hidden: bool }
// ============================================================
if ($action === 'toggle_hidden') {
    $url  = trim($_POST['url'] ?? '');
    $path = realpath(__DIR__ . $url);
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
}

// ============================================================
// Action: delete
// Receives: url (POST field, e.g. /sessions/2026-03-16/image_01.png)
// Returns:  { ok: true }
// ============================================================
if ($action === 'delete') {
    $url  = trim($_POST['url'] ?? '');
    $path = realpath(__DIR__ . $url);
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
}

// ============================================================
// Action: save_order
// Receives: urls (JSON array of /sessions/DATE/FILE paths)
// Returns:  { ok: true }
// ============================================================
if ($action === 'save_order') {
    $urls = json_decode($_POST['urls'] ?? '[]', true);
    if (!is_array($urls)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid urls']);
        exit;
    }

    // Group filenames by day
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
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);


// ============================================================
// Helpers
// ============================================================

function nextImageName(string $sessionDir): string
{
    $existing = array_merge(
        glob($sessionDir . '/image_*.jpg') ?: [],
        glob($sessionDir . '/image_*.png') ?: []
    );
    $max = 0;
    foreach ($existing as $f) {
        if (preg_match('/image_(\d+)\.(jpg|png)$/', $f, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return sprintf('image_%02d', $max + 1);
}

function buildPrompt(string $style, string $scene): string
{
    $parts = [];

    if ($scene !== '') {
        $parts[] = $scene;
    }

    if ($style !== '') {
        $parts[] = $style;
    }

    $parts[] = PROMPT_GUARDRAILS;

    return implode("\n\n", $parts);
}

function imageConfig(): array
{
    $model   = trim((string) getenv('IMAGE_MODEL'))   ?: 'gpt-image-1';
    $quality = trim((string) getenv('IMAGE_QUALITY')) ?: '';

    if ($model === 'dall-e-3') {
        if (!in_array($quality, ['standard', 'hd'])) $quality = 'standard';
        return [
            'model'   => 'dall-e-3',
            'quality' => $quality,
            'sizes'   => [
                ['value' => '1024x1024', 'label' => 'Square 1:1'],
                ['value' => '1792x1024', 'label' => 'Landscape 16:9'],
                ['value' => '1024x1792', 'label' => 'Portrait 9:16'],
            ],
        ];
    }

    if (!in_array($quality, ['low', 'medium', 'high', 'auto'])) $quality = 'medium';
    return [
        'model'   => 'gpt-image-1',
        'quality' => $quality,
        'sizes'   => [
            ['value' => '1024x1024', 'label' => 'Square 1:1'],
            ['value' => '1536x1024', 'label' => 'Landscape 3:2'],
            ['value' => '1024x1536', 'label' => 'Portrait 2:3'],
        ],
    ];
}

function imageCost(string $model, string $quality, string $size): float
{
    if ($model === 'dall-e-3') {
        $hd = $quality === 'hd';
        return ($size === '1024x1024') ? ($hd ? 0.080 : 0.040) : ($hd ? 0.120 : 0.080);
    }
    // gpt-image-1
    return match ($quality) {
        'low'   => 0.011,
        'high'  => 0.167,
        default => 0.042, // medium or auto
    };
}

function callOpenAI(string $prompt, string $size, array $cfg): array
{
    $apiKey = OPENAI_API_KEY;
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY not set'];
    }

    $payload = ['model' => $cfg['model'], 'prompt' => $prompt, 'n' => 1, 'size' => $size];

    if ($cfg['model'] === 'dall-e-3') {
        $payload['quality']         = $cfg['quality'];
        $payload['response_format'] = 'b64_json';
    } else {
        $payload['quality'] = $cfg['quality'];
    }

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
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
