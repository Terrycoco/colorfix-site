<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAssetLibraryRepository;
use App\Services\AssetLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function slug_part(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 80) : 'asset';
}

function extension_for_upload(array $file): string {
    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : '';
}

function asset_kind_for_upload(string $ext, string $mime): string {
    if (in_array($ext, ['mp3', 'wav', 'm4a'], true) || str_starts_with($mime, 'audio/')) return 'audio';
    if (in_array($ext, ['mp4', 'mov', 'webm', 'm4v'], true) || str_starts_with($mime, 'video/')) return 'video';
    if (in_array($ext, ['pdf', 'ppt', 'pptx', 'key'], true)) return 'document';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) || str_starts_with($mime, 'image/')) return 'image';
    return 'other';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['ok' => false, 'error' => 'file required'], 400);
    }

    $file = $_FILES['file'];
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        respond(['ok' => false, 'error' => 'Invalid upload'], 400);
    }

    $ext = extension_for_upload($file);
    if ($ext === '') {
        respond(['ok' => false, 'error' => 'File extension required'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($tmp) ?: ($file['type'] ?? ''));
    $kind = asset_kind_for_upload($ext, $mime);
    $allowed = ['mp3', 'wav', 'm4a', 'mp4', 'mov', 'webm', 'm4v', 'pdf', 'ppt', 'pptx', 'key', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        respond(['ok' => false, 'error' => 'Unsupported asset file type'], 400);
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $originalName = (string)($file['name'] ?? "asset.{$ext}");
    if ($title === '') {
        $title = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Asset';
    }

    $folder = match ($kind) {
        'audio' => 'audio',
        'video' => 'video',
        'document' => 'documents',
        'image' => 'images',
        default => 'other',
    };
    $baseDir = dirname(__DIR__, 4) . '/photos/assets/' . $folder;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('Failed to create asset upload folder');
    }

    $filename = slug_part(pathinfo($originalName, PATHINFO_FILENAME)) . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $absPath = $baseDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $absPath)) {
        throw new RuntimeException('Failed to store uploaded asset');
    }
    @chmod($absPath, 0664);

    $relPath = '/photos/assets/' . $folder . '/' . $filename;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $service = new AssetLibraryService(new PdoAssetLibraryRepository($pdo), $baseUrl);
    $asset = $service->upsertAssetByPath($relPath, [
        'asset_kind' => $kind,
        'mime_type' => $mime,
        'title' => $title,
        'tags' => trim((string)($_POST['tags'] ?? '')),
        'note' => trim((string)($_POST['note'] ?? '')),
        'source_type' => 'asset_upload',
        'file_size_bytes' => is_file($absPath) ? filesize($absPath) : null,
        'checksum' => is_file($absPath) ? hash_file('sha256', $absPath) : null,
        'metadata_json' => [
            'original_name' => $originalName,
            'uploaded_via' => 'asset_library',
            'rights_clearance' => $kind === 'audio' ? 'cleared' : 'unknown',
        ],
        'is_inactive' => 0,
        'is_retired' => 0,
    ]);

    respond(['ok' => true, 'item' => $asset]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
