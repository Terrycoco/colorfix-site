<?php
declare(strict_types=1);

namespace App\PHOTOS\Endpoints;

use App\PHOTOS\Services\PhotoLibraryListService;
use PDO;
use Throwable;

/** Admin HTTP adapter; Photo Library search and projection live in PHOTOS service. */
final class PhotoLibraryListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            self::send(['ok' => false, 'error' => 'GET only'], 405);
            return;
        }
        try {
            $items = (new PhotoLibraryListService($pdo))->list($_GET);
            self::send(['ok' => true, 'items' => $items]);
        } catch (Throwable $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
