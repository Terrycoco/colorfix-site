<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\Services\RexPlaylistExperienceSyncService;
use PDO;
use Throwable;


final class PlaylistExperiencesEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        try {
            $method =
                strtoupper(
                    (string)(
                        $_SERVER[
                            'REQUEST_METHOD'
                        ]
                        ?? 'GET'
                    )
                );


            if ($method === 'GET') {
                self::handleGet(
                    $pdo
                );

                return;
            }


            if ($method === 'POST') {
                self::handlePost(
                    $pdo
                );

                return;
            }


            self::sendJson(
                405,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'GET or POST only',
                ]
            );

        } catch (Throwable $e) {
            self::sendJson(
                500,
                [
                    'ok' =>
                        false,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }


    private static function handleGet(
        PDO $pdo
    ): void {
        $playlistId =
            (int)(
                $_GET[
                    'playlist_id'
                ]
                ?? 0
            );


        if ($playlistId <= 0) {
            self::sendJson(
                422,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'Valid playlist_id required.',
                ]
            );

            return;
        }


        $service =
            new RexPlaylistExperienceSyncService(
                $pdo
            );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'item' =>
                    $service
                        ->inspectPlaylist(
                            $playlistId
                        ),
            ]
        );
    }


    private static function handlePost(
        PDO $pdo
    ): void {
        $payload =
            json_decode(
                (string)file_get_contents(
                    'php://input'
                ),
                true
            );


        if (!is_array($payload)) {
            $payload =
                [];
        }


        $playlistId =
            (int)(
                $payload[
                    'playlist_id'
                ]
                ?? 0
            );


        if ($playlistId <= 0) {
            self::sendJson(
                422,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'Valid playlist_id required.',
                ]
            );

            return;
        }


        $service =
            new RexPlaylistExperienceSyncService(
                $pdo
            );


        /*
         * FIRST PASS:
         *
         * syncPlaylist() is already the canonical reconciler.
         * It may create/reconcile more than the one missing row
         * the user clicked. The dialog refreshes immediately after.
         *
         * If the UI feels right, this can later become
         * syncExperience($playlistId, $experienceKey).
         */
        $result =
            $service
                ->syncPlaylist(
                    $playlistId
                );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'item' =>
                    $result,
            ]
        );
    }


    private static function sendJson(
        int $status,
        array $payload
    ): void {
        http_response_code(
            $status
        );

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }
}
