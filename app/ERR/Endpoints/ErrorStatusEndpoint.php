<?php
declare(strict_types=1);
namespace App\ERR\Endpoints;

use App\ERR\Services\ErrorStatusBroadcaster;
use PDO;
use Throwable;

final class ErrorStatusEndpoint
{
    /** Called only after the normal admin auth/bootstrap in status.php. */
    public static function respond(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'GET required.']);
            return;
        }
        try {
            $feed = new ErrorStatusBroadcaster($pdo, ErrorStatusBroadcaster::tokenFromRequest());
            echo json_encode(['ok' => true, 'event' => $feed->latest()], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            error_log('ERR status endpoint failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Status temporarily unavailable.']);
        }
    }
}
