<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';

$photoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$width = isset($_GET['w']) ? (int)$_GET['w'] : 480;
$quality = isset($_GET['q']) ? (int)$_GET['q'] : 72;

$width = max(120, min(1200, $width));
$quality = max(45, min(90, $quality));

if ($photoId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing id';
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT rel_path, updated_at FROM photo_library WHERE photo_library_id = :id LIMIT 1');
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$photo) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }

    $relPath = trim((string)($photo['rel_path'] ?? ''));
    if ($relPath === '' || preg_match('~^https?://~i', $relPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unsupported image';
        exit;
    }

    $root = realpath(dirname(__DIR__, 2));
    if (!$root) {
        throw new RuntimeException('Root missing');
    }
    $sourcePath = realpath($root . '/' . ltrim(parse_url($relPath, PHP_URL_PATH) ?: $relPath, '/'));
    if (!$sourcePath || !str_starts_with($sourcePath, $root) || !is_file($sourcePath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Source missing';
        exit;
    }

    $mtime = (string)(filemtime($sourcePath) ?: strtotime((string)($photo['updated_at'] ?? '')) ?: time());
    $cacheDir = $root . '/photos/cache/thumbs';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $supportsWebp = function_exists('imagewebp');
    $format = $supportsWebp ? 'webp' : 'jpg';
    $cachePath = "{$cacheDir}/pl-{$photoId}-w{$width}-q{$quality}-{$mtime}.{$format}";
    if (!is_file($cachePath)) {
        create_thumbnail($sourcePath, $cachePath, $width, $quality, $format);
    }

    $maxAge = 31536000;
    header('Cache-Control: public, max-age=' . $maxAge . ', immutable');
    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('Content-Length: ' . filesize($cachePath));
    readfile($cachePath);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Thumbnail failed';
}

function create_thumbnail(string $sourcePath, string $destPath, int $targetWidth, int $quality, string $format): void {
    $info = getimagesize($sourcePath);
    if (!$info) {
        throw new RuntimeException('Invalid source image');
    }

    [$sourceWidth, $sourceHeight] = $info;
    $mime = (string)($info['mime'] ?? '');
    $source = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($sourcePath),
        'image/png' => imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if (!$source) {
        throw new RuntimeException('Unsupported source image');
    }

    $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / max(1, $sourceWidth))));
    if ($sourceWidth <= $targetWidth) {
        $targetWidth = $sourceWidth;
        $targetHeight = $sourceHeight;
    }

    $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    if ($format === 'webp' && function_exists('imagewebp')) {
        imagewebp($thumb, $destPath, $quality);
    } else {
        imagejpeg($thumb, $destPath, $quality);
    }

    imagedestroy($source);
    imagedestroy($thumb);
}
