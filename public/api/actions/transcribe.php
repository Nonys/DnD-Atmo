<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$tStart     = microtime(true);
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

$transcription      = '';
$transcriptionError = null;

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
        writeLog([
            'type'       => 'transcription',
            'duration_s' => round(microtime(true) - $tStart, 2),
            'text'       => null,
            'error'      => 'Transcription timed out after 2 minutes',
        ]);
        http_response_code(504);
        echo json_encode(['error' => 'Transcription timed out after 2 minutes']);
        exit;
    }

    if ($whisperRc === 0) {
        $transcription = trim(implode(' ', $whisperOut));
    } else {
        $transcriptionError = "whisper exited with code $whisperRc";
    }
} else {
    $transcriptionError = 'Whisper model not found';
}

@unlink($tmpWav);

writeLog([
    'type'       => 'transcription',
    'duration_s' => round(microtime(true) - $tStart, 2),
    'text'       => $transcription !== '' ? $transcription : null,
    'error'      => $transcriptionError,
]);

echo json_encode(['transcription' => $transcription]);
exit;
