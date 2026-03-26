<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

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
    if ($scene !== '') $parts[] = $scene;
    if ($style !== '') $parts[] = $style;
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

function imageCostFromUsage(array $usage, string $model, string $quality): float
{
    if ($model !== 'gpt-image-1') return 0.0;
    // Input text tokens: $5.00 per 1M
    $inputCost = (($usage['input_tokens'] ?? 0) * 5.0) / 1_000_000;
    // Output image: flat rate per quality level
    $outputCost = match ($quality) {
        'low'   => 0.011,
        'high'  => 0.167,
        default => 0.042,
    };
    return round($inputCost + $outputCost, 6);
}

function imageCost(string $model, string $quality, string $size): float
{
    if ($model === 'dall-e-3') {
        $hd = $quality === 'hd';
        return ($size === '1024x1024') ? ($hd ? 0.080 : 0.040) : ($hd ? 0.120 : 0.080);
    }
    return match ($quality) {
        'low'   => 0.011,
        'high'  => 0.167,
        default => 0.042,
    };
}

function rephraseForSafety(string $prompt): ?string
{
    $apiKey = OPENAI_API_KEY;
    if ($apiKey === '') return null;

    $payload = [
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            [
                'role'    => 'system',
                'content' => 'You rewrite image generation prompts for a fantasy tabletop RPG atmosphere display. '
                           . 'The prompt was rejected by a safety filter. '
                           . 'Preserve the scene and mood but rephrase using clearly fantastical, painterly, artistic language — '
                           . 'frame it as a fantasy illustration or digital painting, avoid realistic-sounding violence or gore. '
                           . 'Return ONLY the rewritten prompt, no commentary.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_tokens'  => 500,
        'temperature' => 0.7,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) return null;
    $data = json_decode($raw, true);
    return trim($data['choices'][0]['message']['content'] ?? '') ?: null;
}

function callOpenAI(string $prompt, string $size, array $cfg): array
{
    $apiKey = OPENAI_API_KEY;
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY not set', 'http_status' => 0];
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
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => "curl: $err", 'http_status' => 0];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $preview = substr(trim($raw), 0, 120);
        return ['ok' => false, 'error' => "Non-JSON response: $preview", 'http_status' => $code, 'raw' => ['body' => substr($raw, 0, 500)]];
    }

    // Strip image payload before storing raw response in logs
    $loggable = $data;
    if (isset($loggable['data']) && is_array($loggable['data'])) {
        foreach ($loggable['data'] as &$item) {
            unset($item['b64_json'], $item['url']);
        }
        unset($item);
    }

    if ($code !== 200 || empty($data['data'][0]['b64_json'])) {
        $msg = $data['error']['message'] ?? "HTTP $code";
        return ['ok' => false, 'error' => $msg, 'http_status' => $code, 'raw' => $loggable];
    }

    return [
        'ok'          => true,
        'b64'         => $data['data'][0]['b64_json'],
        'http_status' => $code,
        'usage'       => $data['usage'] ?? null,
        'raw'         => $loggable,
    ];
}

function costsFromLog(): array
{
    $today    = date('Y-m-d');
    $lifetime = 0.0;
    $session  = 0.0;

    if (!file_exists(ACTIVITY_LOG)) {
        return ['lifetime_cost' => 0.0, 'session_cost' => 0.0, 'session_date' => $today];
    }

    $fh = fopen(ACTIVITY_LOG, 'r');
    if (!$fh) {
        return ['lifetime_cost' => 0.0, 'session_cost' => 0.0, 'session_date' => $today];
    }

    while (($line = fgets($fh)) !== false) {
        $entry = json_decode(trim($line), true);
        if (!is_array($entry) || ($entry['type'] ?? '') !== 'generation') continue;
        $cost = (float) ($entry['cost'] ?? 0);
        if ($cost <= 0) continue;
        $lifetime += $cost;
        if (isset($entry['ts']) && str_starts_with($entry['ts'], $today)) {
            $session += $cost;
        }
    }
    fclose($fh);

    return [
        'lifetime_cost' => round($lifetime, 4),
        'session_cost'  => round($session,  4),
        'session_date'  => $today,
    ];
}

function loadCosts(): array
{
    return costsFromLog();
}

function saveCosts(array $costs): void
{
    // costs are now derived from the activity log — nothing to persist
}

function writeLog(array $entry): void
{
    $entry = array_merge(['ts' => date('c')], $entry);
    file_put_contents(ACTIVITY_LOG, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}
