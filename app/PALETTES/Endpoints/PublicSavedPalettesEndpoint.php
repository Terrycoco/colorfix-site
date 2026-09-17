<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Services\PublicSavedPaletteService;
use PDO;
use Throwable;

final class PublicSavedPalettesEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                self::respond([
                    'ok' => false,
                    'error' => 'GET only',
                ], 405);
            }

            $filters = [];

            $brand = strtolower(trim((string)($_GET['brand'] ?? '')));
            if ($brand !== '') {
                $filters['brand'] = substr($brand, 0, 4);
            }

            $paletteType = strtolower(trim(
                (string)($_GET['palette_type'] ?? '')
            ));
            if ($paletteType !== '') {
                $filters['palette_type'] = $paletteType;
            }

            $colorFamily = trim(
                (string)($_GET['color_family'] ?? '')
            );
            if ($colorFamily !== '') {
                $filters['color_family'] = $colorFamily;
            }

            $limit = max(
                1,
                min(200, (int)($_GET['limit'] ?? 200))
            );

            $offset = max(
                0,
                (int)($_GET['offset'] ?? 0)
            );

            $service = new PublicSavedPaletteService($pdo);

            $items = $service->list(
                $filters,
                $limit,
                $offset
            );

            self::respond([
                'ok' => true,
                'items' => $items,
                'meta' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($items),
                ],
            ]);
        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private static function respond(
        array $payload,
        int $status = 200
    ): never {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}
