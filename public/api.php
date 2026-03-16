<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// --- Config ---
define('WHISPER_BIN',   '/usr/local/bin/whisper-cli');
define('WHISPER_MODEL', '/app/models/ggml-model.bin');
define('OPENAI_API_KEY', (string) getenv('OPENAI_API_KEY'));
define('IMAGE_COST_USD', 0.040);   // dall-e-3 standard 1024x1024
define('COSTS_FILE',    '/app/data/costs.json');
define('SESSIONS_DIR',  __DIR__ . '/sessions');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Gallery listing (GET ?action=gallery)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'gallery') {
    $images = [];
    if (is_dir(SESSIONS_DIR)) {
        $days = glob(SESSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [];
        rsort($days); // newest day first
        foreach ($days as $dayDir) {
            $date  = basename($dayDir);
            $files = glob($dayDir . '/image_*.png') ?: [];
            rsort($files); // newest image within day first
            foreach ($files as $imgPath) {
                $name    = pathinfo($imgPath, PATHINFO_FILENAME);
                $txtPath = $dayDir . '/' . $name . '.txt';
                $images[] = [
                    'url'    => '/sessions/' . $date . '/' . basename($imgPath),
                    'prompt' => file_exists($txtPath) ? file_get_contents($txtPath) : '',
                    'date'   => $date,
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
    $ips = $raw !== '' ? array_values(array_filter(explode(',', $raw))) : [];
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
            '%s -m %s -f %s -l cs -nt 2>/dev/null',
            escapeshellarg(WHISPER_BIN),
            escapeshellarg(WHISPER_MODEL),
            escapeshellarg($tmpWav)
        );
        exec($whisperCmd, $whisperOut, $whisperRc);
        if ($whisperRc === 0) {
            $transcription = trim(implode(' ', $whisperOut));
        }
    }
    @unlink($tmpWav);

    $finalPrompt = $basePrompt;
    if ($transcription !== '') {
        $finalPrompt .= "\n\n" . $transcription;
    }

    echo json_encode([
        'transcription' => $transcription,
        'final_prompt'  => $finalPrompt,
    ]);
    exit;
}

// ============================================================
// Action: generate
// Receives: final_prompt (POST field)
// Returns:  { image_url, cost_image, cost_session, cost_lifetime }
// ============================================================
if ($action === 'generate') {
    $finalPrompt = trim($_POST['final_prompt'] ?? '');
    if ($finalPrompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'final_prompt is required']);
        exit;
    }

    // Generate image (up to 3 retries)
    $imageB64  = null;
    $lastError = 'Unknown error';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $result = callOpenAI($finalPrompt);
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

    $existing  = glob($sessionDir . '/image_*.png') ?: [];
    $nextNum   = count($existing) + 1;
    $imageName = sprintf('image_%02d', $nextNum);
    $imagePath = "$sessionDir/$imageName.png";

    file_put_contents($imagePath, base64_decode($imageB64));

    // Also save the prompt alongside the image
    file_put_contents("$sessionDir/$imageName.txt", $finalPrompt);

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
        'cost_image'    => IMAGE_COST_USD,
        'cost_session'  => $costs['session_cost'],
        'cost_lifetime' => $costs['lifetime_cost'],
    ]);
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
    @unlink(preg_replace('/\.png$/', '.txt', $path));

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);


// ============================================================
// Helpers
// ============================================================

function callOpenAI(string $prompt): array
{
    $apiKey = OPENAI_API_KEY;
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY not set'];
    }

    $payload = json_encode([
        'model'           => 'dall-e-3',
        'prompt'          => $prompt,
        'n'               => 1,
        'size'            => '1024x1024',
        'response_format' => 'b64_json',
    ]);

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
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
