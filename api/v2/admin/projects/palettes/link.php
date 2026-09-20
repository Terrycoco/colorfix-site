<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';

use App\PROJECTS\Repos\PdoProjectPaletteRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'error' => 'POST only',
        ]);
        exit;
    }

    $input = json_decode(
        file_get_contents('php://input') ?: '{}',
        true
    );

    if (!is_array($input)) {
        $input = [];
    }

    $projectId = (int)($input['project_id'] ?? 0);
    $savedPaletteId = (int)($input['saved_palette_id'] ?? 0);

    $noteRaw = $input['note'] ?? null;
    $note = is_string($noteRaw)
        ? trim($noteRaw)
        : null;

    if ($note === '') {
        $note = null;
    }

    if ($projectId <= 0 || $savedPaletteId <= 0) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'project_id and saved_palette_id are required',
        ]);
        exit;
    }

    $repo = new PdoProjectPaletteRepository($pdo);
    $projectPaletteId = $repo->link(
        $projectId,
        $savedPaletteId,
        $note
    );

    echo json_encode([
        'ok' => true,
        'project_palette_id' => $projectPaletteId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
