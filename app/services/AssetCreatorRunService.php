<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\AppTime;
use App\Repos\PdoAssetCreatorRepository;
use PDO;
use RuntimeException;

final class AssetCreatorRunService
{
    private const PIN_WIDTH = 1000;
    private const PIN_HEIGHT = 1500;
    private const CREATOR_PIN_COMPOSITE = 'pinterest.before_after_composite';

    public function __construct(
        private PdoAssetCreatorRepository $creatorRepo,
        private AssetLibraryService $assetLibrary,
        private string $rootDir,
        private ?PDO $pdo = null
    ) {}

    public function runJob(int $jobId): array
    {
        if ($jobId <= 0) {
            throw new RuntimeException('asset_creator_job_id required');
        }

        $job = $this->creatorRepo->findJob($jobId);
        if (!$job) {
            throw new RuntimeException('Asset creator job not found');
        }

        $creatorKey = trim((string)($job['creator_key'] ?? ''));
        if ($creatorKey !== self::CREATOR_PIN_COMPOSITE) {
            throw new RuntimeException("Creator is not runnable yet: {$creatorKey}");
        }

        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PHP GD image extension is required to create pin composites.');
        }

        $instructions = $this->decodeJsonObject($job['instructions_json'] ?? null);
        $pairs = array_values(array_filter(
            is_array($instructions['pairs'] ?? null) ? $instructions['pairs'] : [],
            static fn(mixed $pair): bool => is_array($pair) && ($pair['include'] ?? true) !== false
        ));

        if (!$pairs) {
            throw new RuntimeException('No included pairs found in this creator recipe.');
        }

        $outputs = [];
        foreach ($pairs as $idx => $pair) {
            $outputs[] = $this->createCompositePin($job, $pair, $idx + 1);
        }

        $this->creatorRepo->replaceOutputs($jobId, array_map(
            static fn(array $output): array => [
                'asset_library_id' => $output['asset_library_id'],
                'role' => 'pin',
                'status' => 'created',
                'generated_at' => AppTime::now(),
                'metadata_json' => $output['metadata'],
            ],
            $outputs
        ));

        $this->creatorRepo->updateJob($jobId, [
            'status' => 'generated',
            'last_run_at' => AppTime::now(),
        ]);

        $updated = $this->creatorRepo->findJob($jobId);
        if (!$updated) {
            throw new RuntimeException('Asset creator job not found after run');
        }

