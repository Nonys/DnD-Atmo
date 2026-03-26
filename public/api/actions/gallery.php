<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }

$isLocal = ($_GET['dm'] ?? '') === '1'
    || in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]), ['localhost', '127.0.0.1']);

$images = [];

if (is_dir(SESSIONS_DIR)) {
    $days = glob(SESSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [];
    rsort($days);

    foreach ($days as $dayDir) {
        $date     = basename($dayDir);
        $allFiles = array_merge(
            glob($dayDir . '/image_*.jpg') ?: [],
            glob($dayDir . '/image_*.png') ?: []
        );

        $orderFile = $dayDir . '/order.json';
        if (file_exists($orderFile)) {
            $order  = json_decode(file_get_contents($orderFile), true) ?: [];
            $byName = [];
            foreach ($allFiles as $f) $byName[basename($f)] = $f;
            $files = [];
            foreach ($order as $name) {
                if (isset($byName[$name])) {
                    $files[] = $byName[$name];
                    unset($byName[$name]);
                }
            }
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
            if (!$isLocal && $isHidden) continue;
            $txtPath  = $dayDir . '/' . $name . '.txt';
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
