<?php
declare(strict_types=1);

namespace App\Services\AssetCreators;

use App\Services\AssetLibraryService;
use PDO;
use RuntimeException;

final class PinterestPinRenderToolkit
{
    public const WIDTH = 1000;
    public const HEIGHT = 1500;
    public const IDEA_TITLE_HEIGHT = 280;
    private const LOGO_FILE = 'colorfix-pin-logo-compact-right-aligned-transparent.png';
    private const LOGO_WIDTH = 188;
    private const LOGO_MARGIN = 20;
    private const LOGO_BADGE_PADDING = 12;
    private const LOGO_BADGE_RADIUS = 8;
    private const IDEA_TITLE_PADDING_X = 70;
    private const IDEA_TITLE_Y = 34;
    private const IDEA_TITLE_INNER_HEIGHT = 218;
    private const IDEA_TITLE_FONT_SIZE = 58;
    private const IDEA_TITLE_MIN_FONT_SIZE = 42;

    public function __construct(
        private AssetLibraryService $assetLibrary,
        private string $rootDir,
        private ?PDO $pdo = null
    ) {}

    public function canvas(): \GdImage
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        return $canvas;
    }

    public function sourceAsset(array $pair): array
    {
        $asset = is_array($pair['asset'] ?? null) ? $pair['asset'] : [];
        if (!$asset) {
            $asset = is_array($pair['after'] ?? null) ? $pair['after'] : [];
        }
        if (!$asset) {
            throw new RuntimeException('Idea pin row is missing a source photo.');
        }
        return $asset;
    }

    public function resolveImagePath(array $side): string
    {
        $photoId = (int)($side['photo_library_id'] ?? 0);
        if ($photoId > 0) {
            $path = $this->resolvePhotoLibraryPath($photoId);
            if ($path !== null) {
                return $path;
            }

            $asset = $this->assetLibrary->getAssetForLegacyPhoto($photoId);
            if ($asset && !empty($asset['asset_library_id'])) {
                $path = $this->resolveAssetLibraryPath((int)$asset['asset_library_id']);
                if ($path !== null) {
                    return $path;
                }
            }
            if (!empty($asset['rel_path'])) {
                $path = $this->tryExistingAbsolutePath((string)$asset['rel_path']);
                if ($path !== null) {
                    return $path;
                }
            }
        }

        $assetId = (int)($side['asset_library_id'] ?? 0);
        if ($assetId > 0) {
            $path = $this->resolveAssetLibraryPath($assetId);
            if ($path !== null) {
                return $path;
            }
        }

        $publicUrl = trim((string)($side['public_url'] ?? ''));
        if ($publicUrl !== '') {
            return $this->existingAbsolutePath($publicUrl);
        }

        throw new RuntimeException('Pin row is missing an image URL, photo id, or asset library id.');
    }

    public function copyImageCover(\GdImage $canvas, string $sourcePath, int $dstX, int $dstY, int $dstW, int $dstH): void
    {
        $info = getimagesize($sourcePath);
        if (!$info) {
            throw new RuntimeException("Invalid image: {$sourcePath}");
        }

        [$srcW, $srcH] = $info;
        $source = $this->openImage($sourcePath, (string)($info['mime'] ?? ''));
        $scale = max($dstW / max(1, $srcW), $dstH / max(1, $srcH));
        $cropW = (int)round($dstW / $scale);
        $cropH = (int)round($dstH / $scale);
        $srcX = max(0, (int)floor(($srcW - $cropW) / 2));
        $srcY = max(0, (int)floor(($srcH - $cropH) / 2));

        imagecopyresampled($canvas, $source, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $cropW, $cropH);
        imagedestroy($source);
    }

    public function drawTitle(
        \GdImage $canvas,
        string $title,
        int $x,
        int $y,
        int $width,
        int $height,
        int $fontSize = 48,
        int $minFontSize = 34,
        bool $extraWeight = false,
        int $maxLines = 0
    ): void
    {
        $title = trim($title) !== '' ? trim($title) : 'ColorFix Ideas';
        $font = $this->findFont();
        $black = imagecolorallocate($canvas, 17, 17, 17);
        if (!$font) {
            imagestring($canvas, 5, $x, $y + 20, $title, $black);
            return;
        }

        $lineHeight = $fontSize + 12;
        $lines = $this->wrapText($title, $font, $fontSize, $width);
        while (
            (($maxLines > 0 && count($lines) > $maxLines) || count($lines) * $lineHeight > $height)
            && $fontSize > $minFontSize
        ) {
            $fontSize -= 2;
            $lineHeight -= 2;
            $lines = $this->wrapText($title, $font, $fontSize, $width);
        }
        if ($maxLines > 0 && count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->truncateLine($lines[$maxLines - 1], $font, $fontSize, $width);
        }
        $textY = $y + (int)round(($height - (count($lines) * $lineHeight)) / 2) + $fontSize;
        foreach ($lines as $line) {
            $lineX = $x + (int)round(($width - $this->textWidth($line, $font, $fontSize)) / 2);
            imagettftext($canvas, $fontSize, 0, $lineX, $textY, $black, $font, $line);
            if ($extraWeight) {
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, -1], [-1, 1], [1, 1], [2, 0], [0, 2]] as [$dx, $dy]) {
                    imagettftext($canvas, $fontSize, 0, $lineX + $dx, $textY + $dy, $black, $font, $line);
                }
            }
            $textY += $lineHeight;
        }
    }

    public function drawIdeaTitle(\GdImage $canvas, string $title): void
    {
        $this->drawTitle(
            $canvas,
            $title,
            self::IDEA_TITLE_PADDING_X,
            self::IDEA_TITLE_Y,
            self::WIDTH - (self::IDEA_TITLE_PADDING_X * 2),
            self::IDEA_TITLE_INNER_HEIGHT,
            self::IDEA_TITLE_FONT_SIZE,
            self::IDEA_TITLE_MIN_FONT_SIZE,
            true,
            2
        );
    }

    public function drawLogo(\GdImage $canvas): void
    {
        $logoPath = $this->findPinLogoPath();
        if (!is_file($logoPath)) {
            throw new RuntimeException('Pin logo file missing.');
        }

        $info = getimagesize($logoPath);
        if (!$info) {
            throw new RuntimeException('Invalid pin logo file.');
        }

        [$srcW, $srcH] = $info;
        $source = $this->openImage($logoPath, (string)($info['mime'] ?? 'image/png'));
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $dstW = self::LOGO_WIDTH;
        $dstH = max(1, (int)round($srcH * ($dstW / max(1, $srcW))));
        $dstX = self::WIDTH - self::LOGO_MARGIN - $dstW;
        $dstY = self::HEIGHT - self::LOGO_MARGIN - $dstH;
        $padding = self::LOGO_BADGE_PADDING;
        $badge = imagecolorallocatealpha($canvas, 255, 255, 255, 52);
        $this->drawRoundedRect(
            $canvas,
            $dstX - $padding,
            $dstY - $padding,
            $dstW + ($padding * 2),
            $dstH + ($padding * 2),
            self::LOGO_BADGE_RADIUS,
            $badge
        );

        imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);
    }

    public function paletteColorsForAsset(array $asset): array
    {
        if (!$this->pdo) {
            return [];
        }
        $appliedPaletteId = (int)($asset['ap_id'] ?? 0);
        if ($appliedPaletteId > 0) {
            $colors = $this->appliedPaletteColors($appliedPaletteId);
            if ($colors) {
                return $colors;
            }
        }

        $savedPaletteId = (int)($asset['saved_palette_id'] ?? 0);
        $paletteHash = trim((string)($asset['palette_hash'] ?? ''));
        if ($savedPaletteId <= 0 && $paletteHash !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM saved_palettes WHERE palette_hash = :hash LIMIT 1');
            $stmt->execute([':hash' => $paletteHash]);
            $savedPaletteId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($savedPaletteId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT c.name AS color_name,
                    c.brand AS color_brand,
                    c.code AS color_code,
                    c.hex6 AS color_hex6
               FROM saved_palette_members m
          LEFT JOIN swatch_view c
                 ON c.id = m.color_id
              WHERE m.saved_palette_id = :id
           ORDER BY m.order_index ASC, m.id ASC
              LIMIT 12'
        );
        $stmt->execute([':id' => $savedPaletteId]);
        $colors = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], static function (array $row): bool {
            return trim((string)($row['color_hex6'] ?? '')) !== '';
        }));
        return array_slice($this->uniqueColors($colors), 0, 4);
    }

    private function appliedPaletteColors(int $appliedPaletteId): array
    {
        return [];
    }

    public function drawPaintCanLid(\GdImage $canvas, array $color, int $cx, int $cy, int $diameter): void
    {
        $hex = $this->normalizeHex((string)($color['color_hex6'] ?? 'cccccc'));
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');
        $paint = imagecolorallocate($canvas, $r, $g, $b);
        $rim = imagecolorallocate($canvas, 176, 176, 176);
        $rimDark = imagecolorallocate($canvas, 104, 104, 104);
        $highlight = imagecolorallocatealpha($canvas, 255, 255, 255, 65);
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 84);
        $innerDiameter = (int)round($diameter * 0.78);
        $highlightDiameter = (int)round($diameter * 0.34);

        imagefilledellipse($canvas, $cx + 8, $cy + 12, $diameter, $diameter, $shadow);
        imagefilledellipse($canvas, $cx, $cy + 4, $diameter, $diameter, $rimDark);
        imagefilledellipse($canvas, $cx, $cy, $diameter, $diameter, $rim);
        imagefilledellipse($canvas, $cx, $cy, $innerDiameter, $innerDiameter, $paint);
        imagearc($canvas, $cx - (int)round($diameter * 0.1), $cy - (int)round($diameter * 0.16), $highlightDiameter, $highlightDiameter, 190, 340, $highlight);
    }

    private function uniqueColors(array $colors): array
    {
        $seen = [];
        $unique = [];
        foreach ($colors as $color) {
            $hex = $this->normalizeHex((string)($color['color_hex6'] ?? ''));
            if (isset($seen[$hex])) {
                continue;
            }
            $seen[$hex] = true;
            $color['color_hex6'] = $hex;
            $unique[] = $color;
        }
        return $unique;
    }

    public function writeJpeg(\GdImage $canvas, string $relPath): array
    {
        $absPath = $this->absolutePathForRelPath($relPath);
        $dir = dirname($absPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            throw new RuntimeException('Failed to create output folder.');
        }
        if (!imagejpeg($canvas, $absPath, 90)) {
            imagedestroy($canvas);
            throw new RuntimeException('Failed to write pin image.');
        }
        imagedestroy($canvas);
        return [
            'abs_path' => $absPath,
            'file_size_bytes' => is_file($absPath) ? filesize($absPath) : null,
            'checksum' => is_file($absPath) ? hash_file('sha256', $absPath) : null,
        ];
    }

    public function upsertPinAsset(string $relPath, array $data): array
    {
        return $this->assetLibrary->upsertAssetByPath($relPath, $data);
    }

    public function publicUrl(string $relPath): string
    {
        return $this->assetLibrary->publicUrlForRelPath($relPath);
    }

    private function resolveAssetLibraryPath(int $assetLibraryId): ?string
    {
        $asset = $this->assetLibrary->getAsset($assetLibraryId);
        if (!$asset || empty($asset['rel_path'])) {
            return null;
        }
        return $this->tryExistingAbsolutePath((string)$asset['rel_path']);
    }

    private function resolvePhotoLibraryPath(int $photoLibraryId): ?string
    {
        if (!$this->pdo || $photoLibraryId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT rel_path FROM photo_library WHERE photo_library_id = :photo_library_id LIMIT 1');
        $stmt->execute([':photo_library_id' => $photoLibraryId]);
        $relPath = trim((string)($stmt->fetchColumn() ?: ''));
        return $relPath !== '' ? $this->tryExistingAbsolutePath($relPath) : null;
    }

    private function tryExistingAbsolutePath(string $relOrUrl): ?string
    {
        try {
            return $this->existingAbsolutePath($relOrUrl);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function existingAbsolutePath(string $relOrUrl): string
    {
        $path = parse_url($relOrUrl, PHP_URL_PATH);
        $path = $path !== false && $path !== null ? $path : $relOrUrl;
        $path = preg_replace('~^/colorfix/~', '/', (string)$path);
        $abs = realpath($this->absolutePathForRelPath($path));
        $root = realpath($this->rootDir);
        if (!$abs || !$root || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
            throw new RuntimeException("Source image missing: {$relOrUrl}");
        }
        return $abs;
    }

    private function absolutePathForRelPath(string $relPath): string
    {
        $path = parse_url($relPath, PHP_URL_PATH);
        $path = $path !== false && $path !== null ? $path : $relPath;
        return rtrim($this->rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$path, '/');
    }

    private function openImage(string $sourcePath, string $mime): \GdImage
    {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => @imagecreatefromstring((string)@file_get_contents($sourcePath)),
        };
        if (!$source instanceof \GdImage) {
            throw new RuntimeException("Unsupported image: {$sourcePath}");
        }
        return $source;
    }

    private function findPinLogoPath(): string
    {
        $candidates = [
            $this->rootDir . '/brand/' . self::LOGO_FILE,
            $this->rootDir . '/public/brand/' . self::LOGO_FILE,
            dirname($this->rootDir) . '/public/brand/' . self::LOGO_FILE,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return $candidates[0];
    }

    private function findFont(): ?string
    {
        $candidates = [
            $this->rootDir . '/public/fonts/Inter-SemiBold.ttf',
            $this->rootDir . '/public/fonts/Inter-Bold.ttf',
            $this->rootDir . '/public/fonts/Montserrat-SemiBold.ttf',
            $this->rootDir . '/public/fonts/Montserrat-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Trebuchet MS Bold.ttf',
            '/System/Library/Fonts/Supplemental/Verdana Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
            $this->rootDir . '/fonts/Montserrat.ttf',
            $this->rootDir . '/public/fonts/Montserrat.ttf',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function wrapText(string $text, string $font, int $fontSize, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $test = trim($line . ' ' . $word);
            $box = imagettfbbox($fontSize, 0, $font, $test);
            $width = $box ? abs((int)$box[4] - (int)$box[0]) : 0;
            if ($line !== '' && $width > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $test;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines ?: [$text];
    }

    private function truncateLine(string $line, string $font, int $fontSize, int $maxWidth): string
    {
        $line = trim($line);
        if ($this->textWidth($line, $font, $fontSize) <= $maxWidth) {
            return $line;
        }
        $suffix = '...';
        while ($line !== '' && $this->textWidth(rtrim($line) . $suffix, $font, $fontSize) > $maxWidth) {
            $line = rtrim(substr($line, 0, -1));
        }
        return $line !== '' ? $line . $suffix : $suffix;
    }

    private function textWidth(string $text, string $font, int $fontSize): int
    {
        $box = imagettfbbox($fontSize, 0, $font, $text);
        return $box ? abs((int)$box[4] - (int)$box[0]) : 0;
    }

    private function drawRoundedRect(\GdImage $canvas, int $x, int $y, int $width, int $height, int $radius, int $color): void
    {
        imagefilledrectangle($canvas, $x + $radius, $y, $x + $width - $radius, $y + $height, $color);
        imagefilledrectangle($canvas, $x, $y + $radius, $x + $width, $y + $height - $radius, $color);
        imagefilledellipse($canvas, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x + $width - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x + $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($canvas, $x + $width - $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color);
    }

    private function normalizeHex(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : 'cccccc';
    }
}