        return [
            'job' => $updated,
            'outputs' => $outputs,
        ];
    }

    private function createCompositePin(array $job, array $pair, int $pairOrder): array
    {
        $before = is_array($pair['before'] ?? null) ? $pair['before'] : [];
        $after = is_array($pair['after'] ?? null) ? $pair['after'] : [];
        $beforePath = $this->resolveRecipeImagePath($before);
        $afterPath = $this->resolveRecipeImagePath($after);

        $canvas = imagecreatetruecolor(self::PIN_WIDTH, self::PIN_HEIGHT);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $halfHeight = intdiv(self::PIN_HEIGHT, 2);
        $this->copyImageCover($canvas, $beforePath, 0, 0, self::PIN_WIDTH, $halfHeight);
        $this->copyImageCover($canvas, $afterPath, 0, $halfHeight, self::PIN_WIDTH, self::PIN_HEIGHT - $halfHeight);

        $divider = imagecolorallocatealpha($canvas, 255, 255, 255, 18);
        imagefilledrectangle($canvas, 0, $halfHeight - 4, self::PIN_WIDTH, $halfHeight + 4, $divider);
        $this->drawBadge($canvas, 'BEFORE', 34, 34);
        $this->drawBadge($canvas, 'AFTER', 34, $halfHeight + 34);

        $jobId = (int)$job['asset_creator_job_id'];
        $pairKey = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($pair['pair_key'] ?? "pair-{$pairOrder}"));
        $pairKey = trim((string)$pairKey, '-');
        if ($pairKey === '') {
            $pairKey = "pair-{$pairOrder}";
        }

        $relPath = "/photos/pins/generated/job-{$jobId}/pin-{$jobId}-{$pairOrder}-{$pairKey}.jpg";
        $absPath = $this->absolutePathForRelPath($relPath);
        $dir = dirname($absPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            throw new RuntimeException('Failed to create output folder.');
        }

        if (!imagejpeg($canvas, $absPath, 90)) {
            imagedestroy($canvas);
            throw new RuntimeException('Failed to write composite pin image.');
        }
        imagedestroy($canvas);

        $metadata = [
            'creator_key' => self::CREATOR_PIN_COMPOSITE,
            'asset_creator_job_id' => $jobId,
            'pair_key' => $pair['pair_key'] ?? null,
            'pair_order' => $pairOrder,
            'before' => $before,
            'after' => $after,
            'search_title' => $pair['search_title'] ?? $pair['pin_title'] ?? $pair['title'] ?? '',
            'description' => $pair['description'] ?? $pair['pin_description'] ?? $pair['caption'] ?? '',
        ];

        $title = trim((string)($metadata['search_title'] ?: ($job['title'] ?? "Pin {$jobId}-{$pairOrder}")));
        $asset = $this->assetLibrary->upsertAssetByPath($relPath, [
            'asset_kind' => 'image',
            'mime_type' => 'image/jpeg',
            'title' => $title,
            'tags' => 'pin, pinterest, composite, generated',
            'alt_text' => trim((string)($metadata['description'] ?? '')),
            'note' => 'Generated by asset creator job #' . $jobId,
            'source_type' => 'asset_creator_job',
            'source_id' => $jobId,
            'width' => self::PIN_WIDTH,
            'height' => self::PIN_HEIGHT,
            'file_size_bytes' => is_file($absPath) ? filesize($absPath) : null,
            'checksum' => is_file($absPath) ? hash_file('sha256', $absPath) : null,
            'metadata_json' => $metadata,
            'is_inactive' => 0,
            'is_retired' => 0,
        ]);

        return [
            'asset_library_id' => (int)$asset['asset_library_id'],
            'rel_path' => $relPath,
            'public_url' => $asset['public_url'] ?? $this->assetLibrary->publicUrlForRelPath($relPath),
            'metadata' => $metadata,
        ];
    }

    private function resolveRecipeImagePath(array $side): string
    {
        $assetId = (int)($side['asset_library_id'] ?? 0);
        if ($assetId > 0) {
            $path = $this->resolveAssetLibraryPath($assetId);
            if ($path !== null) {
                return $path;
            }
        }

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

        $publicUrl = trim((string)($side['public_url'] ?? ''));
        if ($publicUrl !== '') {
            return $this->existingAbsolutePath($publicUrl);
        }

        throw new RuntimeException('Pair is missing an image URL, photo id, or asset library id.');
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

        $stmt = $this->pdo->prepare(
            'SELECT rel_path
               FROM photo_library
              WHERE photo_library_id = :photo_library_id
              LIMIT 1'
        );
        $stmt->execute([':photo_library_id' => $photoLibraryId]);
        $relPath = trim((string)($stmt->fetchColumn() ?: ''));
        if ($relPath === '') {
            return null;
        }
        return $this->tryExistingAbsolutePath($relPath);
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

    private function copyImageCover(\GdImage $canvas, string $sourcePath, int $dstX, int $dstY, int $dstW, int $dstH): void
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

    private function drawBadge(\GdImage $canvas, string $label, int $x, int $y): void
    {
        $width = 235;
        $height = 72;
        $radius = 12;
        $black = imagecolorallocatealpha($canvas, 0, 0, 0, 58);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 8);
        $border = imagecolorallocatealpha($canvas, 255, 255, 255, 82);
        $this->drawRoundedRect($canvas, $x, $y, $width, $height, $radius, $black);
        $this->drawRoundedRectBorder($canvas, $x, $y, $width, $height, $radius, $border, 2);

        $font = $this->findFont();
        if ($font) {
            $fontSize = 36;
            $box = imagettfbbox($fontSize, 0, $font, $label);
            $textW = $box ? abs((int)$box[4] - (int)$box[0]) : 0;
            $textH = $box ? abs((int)$box[5] - (int)$box[1]) : 0;
            $textX = $x + (int)round(($width - $textW) / 2);
            $textY = $y + (int)round(($height + $textH) / 2) - 2;
            imagettftext($canvas, $fontSize, 0, $textX + 2, $textY + 2, $shadow, $font, $label);
            imagettftext($canvas, $fontSize, 0, $textX, $textY, $white, $font, $label);
            imagettftext($canvas, $fontSize, 0, $textX + 1, $textY, $white, $font, $label);
            imagettftext($canvas, $fontSize, 0, $textX, $textY + 1, $white, $font, $label);
            return;
        }
        $this->drawScaledFallbackText($canvas, $label, $x, $y, $width, $height, $white);
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

    private function drawRoundedRectBorder(\GdImage $canvas, int $x, int $y, int $width, int $height, int $radius, int $color, int $thickness): void
    {
        imagesetthickness($canvas, $thickness);
        imageline($canvas, $x + $radius, $y, $x + $width - $radius, $y, $color);
        imageline($canvas, $x + $radius, $y + $height, $x + $width - $radius, $y + $height, $color);
        imageline($canvas, $x, $y + $radius, $x, $y + $height - $radius, $color);
        imageline($canvas, $x + $width, $y + $radius, $x + $width, $y + $height - $radius, $color);
        imagearc($canvas, $x + $radius, $y + $radius, $radius * 2, $radius * 2, 180, 270, $color);
        imagearc($canvas, $x + $width - $radius, $y + $radius, $radius * 2, $radius * 2, 270, 360, $color);
        imagearc($canvas, $x + $width - $radius, $y + $height - $radius, $radius * 2, $radius * 2, 0, 90, $color);
        imagearc($canvas, $x + $radius, $y + $height - $radius, $radius * 2, $radius * 2, 90, 180, $color);
        imagesetthickness($canvas, 1);
    }

    private function findFont(): ?string
    {
        $candidates = [
            $this->rootDir . '/public/fonts/Inter-SemiBold.ttf',
            $this->rootDir . '/public/fonts/Inter-Bold.ttf',
            $this->rootDir . '/public/fonts/Montserrat-SemiBold.ttf',
            $this->rootDir . '/public/fonts/Montserrat-Bold.ttf',
            $this->rootDir . '/fonts/Montserrat.ttf',
            $this->rootDir . '/public/fonts/Montserrat.ttf',
            '/usr/share/fonts/truetype/inter/Inter-SemiBold.ttf',
            '/usr/share/fonts/truetype/inter/Inter-Bold.ttf',
            '/usr/share/fonts/truetype/montserrat/Montserrat-SemiBold.ttf',
            '/usr/share/fonts/truetype/montserrat/Montserrat-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function drawScaledFallbackText(\GdImage $canvas, string $label, int $x, int $y, int $badgeW, int $badgeH, int $color): void
    {
        $font = 5;
        $baseW = imagefontwidth($font) * strlen($label);
        $baseH = imagefontheight($font);
        $scale = 4;
        $tmpW = max(1, $baseW);
        $tmpH = max(1, $baseH);
        $tmp = imagecreatetruecolor($tmpW, $tmpH);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $transparent);
        $white = imagecolorallocate($tmp, 255, 255, 255);
        imagestring($tmp, $font, 0, 0, $label, $white);

        $targetW = $baseW * $scale;
        $targetH = $baseH * $scale;
        $dstX = $x + (int)round(($badgeW - $targetW) / 2);
        $dstY = $y + (int)round(($badgeH - $targetH) / 2);
        imagecopyresampled($canvas, $tmp, $dstX, $dstY, 0, 0, $targetW, $targetH, $tmpW, $tmpH);
        imagedestroy($tmp);
    }

    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}
