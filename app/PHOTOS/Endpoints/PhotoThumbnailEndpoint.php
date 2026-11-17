<?php
declare(strict_types=1);
namespace App\PHOTOS\Endpoints;

use App\PHOTOS\Services\PhotoThumbnailService;
use App\PHOTOS\Services\PhotoDeliveryService;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\SYSTEM\Services\AdminAuthService;
use App\SYSTEM\Services\AdminSession;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use Throwable;

final class PhotoThumbnailEndpoint
{
    public static function handle(PDO $pdo, string $root): void
    {
        try {
            if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
                http_response_code(405); header('Allow: GET, HEAD'); return;
            }
            $id=(int)($_GET['id'] ?? 0);
            if ($id<=0) throw new InvalidArgumentException('Missing id');
            $photo=(new PdoPhotoLibraryRepository($pdo))->findThumbnailSource($id);
            $admin=false;
            if (!empty($_COOKIE[AdminSession::COOKIE])) $admin=AdminAuthService::fromPdo($pdo)->currentUser(true)!==null;
            // Check before opening the cache, including for conditional requests.
            if (!$photo || !PhotoDeliveryService::authorized($photo,$_GET,$admin)) throw new OutOfBoundsException('Not found');
            $result=(new PhotoThumbnailService($pdo))->get($id,(int)($_GET['w'] ?? 480),(int)($_GET['q'] ?? 88),
                $root,(string)($_GET['crop_type'] ?? 'swatch'),(string)($_GET['fit'] ?? 'width'));
            if (!empty($result['requires_crop'])) throw new \RuntimeException('Crop conversion unavailable');
            $etag='"' . hash_file('sha256',$result['path']) . '"';
            // Stable URLs + validation give immediate source/crop/permission changes and body-free warm loads.
            header('Cache-Control: private, no-cache, must-revalidate');
            header('Vary: Cookie');
            header('ETag: ' . $etag);
            header('X-Content-Type-Options: nosniff');
            header('X-PHOTOS-Optimized: ' . ($result['optimized'] ? '1' : '0'));
            if (in_array($etag,array_map('trim',explode(',',(string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))),true)) {
                http_response_code(304); return;
            }
            header('Content-Type: ' . $result['mime']);
            header('Content-Length: ' . filesize($result['path']));
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($result['path']);
        } catch (Throwable $e) {
            http_response_code($e instanceof OutOfBoundsException ? 404 : ($e instanceof InvalidArgumentException ? 400 : 503));
            header('Cache-Control: no-store'); header('Content-Type: text/plain; charset=utf-8');
            echo $e instanceof OutOfBoundsException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Thumbnail unavailable';
        }
    }
}
