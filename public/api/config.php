<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

define('WHISPER_BIN',        '/usr/local/bin/whisper-cli');
define('WHISPER_MODEL',      '/app/models/ggml-model.bin');
define('OPENAI_API_KEY',     (string) getenv('OPENAI_API_KEY'));
define('COSTS_FILE',         '/app/data/costs.json');
define('ACTIVITY_LOG',       '/app/data/activity.ndjson');
define('SESSIONS_DIR',       dirname(__DIR__) . '/sessions');
define('WHISPER_TIMEOUT_S',  120);
define('OPENAI_TIMEOUT_S',   120);

const PROMPT_GUARDRAILS = 'The scene is viewed naturally through human eyes.

    This is a real environment, not an artwork, not a cinematic frame.
    No stylized illustration.
    No dramatic film composition.

    No borders, no text, no subtitles, no UI elements.
    No watermarks.

    Everything appears physically present and grounded in reality.';
