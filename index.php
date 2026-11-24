<?php
if (file_exists(__DIR__ . '/bot.php')) {
    require_once __DIR__ . '/bot.php';
} else {
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"ok":true}';
}
