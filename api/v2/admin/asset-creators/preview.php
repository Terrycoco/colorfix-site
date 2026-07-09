<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../autoload.php';

use App\Services\AssetCreators\YouTubePlaylistVideoCreator;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $creatorKey = trim((string)($payload['creator_key'] ?? $payload['recipe']['creator_key'] ?? ''));
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $rootDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4)), '/');

    if ($creatorKey === 'youtube.playlist_video') {
        $service = new YouTubePlaylistVideoCreator($rootDir, $baseUrl);
        respond([
            'ok' => true,
            'preview' => $service->preview($payload),
        ]);
    }

    respond([
        'ok' => false,
        'error' => "Preview is not wired for creator: {$creatorKey}",
    ], 400);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
