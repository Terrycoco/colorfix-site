<?php
declare(strict_types=1);

namespace App\PHOTOS\Endpoints;

use App\PHOTOS\Services\PhotoEditorialService;
use InvalidArgumentException;
use PDO;
use Throwable;

/** HTTP adapter; MUST be called only by an authenticated, authorized admin API doorbell. */
final class PhotoEditorialEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== 'GET' && $method !== 'POST') {
                header('Allow: GET, POST');
                self::send(['ok' => false, 'error' => 'GET or POST only.'], 405);
                return;
            }
            $params = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($params)) {
                throw new InvalidArgumentException('Invalid request JSON.');
            }
            $id = filter_var($params['photo_library_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id <= 0) {
                throw new InvalidArgumentException('photo_library_id must be a positive integer.');
            }
            $service = new PhotoEditorialService($pdo);
            if ($method === 'GET') {
                self::send(['ok' => true, 'editorial' => $service->get($id)]);
            } else {
                self::send(['ok' => true, 'editorial' => $service->save($id, $params)]);
            }
        } catch (InvalidArgumentException $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\PDOException $e) {
            error_log('PHOTOS editorial database: ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Editorial database operation failed.'], 500);
        } catch (Throwable $e) {
            error_log('PHOTOS editorial endpoint: ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Editorial operation failed.'], 500);
        }
    }

    private static function send(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
