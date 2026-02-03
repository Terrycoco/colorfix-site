<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoSavedPaletteRepository;

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Use POST']);
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }

    $photoId = isset($payload['photo_id']) ? (int) $payload['photo_id'] : 0;
    if ($photoId <= 0) {
        respond(400, ['ok' => false, 'error' => 'photo_id required']);
    }

    $repo = new PdoSavedPaletteRepository($pdo);
    $photo = $repo->getPhotoById($photoId);
    if (!$photo) {
        respond(404, ['ok' => false, 'error' => 'Photo not found']);
    }

    $rel = (string)($photo['rel_path'] ?? '');
    if ($rel !== '' && str_starts_with($rel, '/photos/')) {
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
        $abs = $docRoot . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    $repo->deletePhoto($photoId);

    respond(200, ['ok' => true]);
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
