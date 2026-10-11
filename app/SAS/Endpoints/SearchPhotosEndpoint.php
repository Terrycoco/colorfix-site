<?php
declare(strict_types=1);

namespace App\SAS\Endpoints;

use App\SAS\Services\PhotoSearchService;
use InvalidArgumentException;
use PDO;
use Throwable;

final class SearchPhotosEndpoint
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
            $q = $_GET['q'] ?? '';
            $page = $_GET['page'] ?? '1';
            $perPage = $_GET['per_page'] ?? '30';

            if (!is_string($q) || mb_strlen($q) > 120) {
                throw new InvalidArgumentException('Invalid search query.');
            }
            if (!is_scalar($page) || filter_var($page, FILTER_VALIDATE_INT) === false || (int)$page < 1) {
                throw new InvalidArgumentException('Invalid page.');
            }
            if (!is_scalar($perPage) || filter_var($perPage, FILTER_VALIDATE_INT) === false || (int)$perPage < 1 || (int)$perPage > 60) {
                throw new InvalidArgumentException('Invalid per_page.');
            }

            $service = PhotoSearchService::fromPdo($pdo);
            $result = $service->search(trim($q), (int)$page, (int)$perPage);
            self::respond(200, ['ok' => true] + $result);
        } catch (InvalidArgumentException $e) {
            self::respond(400, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[SAS photo search] ' . $e->getMessage());
            self::respond(500, ['ok' => false, 'error' => 'Photo search failed.']);
        }
    }

    private static function respond(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
