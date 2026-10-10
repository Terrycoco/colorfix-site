<?php
declare(strict_types=1);

namespace App\ERR\Endpoints;

use App\ERR\Repos\PdoErrorRepository;
use PDO;
use Throwable;

/**
 * Read-only admin report endpoint.
 * The API doorbell MUST authenticate/authorize the admin and provide PDO
 * before invoking this endpoint. Do not expose this method without that gate.
 */
final class ErrorReportEndpoint
{
    public static function respond(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        try {
            $report = (new PdoErrorRepository($pdo))->report();
            echo json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $error) {
            error_log('ERR report read failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not load error report.']);
        }
    }
}
