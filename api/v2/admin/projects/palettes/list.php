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
            $_SERVER[
                'REQUEST_METHOD'
            ]
            ?? 'GET'
        ) !== 'GET'
    ) {
        http_response_code(
            405
        );

        echo json_encode([
            'ok' =>
                false,

            'error' =>
                'GET only',
        ]);

        exit;
    }

    $projectId =
        (int)(
            $_GET[
                'project_id'
            ]
            ?? 0
        );

    if ($projectId <= 0) {
        http_response_code(
            400
        );

        echo json_encode([
            'ok' =>
                false,

            'error' =>
                'project_id required',
        ]);

        exit;
    }

    $repo =
        new PdoProjectPaletteRepository(
            $pdo
        );

    echo json_encode([
        'ok' =>
            true,

        'items' =>
            $repo->listForProject(
                $projectId
            ),
    ]);

} catch (
    Throwable $e
) {
    http_response_code(
        500
    );

    echo json_encode([
        'ok' =>
            false,

        'error' =>
            $e->getMessage(),
    ]);
}
