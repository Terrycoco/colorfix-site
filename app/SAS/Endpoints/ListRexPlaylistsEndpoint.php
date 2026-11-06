<?php
declare(strict_types=1);

namespace App\SAS\Endpoints;

use App\SAS\Services\RexPlaylistSearchService;
use App\SYSTEM\Services\AdminAuthService;
use PDO;
use Throwable;

final class ListRexPlaylistsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            self::respond(405, ['ok' => false, 'error' => 'Use GET']);
            return;
        }
        try {
            if (!AdminAuthService::fromPdo($pdo)->rememberDevice()) {
                self::respond(401, ['ok' => false, 'error' => 'Unauthorized']);
                return;
            }
            self::respond(200, ['ok' => true, 'items' => (new RexPlaylistSearchService($pdo))->listRexPlaylists()]);
        } catch (Throwable $e) {
            error_log('SAS listRexPlaylists: ' . $e->getMessage());
            self::respond(500, ['ok' => false, 'error' => 'Could not load playlists']);
        }
    }

    private static function respond(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"error":"JSON encoding failed"}';
    }
}
