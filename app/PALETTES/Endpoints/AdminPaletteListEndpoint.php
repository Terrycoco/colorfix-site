<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Services\AdminPaletteListService;
use PDO;
use Throwable;

final class AdminPaletteListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                self::respond([
                    'ok' => false,
                    'error' => 'GET only',
                ], 405);
            }

            $limit = max(
                1,
                min(1000, (int)($_GET['limit'] ?? 500))
            );

            $service = new AdminPaletteListService($pdo);

            $items = $service->list($limit);

            self::respond([
                'ok' => true,
                'items' => $items,
                'meta' => [
                    'count' => count($items),
                    'limit' => $limit,
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
