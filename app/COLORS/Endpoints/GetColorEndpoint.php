<?php
declare(strict_types=1);

namespace App\COLORS\Endpoints;

use App\COLORS\Repos\PdoColorDetailRepository;
use PDO;
use Throwable;

final class GetColorEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '1');

        try {
            $rawId = $_GET['id'] ?? null;
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $id === null) {
                self::respond(400, ['ok' => false, 'error' => 'Missing or invalid color ID']);
                return;
            }

            $color = (new PdoColorDetailRepository($pdo))->getById((int)$id);
            if ($color === null) {
                self::respond(404, ['ok' => false, 'error' => 'Color not found']);
                return;
            }
            self::respond(200, ['ok' => true, 'color' => $color]);
        } catch (Throwable $e) {
            error_log('COLORS GetColorEndpoint: ' . $e);
            self::respond(500, ['ok' => false, 'error' => 'Failed to load color detail']);
        }
    }

    private static function respond(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
