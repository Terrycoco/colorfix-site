<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPublishingDefaultTemplateRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestPayload(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return $_GET;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($payload) ? $payload : [];
}

function assetTypeForCreatorKey(string $creatorKey): string {
    return match ($creatorKey) {
        'youtube.playlist_video' => 'youtube_playlist_video',
        'pinterest.before_after_composite' => 'pinterest_pin',
        default => 'any',
    };
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        respond(['ok' => false, 'error' => 'GET or POST only'], 405);
    }

    $payload = requestPayload();
    $creatorKey = trim((string)($payload['creator_key'] ?? ''));
    $platform = trim((string)($payload['platform'] ?? $payload['channel'] ?? ''));
    if ($platform === '' && str_contains($creatorKey, '.')) {
        $platform = explode('.', $creatorKey, 2)[0];
    }
    $assetType = trim((string)($payload['asset_type'] ?? ''));
    if ($assetType === '') {
        $assetType = assetTypeForCreatorKey($creatorKey);
    }
    $playlistType = trim((string)($payload['playlist_type'] ?? 'any'));
    $fieldKey = trim((string)($payload['field_key'] ?? 'description'));

    $repo = new PdoPublishingDefaultTemplateRepository($pdo);

    if ($method === 'POST') {
        $templateText = (string)($payload['template_text'] ?? $payload['description'] ?? '');
        if (trim($templateText) === '') {
            respond(['ok' => false, 'error' => 'template_text required'], 400);
        }
        $item = $repo->upsert(
            $platform,
            $assetType,
            $playlistType,
            $fieldKey,
            $templateText,
            isset($payload['label']) ? (string)$payload['label'] : null
        );
        respond(['ok' => true, 'item' => $item]);
    }

    $item = $repo->findBest($platform, $assetType, $playlistType, $fieldKey);
    respond(['ok' => true, 'item' => $item]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
