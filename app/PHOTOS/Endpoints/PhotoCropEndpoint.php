<?php
declare(strict_types=1);

namespace App\PHOTOS\Endpoints;

use App\PHOTOS\Repos\PdoPhotoCropRepository;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PHOTOS\Services\PhotoCropService;
use App\PHOTOS\Services\PhotoReplacementService;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Admin-only HTTP adapter for PHOTOS crop operations.
 *
 * Caller/doorbell MUST enforce the existing Photo Library admin authentication
 * and permissions BEFORE invoking handle(). Do not expose this unguarded.
 *
 * GET  ?action=presets
 * GET  ?action=get&photo_library_id=123&crop_type=swatch
 * POST {"action":"save","photo_library_id":123,"crop_type":"swatch",
 *       "crop_x":0.1,"crop_y":0,"crop_width":0.8,"crop_height":1}
 * POST {"action":"reset","photo_library_id":123,"crop_type":"swatch"}
 *
 * Original image dimensions are measured on the server, never supplied by a caller.
 */
final class PhotoCropEndpoint
{
    public static function handle(PDO $pdo, string $documentRoot): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (!in_array($method, ['GET', 'POST'], true)) {
                header('Allow: GET, POST');
                self::respond(['ok' => false, 'error' => 'GET or POST required'], 405);
                return;
            }

            $data = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($data)) {
                throw new InvalidArgumentException('Invalid JSON body.');
            }
            $action = trim((string)($data['action'] ?? ($method === 'GET' ? 'get' : 'save')));
            $service = new PhotoCropService(new PdoPhotoCropRepository($pdo));

            if ($method === 'GET' && $action === 'presets') {
                self::respond(['ok' => true, 'default_type' => \App\PHOTOS\Config\PhotoCropPresets::DEFAULT_TYPE,
                    'presets' => $service->presets()]);
                return;
            }
            if ($method === 'GET' && $action !== 'get'
                || $method === 'POST' && !in_array($action, ['save', 'reset'], true)) {
                throw new InvalidArgumentException('Unsupported crop action.');
            }

            $id = filter_var($data['photo_library_id'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            if (!$id) {
                throw new InvalidArgumentException('Valid photo_library_id is required.');
            }
            $type = trim((string)($data['crop_type'] ?? \App\PHOTOS\Config\PhotoCropPresets::DEFAULT_TYPE));
            $service->preset($type);

            $photo = (new PdoPhotoLibraryRepository($pdo))->findById((int)$id);
            if (!$photo) {
                self::respond(['ok' => false, 'error' => 'Photo not found'], 404);
                return;
            }

            if ($method === 'POST' && $action === 'reset') {
                $service->resetCrop((int)$id, $type);
                \App\PHOTOS\Services\PhotoThumbnailService::invalidate((int)$id, $documentRoot);
                // No crop row => caller uses centered default, without a DB insert.
                self::respond(['ok' => true, 'photo_library_id' => (int)$id,
                    'crop_type' => $type, 'is_custom' => false, 'reset' => true]);
                return;
            }

            $path = (new PhotoReplacementService($pdo, $documentRoot))->localPath((string)($photo['rel_path'] ?? ''));
            if ($path === null) {
                self::respond(['ok' => false, 'error' => 'Original image unavailable for crop calculation'], 409);
                return;
            }
            $dimensions = @getimagesize($path);
            if (!is_array($dimensions) || ($dimensions[0] ?? 0) <= 0 || ($dimensions[1] ?? 0) <= 0) {
                self::respond(['ok' => false, 'error' => 'Could not read original image dimensions'], 409);
                return;
            }
            $width = (int)$dimensions[0];
            $height = (int)$dimensions[1];

            if ($action === 'save') {
                $crop = [];
                foreach (['crop_x', 'crop_y', 'crop_width', 'crop_height'] as $key) {
                    $crop[$key] = $data[$key] ?? null;
                }
                $result = $service->saveCrop((int)$id, $type, $crop, $width, $height);
                \App\PHOTOS\Services\PhotoThumbnailService::invalidate((int)$id, $documentRoot);
            } else {
                $result = $service->getCrop((int)$id, $width, $height, $type);
            }

            self::respond(['ok' => true, 'crop' => $result, 'image_width' => $width,
                'image_height' => $height]);
        } catch (InvalidArgumentException $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('PHOTOS crop endpoint: ' . $e->getMessage());
            self::respond(['ok' => false, 'error' => 'Photo crop request failed'], 500);
        }
    }

    private static function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
