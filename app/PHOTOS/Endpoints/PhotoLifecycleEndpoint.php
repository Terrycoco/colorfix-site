<?php
declare(strict_types=1);
namespace App\PHOTOS\Endpoints;
use App\PHOTOS\Services\PhotoLifecycleService;
use App\PHOTOS\Services\PhotoDuplicateService;
use PDO;
use Throwable;

final class PhotoLifecycleEndpoint
{
    public static function handle(PDO $pdo, string $operation): void
    {
        try {
            $method = $operation === 'duplicate-candidates' ? 'GET' : 'POST';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) { self::respond(['ok' => false, 'error' => $method . ' only'], 405); return; }
            $payload = [];
            if (in_array($operation, ['delete', 'merge'], true)) {
                $payload = json_decode(file_get_contents('php://input') ?: '', true);
                if (!is_array($payload)) { self::respond(['ok' => false, 'error' => 'Invalid JSON'], 400); return; }
                $keys = $operation === 'delete' ? ['photo_library_id'] : ['from_photo_library_id', 'to_photo_library_id'];
                foreach ($keys as $key) { if ((int)($payload[$key] ?? 0) <= 0) { self::respond(['ok' => false, 'error' => $key . ' required'], 400); return; } }
            }
            [$result, $status] = self::dispatch($pdo, $operation, $payload);
            self::respond($result, $status);
        } catch (\DomainException $e) { self::respond(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (\OutOfBoundsException $e) { self::respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\InvalidArgumentException $e) { self::respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('PHOTOS lifecycle: ' . $e->getMessage());
            self::respond(['ok' => false, 'error' => 'Photo operation failed; no files were removed'], 500);
        }
    }
    private static function dispatch(PDO $pdo, string $operation, array $payload): array
    {
        $service = new PhotoLifecycleService($pdo);
        switch ($operation) {
            case 'delete':
                $result = $service->delete((int)($payload['photo_library_id'] ?? 0));
                $status = (int)($result['status'] ?? 200);
                unset($result['status']);
                return [$result, $status];
            case 'delete-inactive':
                return [['ok' => true, 'result' => $service->deleteInactive()], 200];
            case 'merge':
                $apply = !empty($payload['apply']);
                return [['ok' => true, 'apply' => $apply, 'result' => $service->merge((int)($payload['from_photo_library_id'] ?? 0), (int)($payload['to_photo_library_id'] ?? 0), $apply)], 200];
            case 'duplicate-candidates':
                return [['ok' => true, 'result' => (new PhotoDuplicateService($pdo))->candidates()], 200];
        }
        throw new \InvalidArgumentException('Unknown photo operation');
    }
    private static function respond(array $result, int $status): void
    {
        http_response_code($status);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    }
}
