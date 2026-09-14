<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\SlidePresetService;
use PDO;
use Throwable;

final class SlidePresetListEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $service =
                new SlidePresetService(
                    $pdo
                );

            $editorMode =
                isset($_GET['editor'])
                && (string)$_GET['editor'] === '1';

            $presets =
                $editorMode
                    ? $service->listEditorPresets()
                    : $service->listPresets(true);

            http_response_code(200);

            echo json_encode(
                [
                    'ok' => true,
                    'presets' => $presets,
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
