<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$tStart     = microtime(true);
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

$imageB64        = null;
$lastError       = 'Unknown error';
$lastHttpStatus  = 0;
$lastUsage       = null;
$lastRaw         = null;
$successAttempt  = 0;
$safetyRewritten = false;

for ($attempt = 1; $attempt <= 3; $attempt++) {
    $result = callOpenAI($finalPrompt, $size, $cfg);
    $lastRaw        = $result['raw'] ?? null;
    $lastHttpStatus = $result['http_status'];

    if ($result['ok']) {
        $imageB64       = $result['b64'];
        $lastUsage      = $result['usage'] ?? null;
        $successAttempt = $attempt;
        break;
    }

    $lastError = $result['error'];
    $isSafety       = str_contains($lastError, 'safety system');

    if ($isSafety && !$safetyRewritten) {
        // First safety hit: rephrase and retry immediately
        $rewritten = rephraseForSafety($finalPrompt);
        if ($rewritten !== null) {
            $finalPrompt     = $rewritten;
            $safetyRewritten = true;
            continue;
        }
        break; // couldn't rephrase, give up
    }

    if ($isSafety) break; // rewrite also rejected, give up

    if ($attempt < 3) sleep(3); // transient error: wait and retry
}

if ($imageB64 === null) {
    writeLog([
        'type'            => 'generation',
        'transcription'   => $scene ?: null,
        'base_prompt'     => $basePrompt,
        'final_prompt'    => $finalPrompt,
        'safety_rewrite'  => $safetyRewritten,
        'model'           => $cfg['model'],
        'quality'         => $cfg['quality'],
        'size'            => $size,
        'attempts'        => $attempt,
        'http_status'     => $lastHttpStatus,
        'image_url'       => null,
        'cost'            => null,
        'duration_s'      => round(microtime(true) - $tStart, 2),
        'error'           => $lastError,
        'api_response'    => $lastRaw,
    ]);
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

file_put_contents("$sessionDir/$imageName.txt", $finalPrompt);

if (($_POST['hidden'] ?? '0') === '1') {
    file_put_contents("$sessionDir/$imageName.hidden", '');
}

$costImage = ($lastUsage !== null)
    ? imageCostFromUsage($lastUsage, $cfg['model'], $cfg['quality'])
    : imageCost($cfg['model'], $cfg['quality'], $size);
$costs     = loadCosts();
if (($costs['session_date'] ?? '') !== $today) {
    $costs['session_cost'] = 0.0;
    $costs['session_date'] = $today;
}
$costs['session_cost']  = round(($costs['session_cost']  ?? 0) + $costImage, 4);
$costs['lifetime_cost'] = round(($costs['lifetime_cost'] ?? 0) + $costImage, 4);
saveCosts($costs);

$imageUrl = '/sessions/' . $today . '/' . $imageName . '.jpg';

writeLog([
    'type'           => 'generation',
    'transcription'  => $scene ?: null,
    'base_prompt'    => $basePrompt,
    'final_prompt'   => $finalPrompt,
    'safety_rewrite' => $safetyRewritten,
    'model'          => $cfg['model'],
    'quality'        => $cfg['quality'],
    'size'           => $size,
    'attempts'       => $successAttempt,
    'http_status'    => $lastHttpStatus,
    'image_url'      => $imageUrl,
    'cost'           => $costImage,
    'duration_s'     => round(microtime(true) - $tStart, 2),
    'error'          => null,
    'api_response'   => $lastRaw,
]);

echo json_encode([
    'image_url'     => $imageUrl,
    'prompt_used'   => $finalPrompt,
    'cost_image'    => $costImage,
    'cost_session'  => $costs['session_cost'],
    'cost_lifetime' => $costs['lifetime_cost'],
]);
exit;
