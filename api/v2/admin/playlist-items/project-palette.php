<?php
declare(strict_types=1);

header(
    'Content-Type: application/json; charset=UTF-8'
);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\PLAYLISTS\Repos\PdoPlaylistItemPaletteRepository;
use App\PROJECTS\Repos\PdoProjectPaletteRepository;


try {
    $method =
        strtoupper(
            $_SERVER[
                'REQUEST_METHOD'
            ]
            ?? 'GET'
        );

    $playlistItemRepo =
        new PdoPlaylistItemPaletteRepository(
            $pdo
        );

    $projectPaletteRepo =
        new PdoProjectPaletteRepository(
            $pdo
        );


    if ($method === 'GET') {
        $playlistItemId =
            (int)(
                $_GET[
                    'playlist_item_id'
                ]
                ?? 0
            );

        if ($playlistItemId <= 0) {
            http_response_code(400);

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'playlist_item_id required',
            ]);

            exit;
        }

        $context =
            $playlistItemRepo->getContext(
                $playlistItemId
            );

        if (!$context) {
            http_response_code(404);

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'Playlist slide not found',
            ]);

            exit;
        }

        $projectId =
            (int)(
                $context[
                    'project_id'
                ]
                ?? 0
            );

        echo json_encode([
            'ok' =>
                true,

            'playlist_item_id' =>
                $context[
                    'playlist_item_id'
                ],

            'playlist_id' =>
                $context[
                    'playlist_id'
                ],

            'project_id' =>
                $projectId > 0
                    ? $projectId
                    : null,

            'saved_palette_id' =>
                $context[
                    'saved_palette_id'
                ],

            'palettes' =>
                $projectId > 0
                    ? $projectPaletteRepo
                        ->listForProject(
                            $projectId
                        )
                    : [],
        ]);

        exit;
    }


    if ($method === 'POST') {
        $raw =
            file_get_contents(
                'php://input'
            );

        $input =
            json_decode(
                $raw ?: '{}',
                true
            );

        if (!is_array($input)) {
            $input = [];
        }

        $playlistItemId =
            (int)(
                $input[
                    'playlist_item_id'
                ]
                ?? 0
            );

        if ($playlistItemId <= 0) {
            http_response_code(400);

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'playlist_item_id required',
            ]);

            exit;
        }

        $context =
            $playlistItemRepo->getContext(
                $playlistItemId
            );

        if (!$context) {
            http_response_code(404);

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'Playlist slide not found',
            ]);

            exit;
        }

        $projectId =
            (int)(
                $context[
                    'project_id'
                ]
                ?? 0
            );

        if ($projectId <= 0) {
            http_response_code(422);

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'This playlist is not attached to a Project',
            ]);

            exit;
        }

        $savedPaletteId =
            isset(
                $input[
                    'saved_palette_id'
                ]
            )
            &&
            $input[
                'saved_palette_id'
            ] !== null
            &&
            $input[
                'saved_palette_id'
            ] !== ''
                ? (int)$input[
                    'saved_palette_id'
                ]
                : null;

        if (
            $savedPaletteId !== null
            &&
            $savedPaletteId > 0
        ) {
            $projectPalettes =
                $projectPaletteRepo
                    ->listForProject(
                        $projectId
                    );

            $allowed =
                false;

            foreach (
                $projectPalettes
                as $palette
            ) {
                if (
                    (int)(
                        $palette[
                            'saved_palette_id'
                        ]
                        ?? 0
                    ) ===
                    $savedPaletteId
                ) {
                    $allowed =
                        true;

                    break;
                }
            }

            if (!$allowed) {
                http_response_code(422);

                echo json_encode([
                    'ok' =>
                        false,

                    'error' =>
                        'That palette is not attached to this Project',
                ]);

                exit;
            }
        } else {
            $savedPaletteId =
                null;
        }

        $playlistItemRepo
            ->setSavedPaletteId(
                $playlistItemId,
                $savedPaletteId
            );

        echo json_encode([
            'ok' =>
                true,

            'playlist_item_id' =>
                $playlistItemId,

            'saved_palette_id' =>
                $savedPaletteId,
        ]);

        exit;
    }


    http_response_code(405);

    echo json_encode([
        'ok' =>
            false,

        'error' =>
            'GET or POST only',
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' =>
            false,

        'error' =>
            $e->getMessage(),
    ]);
}
