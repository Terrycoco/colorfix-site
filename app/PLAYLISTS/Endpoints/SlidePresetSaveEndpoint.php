<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\SlidePresetService;
use InvalidArgumentException;
use PDO;
use Throwable;

final class SlidePresetSaveEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        header('Content-Type: application/json; charset=utf-8');

        if (
            strtoupper(
                (string)(
                    $_SERVER['REQUEST_METHOD']
                    ?? 'GET'
                )
            ) !== 'POST'
        ) {
            http_response_code(405);

            echo json_encode(
                [
                    'ok' => false,
                    'error' => 'POST required.',
                ],
                JSON_UNESCAPED_SLASHES
            );

            return;
        }

        try {
            $raw =
                file_get_contents(
                    'php://input'
                );

            $payload =
                [];

            if (
                is_string($raw)
                && trim($raw) !== ''
            ) {
                $decoded =
                    json_decode(
                        $raw,
                        true
                    );

                if (
                    !is_array(
                        $decoded
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Invalid JSON payload.'
                    );
                }

                $payload =
                    $decoded;
            } elseif (
                !empty($_POST)
            ) {
                $payload =
                    $_POST;
            }

            $service =
                new SlidePresetService(
                    $pdo
                );

            $preset =
                $service->savePreset(
                    $payload
                );

            http_response_code(200);

            echo json_encode(
                [
                    'ok' => true,
                    'preset' => $preset,
                ],
                JSON_UNESCAPED_SLASHES
            );
        } catch (InvalidArgumentException $e) {
            http_response_code(422);

            echo json_encode(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ],
                JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable $e) {
            http_response_code(500);

            echo json_encode(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ],
                JSON_UNESCAPED_SLASHES
            );
        }
    }
}
