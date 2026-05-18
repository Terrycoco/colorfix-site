<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoFileLockerRepository;
use App\Services\FileLockerService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo 'GET only';
        exit;
    }

    $service = new FileLockerService(new PdoFileLockerRepository($pdo));
    $file = $service->getDownloadInfo();
    if (!$file) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes((string)$file['download_name']) . '"');
    header('Content-Length: ' . (string)$file['file_size']);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: public');
    readfile((string)$file['path']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Download failed';
}
