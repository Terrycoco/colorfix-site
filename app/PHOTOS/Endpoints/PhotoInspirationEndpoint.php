<?php
declare(strict_types=1);

namespace App\PHOTOS\Endpoints;

use App\PHOTOS\Services\PhotoInspirationService;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Admin request adapter only. PHOTOS service owns validation and mutations. */
final class PhotoInspirationEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (!in_array($method, ['GET', 'POST'], true)) {
                header('Allow: GET, POST');
                self::send(['ok' => false, 'error' => 'GET or POST only.'], 405);
                return;
            }
            $params = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($params)) { throw new InvalidArgumentException('Invalid request JSON.'); }
            $action = (string)($params['action'] ?? '');
            $service = new PhotoInspirationService($pdo);
            if ($method === 'GET') {
                if ($action === 'list') {
                    $id = self::id($params, 'artwork_photo_id');
                    self::send(['ok' => true, 'items' => $service->getInspiredDesigns($id)]);
                    return;
                }
                if ($action === 'search') {
                    $q = trim((string)($params['q'] ?? ''));
                    if (mb_strlen($q) > 120) { throw new InvalidArgumentException('Search term too long.'); }
                    self::send(['ok' => true, 'items' => $service->searchDesigns($q)]);
                    return;
                }
                throw new InvalidArgumentException('Unknown read action.');
            }
            if ($action === 'set') {
                self::send(['ok' => true, 'link' => $service->setInspiration(self::id($params, 'photo_id'), self::id($params, 'artwork_photo_id'))]);
                return;
            }
            if ($action === 'remove') {
                self::send(['ok' => true, 'link' => $service->removeInspiration(self::id($params, 'photo_id'))]);
                return;
            }
            throw new InvalidArgumentException('Unknown write action.');
        } catch (InvalidArgumentException $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\PDOException $e) {
            error_log('PHOTOS inspiration database: ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Inspiration database operation failed.'], 500);
        } catch (Throwable $e) {
            error_log('PHOTOS inspiration endpoint: ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Inspiration operation failed.'], 500);
        }
    }

    private static function id(array $params, string $key): int
    {
        $id = filter_var($params[$key] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id <= 0) {
            throw new InvalidArgumentException($key . ' must be a positive integer.');
        }
        return $id;
    }

    private static function send(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
