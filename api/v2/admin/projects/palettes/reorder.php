<?php
declare(strict_types=1);

header(
    'Content-Type: application/json; charset=UTF-8'
);

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';

use App\PROJECTS\Repos\PdoProjectPaletteRepository;

try {
    if (
        (
            $_SERVER['REQUEST_METHOD']
            ?? 'GET'
        ) !== 'POST'
    ) {
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

    $projectId =
        (int)(
            $input['project_id']
            ?? 0
        );

    $savedPaletteIds =
        $input['saved_palette_ids']
        ?? [];

    if ($projectId <= 0) {
        http_response_code(400);

        echo json_encode([
            'ok' => false,
            'error' => 'project_id required',
        ]);

        exit;
    }

    if (!is_array($savedPaletteIds)) {
        http_response_code(400);

        echo json_encode([
            'ok' => false,
            'error' => 'saved_palette_ids must be an array',
        ]);

        exit;
    }

    $repo =
        new PdoProjectPaletteRepository(
            $pdo
        );

    $repo->reorder(
        $projectId,
        $savedPaletteIds
    );

    echo json_encode([
        'ok' => true,
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}