<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexResourceOptionsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'GET only']);
            return;
        }

        $type = strtolower(trim((string)($_GET['resource_type'] ?? '')));
        try {
            $repo = new PdoRexReservationRepository($pdo);
            $items = $repo->listOptions($type);
            echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            error_log('REX resource options: ' . (string)$e);
            // Admin-authenticated API: show precise failure to aid console diagnostics.
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], JSON_UNESCAPED_SLASHES);
        }
    }
}
