<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\Repos\PdoSavedPaletteRepository;

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        respond(405, ['ok' => false, 'error' => 'GET only']);
    }

    $photoLibraryId = isset($_GET['photo_library_id']) ? (int)$_GET['photo_library_id'] : 0;
    if ($photoLibraryId <= 0) {
        respond(400, ['ok' => false, 'error' => 'photo_library_id required']);
    }

    $repo = new PdoSavedPaletteRepository($pdo);
    $items = $repo->listLinksByPhotoLibraryId($photoLibraryId);
    respond(200, ['ok' => true, 'items' => $items]);
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
