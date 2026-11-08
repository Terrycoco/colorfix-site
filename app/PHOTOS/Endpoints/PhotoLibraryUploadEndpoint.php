<?php
declare(strict_types=1);
namespace App\PHOTOS\Endpoints;
use PDO;
use App\PHOTOS\Services\PhotoLibraryUploadService;
final class PhotoLibraryUploadEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Use POST']); return; }
            $root = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 3);
            echo json_encode((new PhotoLibraryUploadService($pdo))->upload($_POST, $_FILES, $root), JSON_UNESCAPED_SLASHES);
        } catch (\InvalidArgumentException $e) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        } catch (\OutOfBoundsException $e) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        } catch (\Throwable $e) { error_log('PHOTOS upload: '.$e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Photo upload failed']); }
    }
}
