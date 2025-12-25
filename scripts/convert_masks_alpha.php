<?php
declare(strict_types=1);

// Usage:
// php scripts/convert_masks_alpha.php /path/to/photos [--force]
// Scans */masks/*.png and converts grayscale masks to alpha if needed.

$root = $argv[1] ?? '';
$force = in_array('--force', $argv, true);

if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Usage: php scripts/convert_masks_alpha.php /path/to/photos [--force]\n");
    exit(1);
}

$root = rtrim($root, '/');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$count = 0;
$converted = 0;

foreach ($it as $file) {
    if (!$file->isFile()) continue;
    if (strtolower($file->getExtension()) !== 'png') continue;
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'masks' . DIRECTORY_SEPARATOR) === false) continue;
    $count++;
    if (convertMask($path, $force)) {
        $converted++;
        fwrite(STDOUT, "converted: {$path}\n");
    }
}

fwrite(STDOUT, "Done. scanned={$count} converted={$converted}\n");

function convertMask(string $absPath, bool $force): bool
{
    $im = @imagecreatefrompng($absPath);
    if (!$im) {
        fwrite(STDERR, "skip (open fail): {$absPath}\n");
        return false;
    }
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
    if (!$force && $hasOpaque && $hasTransparent) {
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
