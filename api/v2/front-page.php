<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=900');

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$variant = strtolower(trim((string)($_GET['variant'] ?? 'public')));
if (!in_array($variant, ['public', 'admin'], true)) {
    $variant = 'public';
}

$file = dirname(__DIR__) . "/cache/front-page-{$variant}.json";
if (!is_file($file) || !is_readable($file)) {
    respond(['ok' => false, 'error' => 'Prebuilt front page not found'], 404);
}

$json = file_get_contents($file);
if ($json === false || trim($json) === '') {
    respond(['ok' => false, 'error' => 'Prebuilt front page is empty'], 500);
}

echo $json;
