<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\REX\DTO\RexReservation;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\Services\ViewerService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException(
            'GET only.'
        );
    }

    $playlistId =
        (int)($_GET['playlist_id'] ?? 0);

    if ($playlistId <= 0) {
        throw new InvalidArgumentException(
            'Valid playlist ID required.'
        );
    }

    $rexRepo =
        new PdoRexReservationRepository(
            $pdo
        );

    $relationships =
        new RexReservationRelationships(
            $rexRepo
        );

    /*
     * Find active REX reservations
     * for this Playlist.
     */
    $byPlaylist =
        $rexRepo->findActiveByResourceIds(
            'playlist_experience',
            'playlist',
            [$playlistId]
        );

    $reservations =
        $byPlaylist[$playlistId] ?? [];

    /*
     * Pick canonical Public Playlist REX.
     */
    $playlistRex = null;

    foreach ($reservations as $reservation) {
        if (
            !$reservation
            instanceof RexReservation
        ) {
            continue;
        }

        if (
            strtolower(
                trim(
                    $reservation->status
                )
            ) !== 'active'
        ) {
            continue;
        }

        if (
            strtolower(
                trim(
                    (string)(
                        $reservation
                            ->context[
                                'experience_key'
                            ] ??
                        ''
                    )
                )
            ) !== 'public'
        ) {
            continue;
        }

        if (
            $playlistRex === null ||
            $reservation->id <
            $playlistRex->id
        ) {
            $playlistRex =
                $reservation;
        }
    }

    if (
        !$playlistRex
        instanceof RexReservation
    ) {
        echo json_encode([
            'ok' => true,
            'playlist_id' =>
                $playlistId,
            'playlist_rex' =>
                null,
            'items' => [],
        ]);

        exit;
    }

    /*
     * REX is authoritative here.
     *
     * The candidate list comes from
     * Viewer children attached to the
     * Playlist REX.
     */
    $viewerReservations =
        $relationships->children(
            $playlistRex->id,
            'viewer'
        );

    $viewerService =
        new ViewerService(
            $pdo
        );

    $items = [];

    foreach (
        $viewerReservations
        as $viewerRex
    ) {
        if (
            !$viewerRex
            instanceof RexReservation
        ) {
            continue;
        }

        if (
            strtolower(
                trim(
                    $viewerRex->status
                )
            ) !== 'active'
        ) {
            continue;
        }

        if (
            strtolower(
                trim(
                    $viewerRex
                        ->resolverKey
                )
            ) !== 'viewer'
        ) {
            continue;
        }

        /*
         * Resolve the Viewer using the
         * resource identity/context stored
         * by REX.
         *
         * We do NOT look up palette sets,
         * playlist AFTER rows, hashes, etc.
         */
        $viewer =
            $viewerService->resolve(
                $viewerRex
                    ->resourceType,

                $viewerRex
                    ->resourceId,

                $viewerRex
                    ->context
            );

        $meta =
            is_array(
                $viewer['meta'] ??
                null
            )
                ? $viewer['meta']
                : [];

        $swatches =
            is_array(
                $viewer['swatches'] ??
                null
            )
                ? $viewer['swatches']
                : [];

        $photoUrl =
            trim(
                (string)(
                    $meta[
                        'photo_url'
                    ] ??
                    ''
                )
            );

        /*
         * Idea + Palette can display
         * 1–4 colors.
         */
        $colors =
            array_slice(
                $swatches,
                0,
                4
            );

        /*
         * A Viewer without its FULL photo
         * or colors cannot make this type
         * of Pinterest asset.
         */
        if (
            $photoUrl === '' ||
            !$colors
        ) {
            continue;
        }

        $items[] = [
            'viewer_rex' => [
                'id' =>
                    $viewerRex->id,

                'token' =>
                    $viewerRex->token,

                'public_url' =>
                    '/t/' .
                    $viewerRex->token,
            ],

            'palette_viewer_id' =>
                (int)(
                    $meta[
                        'palette_viewer_id'
                    ] ??
                    (
                        $viewerRex
                            ->resourceType ===
                        'palette_viewer'
                            ? $viewerRex
                                ->resourceId
                            : 0
                    )
                ),

            'source_photo' => [
                'image_url' =>
                    $photoUrl,

                'alt_text' =>
                    $meta[
                        'photo_alt'
                    ] ??
                    null,
            ],

            'palette_colors' =>
                $colors,

            /*
             * Keep existing description
             * material if the Viewer has it.
             *
             * Search title is intentionally
             * NOT sourced from the Viewer;
             * Mark will produce that.
             */
            'description' =>
                trim(
                    (string)(
                        $meta[
                            'notes'
                        ] ??
                        $meta[
                            'intro'
                        ] ??
                        ''
                    )
                ),
        ];
    }

    echo json_encode(
        [
            'ok' => true,

            'playlist_id' =>
                $playlistId,

            'playlist_rex' => [
                'id' =>
                    $playlistRex->id,

                'token' =>
                    $playlistRex->token,

                'public_url' =>
                    '/t/' .
                    $playlistRex->token,
            ],

            'items' =>
                $items,
        ],
        JSON_UNESCAPED_SLASHES
    );

} catch (
    InvalidArgumentException $e
) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' =>
            $e->getMessage(),
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' =>
            $e->getMessage(),
    ]);
}