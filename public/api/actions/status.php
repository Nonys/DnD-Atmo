<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$costs = loadCosts();
$raw   = trim((string) getenv('HOST_IPS'));

if ($raw === '') {
    $raw = trim((string) shell_exec('hostname -I 2>/dev/null'));
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
    'image_sizes'   => array_map(function (array $s) use ($cfg): array {
        $s['cost'] = imageCost($cfg['model'], $cfg['quality'], $s['value']);
        return $s;
    }, $cfg['sizes']),
]);
exit;
