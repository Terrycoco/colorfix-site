<?php
declare(strict_types=1);

namespace App\SYSTEM\Http;

use App\SYSTEM\Services\AdminAuthService;
use PDO;
use Throwable;

final class AdminGuard
{
    public static function requireForEndpoint(PDO $pdo): void
    {
        // CLI maintenance already requires the server's OS/SSH credentials.
        if (PHP_SAPI === 'cli') return;
        // Advanced Search already uses this read-only public catalog. All its
        // mutation actions still require the same verified admin session.
        $catalog = dirname(__DIR__, 3) . '/api/v2/admin/supercats.php';
        if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath($catalog)
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && ($_GET['action'] ?? 'list') === 'list') {
            return;
        }
        self::requireAdmin($pdo);
    }

    public static function requireAdmin(PDO $pdo): array
    {
        try {
            $user = AdminAuthService::fromPdo($pdo)->currentUser(true);
            if (!$user) AdminHttp::respond(['ok' => false, 'error' => 'Unauthorized'], 401);
            AdminHttp::requireSameOrigin();
            return $user;
        } catch (Throwable $e) {
            error_log('SYSTEM admin guard: ' . $e->getMessage());
            AdminHttp::respond(['ok' => false, 'error' => 'Authentication unavailable.'], 503);
        }
    }
}
