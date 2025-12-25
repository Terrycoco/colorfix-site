<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../autoload.php';

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST only']);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$path = trim((string)($input['path'] ?? ''));
$convert = !empty($input['convert']);
$force = !empty($input['force']);

if ($path === '') {
    respond(400, ['ok' => false, 'error' => 'path required']);
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3), '/');
$photosRoot = $docRoot . '/photos';

$normalized = ltrim($path, '/');
if (str_starts_with($normalized, 'photos/')) {
    $normalized = substr($normalized, strlen('photos/'));
}
$target = $photosRoot . '/' . $normalized;
$realTarget = realpath($target);
$realPhotos = realpath($photosRoot);
if (!$realTarget || !$realPhotos || !str_starts_with($realTarget, $realPhotos)) {
    respond(400, ['ok' => false, 'error' => 'Invalid path']);
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realTarget));
$scanned = 0;
$ok = 0;
$needs = 0;
$converted = 0;
$needsList = [];
$convertedList = [];

foreach ($it as $file) {
    if (!$file->isFile()) continue;
    if (strtolower($file->getExtension()) !== 'png') continue;
    $filePath = $file->getPathname();
    $scanned++;
    $status = alphaStatus($filePath);
    if ($status === 'ok') {
        $ok++;
        if ($convert && $force) {
            if (convertMask($filePath, true)) {
                $converted++;
                if (count($convertedList) < 200) {
                    $convertedList[] = str_replace($realPhotos, '/photos', $filePath);
                }
            }
        }
        continue;
    }
    $needs++;
    if (count($needsList) < 200) {
        $needsList[] = str_replace($realPhotos, '/photos', $filePath);
    }
    if ($convert) {
        if (convertMask($filePath, $force)) {
            $converted++;
            if (count($convertedList) < 200) {
                $convertedList[] = str_replace($realPhotos, '/photos', $filePath);
            }
        }
    }
}

respond(200, [
    'ok' => true,
    'path' => str_replace($realPhotos, '/photos', $realTarget),
    'scanned' => $scanned,
    'ok_count' => $ok,
    'needs_count' => $needs,
    'converted' => $converted,
    'needs' => $needsList,
    'converted_files' => $convertedList,
]);

function alphaStatus(string $absPath): string
{
    $im = @imagecreatefrompng($absPath);
    if (!$im) return 'error';
    $w = imagesx($im);
    $h = imagesy($im);
    $step = max(1, (int)floor(min($w, $h) / 300));
    $hasOpaque = false;
    $hasTransparent = false;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c & 0x7F000000) >> 24; // 0 opaque, 127 transparent
            if ($a === 0) $hasOpaque = true;
            if ($a > 0) $hasTransparent = true;
            if ($hasOpaque && $hasTransparent) break 2;
        }
    }
    imagedestroy($im);
    return ($hasOpaque && $hasTransparent) ? 'ok' : 'needs';
}

function convertMask(string $absPath, bool $force): bool
{
    $im = @imagecreatefrompng($absPath);
    if (!$im) return false;
    $w = imagesx($im);
    $h = imagesy($im);
    if (!$force && alphaStatus($absPath) === 'ok') {
        imagedestroy($im);
        return false;
    }

    $bg = sampleMaskBackground($im, $w, $h);
    $out = imagecreatetruecolor($w, $h);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    $transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
    imagefill($out, 0, 0, $transparent);

    $tol = 10;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            $dist = abs($r - $bg[0]) + abs($g - $bg[1]) + abs($b - $bg[2]);
            if ($dist <= $tol * 3) {
                $alpha = 127;
            } else {
                $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
                $alpha = (int)round(127 - ($lum / 255.0 * 127));
                if ($alpha < 0) $alpha = 0;
                if ($alpha > 127) $alpha = 127;
            }
            $col = imagecolorallocatealpha($out, 255, 255, 255, $alpha);
            imagesetpixel($out, $x, $y, $col);
        }
    }

    imagepng($out, $absPath, 9);
    imagedestroy($out);
    imagedestroy($im);
    return true;
}

function sampleMaskBackground(\GdImage $im, int $w, int $h): array
{
    $coords = [
        [0, 0],
        [$w - 1, 0],
        [0, $h - 1],
        [$w - 1, $h - 1],
    ];
    $sum = [0, 0, 0];
    foreach ($coords as [$x, $y]) {
        $c = imagecolorat($im, $x, $y);
        $sum[0] += ($c >> 16) & 0xFF;
        $sum[1] += ($c >> 8) & 0xFF;
        $sum[2] += $c & 0xFF;
    }
    return [
        (int)round($sum[0] / count($coords)),
        (int)round($sum[1] / count($coords)),
        (int)round($sum[2] / count($coords)),
    ];
}
