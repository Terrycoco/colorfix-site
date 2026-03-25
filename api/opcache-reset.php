<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$ok = false;
$enabled = function_exists('opcache_reset');

if ($enabled) {
    $ok = opcache_reset();
}

echo json_encode([
    'ok' => $ok,
    'opcache_available' => $enabled,
], JSON_UNESCAPED_SLASHES);
