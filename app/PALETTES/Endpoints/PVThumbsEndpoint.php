<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\PV\PVThumbService;
use PDO;
use Throwable;

final class PVThumbsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'GET required']);
            return;
        }
        $token = trim((string)($_GET['reservation_token'] ?? ''));
        if ($token === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'reservation_token required']);
            return;
        }
        try {
            echo json_encode([
                'ok' => true,
                'data' => (new PVThumbService($pdo))->forParentToken($token),
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            // Do not leak SQL / server exceptions to public visitors.
            error_log('PVThumbsEndpoint: ' . $e->getMessage());
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Thumbnail collection unavailable']);
        }
    }
}
