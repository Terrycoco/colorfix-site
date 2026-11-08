<?php
declare(strict_types=1);

namespace App\ARTWORK\Endpoints;

use App\ARTWORK\Services\ArtworkService;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use Throwable;

/** Admin HTTP adapter. Caller must run the existing admin authorization. */
final class ArtworkEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== 'GET' && $method !== 'POST') {
                header('Allow: GET, POST');
                self::send(['ok' => false, 'error' => 'GET or POST only'], 405);
                return;
            }
            $data = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($data)) { throw new InvalidArgumentException('Invalid JSON request body.'); }

            $action = trim((string)($data['action'] ?? ($method === 'GET' ? 'list' : 'create')));
            $service = new ArtworkService($pdo);
            if ($method === 'GET') {
                switch ($action) {
                    case 'list':
                        $limit = self::intParam($data, 'limit', 100, 1, 500);
                        $offset = self::intParam($data, 'offset', 0, 0, PHP_INT_MAX);
                        self::send(['ok' => true, 'items' => $service->list($limit, $offset)]);
                        return;
                    case 'get':
                        if (isset($data['artwork_id']) && $data['artwork_id'] !== '') {
                            $artwork = $service->findById(self::intParam($data, 'artwork_id', null, 1, PHP_INT_MAX));
                        } else {
                            $artwork = $service->findByPhotoId(self::intParam($data, 'photo_library_id', null, 1, PHP_INT_MAX));
                        }
                        self::send(['ok' => true, 'artwork' => $artwork]);
                        return;
                    default: throw new InvalidArgumentException('Unknown artwork read action.');
                }
            }

            $photoId = self::intParam($data, 'photo_library_id', null, 1, PHP_INT_MAX);
            switch ($action) {
                case 'create':
                case 'update':
                    $fields = $data['fields'] ?? null;
                    if (!is_array($fields) || array_is_list($fields) && $fields !== []) {
                        throw new InvalidArgumentException('fields must be an object of artwork metadata.');
                    }
                    $artwork = $action === 'create'
                        ? $service->create($photoId, $fields)
                        : $service->update($photoId, $fields);
                    self::send(['ok' => true, 'artwork' => $artwork], $action === 'create' ? 201 : 200);
                    return;
                case 'delete':
                    $service->delete($photoId);
                    self::send(['ok' => true, 'deleted' => true, 'photo_library_id' => $photoId]);
                    return;
                default: throw new InvalidArgumentException('Unknown artwork write action.');
            }
        } catch (InvalidArgumentException $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (OutOfBoundsException $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (DomainException $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (\PDOException $e) {
            error_log('ARTWORK database: ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Artwork database operation failed.'], 500);
        } catch (Throwable $e) {
            error_log('ARTWORK endpoint: ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Artwork operation failed.'], 500);
        }
    }

    private static function intParam(array $data, string $key, ?int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $data)) {
            if ($default === null) { throw new InvalidArgumentException($key . ' is required.'); }
            return $default;
        }
        $value = filter_var($data[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException($key . ' must be an integer between ' . $min . ' and ' . $max . '.');
        }
        return $value;
    }

    private static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
