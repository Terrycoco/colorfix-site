<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoRepository;
use App\Services\PhotoRenderingService;

/**
 * PhotosUploadService
 *
 * Writes files into the canonical layout and records variants via repo.
 * Layout:
 *   /photos/YYYY/YYYY-MM/ASSET_ID/
 *     prepared/  base.jpg  + dark.jpg + medium.jpg + light.jpg
 *     repaired/  base.jpg
 *     masks/     <role>.png
 *     renders/   <role or composite>.png
 *     extras/    <name>.<ext>
 *
 * Variants in DB (UNIQUE(photo_id, kind, role)):
 *   - Base (legacy single): kind='prepared' role=''
 *   - New trio: kind='prepared' role in {'dark','medium','light'}
 *   - repaired base: kind='repaired' role=''
 *   - masks: kind='masks' role='<role>'
 *   - renders: kind='renders' role='<role or composite>'
 *   - extras: kind='extras' role='<label>'
 */
final class PhotosUploadService
{
    public function __construct(
        private PdoPhotoRepository $repo,
        private string $photosRoot, // e.g., $_SERVER['DOCUMENT_ROOT'].'/colorfix/photos'
        private \PDO $pdo
    ) {}
    private array $assetPathOverrides = [];

    public function registerAssetPath(string $assetId, ?string $relativePath): void
    {
        $relativePath = $this->normalizeCategoryPath($relativePath);
        if (!$relativePath) $relativePath = $this->defaultSubpath();
        $this->assetPathOverrides[$assetId] = $relativePath;
    }

    public function moveAssetBase(string $assetId, string $fromBase, string $toBase): void
    {
        $fromBase = $this->normalizeCategoryPath($fromBase) ?? $this->defaultSubpath();
        $toBase   = $this->normalizeCategoryPath($toBase) ?? $this->defaultSubpath();
        $from = rtrim($this->photosRoot, '/')."/{$fromBase}/{$assetId}";
        $to   = rtrim($this->photosRoot, '/')."/{$toBase}/{$assetId}";
        if (!is_dir($from)) {
            $this->assetPathOverrides[$assetId] = $toBase;
            return;
        }
        $this->ensureDir(dirname($to));
        if (!@rename($from, $to)) {
            throw new \RuntimeException("Failed to move asset directory from {$from} to {$to}");
        }
        $this->assetPathOverrides[$assetId] = $toBase;
    }

    /** Save legacy single prepared/repaired base (filename: base.<ext>; role=''). */
    public function saveBase(int $photoId, string $kind, array $file): array
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['prepared', 'repaired'], true)) {
            throw new \InvalidArgumentException("Invalid base kind: {$kind}");
        }

        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) {
            throw new \RuntimeException("Photo not found: {$photoId}");
        }
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, $kind);
        $this->ensureDir($destDir);

        [$ext, $mime] = $this->extFromUpload($file);
        $filename = "base.{$ext}";
        $destPath = rtrim($destDir, '/')."/{$filename}";

        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, $mime);

        // Correct param order: mime, bytes, width, height
        $this->repo->upsertVariant($photoId, $kind, '', $this->publicPath($destPath), $mime, $bytes, $width, $height, []);

        return [
            'ok'      => true,
            'kind'    => $kind,
            'role'    => '',
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'mime'    => $mime,
            'assetId' => $assetId,
        ];
    }

    /** Save a role mask PNG into masks/<role>.png (overwrite if exists) */
    public function saveMask(
        int $photoId,
        string $role,
        array $file,
        array $overlaySettings = [],
        ?string $originalTexture = null,
        bool $force = false
    ): array
    {
        $role = $this->normalizeRole($role);
        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, 'masks');
        $this->ensureDir($destDir);

        // Force PNG for masks
        $filename = "{$role}.png";
        $destPath = rtrim($destDir, '/')."/{$filename}";
        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, 'image/png');

        $maskPublicPath = $this->publicPath($destPath);
        // Always upsert (overwrite) the mask variant
        $this->repo->upsertVariant(
            $photoId,
            'masks',
            $role,
            $maskPublicPath,
            'image/png',
            $bytes,
            $width,
            $height,
            $overlaySettings,
            $originalTexture
        );
        // Ensure stats are refreshed for this role
        $this->persistMaskStats($photoId, $assetId, $role, $maskPublicPath, $destPath);
        $this->flagAppliedPalettesForMask($assetId, $role);

        return [
            'ok'      => true,
            'kind'    => 'masks',
            'role'    => $role,
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'assetId' => $assetId,
            'original_texture' => $originalTexture,
            'overwritten' => true,
        ];
    }

    private function flagAppliedPalettesForMask(string $assetId, string $maskRole): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE applied_palettes ap
                JOIN applied_palette_entries ape ON ape.applied_palette_id = ap.id
                SET ap.needs_rerender = 1, ap.updated_at = NOW()
                WHERE ap.asset_id = :asset_id
                  AND ape.mask_role = :mask_role
            ");
            $stmt->execute([
                ':asset_id' => $assetId,
                ':mask_role' => $maskRole,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: uploading masks should not fail if rerender flagging does.
        }
    }

    /** Save a texture overlay into textures/overlay.<ext> */
    public function saveTextureOverlay(int $photoId, array $file): array
    {
        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, 'textures');
        $this->ensureDir($destDir);

        [$ext, $mime] = $this->extFromUpload($file);
        $filename = "overlay.{$ext}";
        $destPath = rtrim($destDir, '/')."/{$filename}";
        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, $mime);

        $this->repo->upsertVariant($photoId, 'texture', '', $this->publicPath($destPath), $mime, $bytes, $width, $height, []);

        return [
            'ok'      => true,
            'kind'    => 'texture',
            'role'    => '',
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'assetId' => $assetId,
        ];
    }

    /** Save a render PNG (role can be a specific role or 'composite'). */
    public function saveRender(int $photoId, ?string $role, array $file): array
    {
        $role = $role === null ? 'composite' : $this->normalizeRole($role);
        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, 'renders');
        $this->ensureDir($destDir);

        $filename = "{$role}.png";
        $destPath = rtrim($destDir, '/')."/{$filename}";
        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, 'image/png');

        $this->repo->upsertVariant($photoId, 'renders', $role, $this->publicPath($destPath), 'image/png', $bytes, $width, $height, []);

        return [
            'ok'      => true,
            'kind'    => 'renders',
            'role'    => $role,
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'assetId' => $assetId,
        ];
    }

    /** Save an extra/reference photo into extras/<role>.<ext>. */
    public function saveExtraPhoto(int $photoId, string $role, array $file): array
    {
        $role = $this->normalizeRole($role);
        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, 'extras');
        $this->ensureDir($destDir);

        [$ext, $mime] = $this->extFromUpload($file);
        $filename = "{$role}.{$ext}";
        $destPath = rtrim($destDir, '/')."/{$filename}";
        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, $mime);

        $this->repo->upsertVariant($photoId, 'extras', $role, $this->publicPath($destPath), $mime, $bytes, $width, $height, []);

        return [
            'ok'      => true,
            'kind'    => 'extras',
            'role'    => $role,
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'assetId' => $assetId,
        ];
    }

    /** Generic variant replace for arbitrary kind/role. */
    public function replaceVariant(int $photoId, string $kind, ?string $role, array $file): array
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['prepared','repaired','masks','renders','thumb','texture','extras'], true)) {
            throw new \InvalidArgumentException("Invalid kind: {$kind}");
        }

        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $dir = $this->buildDir($assetId, $kind);
        $this->ensureDir($dir);

        if ($kind === 'masks' || $kind === 'renders') {
            $role = $role === null ? 'composite' : $this->normalizeRole($role);
            $filename = "{$role}.png";
            $destMime = 'image/png';
        } else {
            $role = $role === null ? '' : $this->normalizeRole($role); // allow prepared tier override
            [$ext, $destMime] = $this->extFromUpload($file);
            $extOut = 'jpg';
            if ($destMime === 'image/png') $extOut = 'png';
            elseif ($destMime === 'image/webp') $extOut = 'webp';
            $filename = ($role === '' ? 'base' : $role) . ".{$extOut}";
        }

        $destPath = rtrim($dir, '/')."/{$filename}";
        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, $destMime);

        $this->repo->upsertVariant($photoId, $kind, $role, $this->publicPath($destPath), $destMime, $bytes, $width, $height, []);

        return [
            'ok'      => true,
            'kind'    => $kind,
            'role'    => $role,
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'assetId' => $assetId,
        ];
    }

    // ---------- helpers ----------

    private function buildDir(string $assetId, string $leaf): string
    {
        $root = $this->assetRootPath($assetId);
        return "{$root}/{$leaf}";
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    /** Returns [ext, mime] */
    private function extFromUpload(array $file): array
    {
        $mime = (string)($file['type'] ?? '');
        return match ($mime) {
            'image/png'  => ['png',  'image/png'],
            'image/webp' => ['webp', 'image/webp'],
            default      => ['jpg',  'image/jpeg'],
        };
    }

    private function moveUpload(array $file, string $destPath): void
    {
        if (file_exists($destPath)) {
            if (is_dir($destPath)) {
                throw new \RuntimeException("Destination is a directory: {$destPath}");
            }
            if (!is_writable($destPath)) {
                throw new \RuntimeException("Destination is not writable: {$destPath}");
            }
            if (!@unlink($destPath)) {
                throw new \RuntimeException("Failed to remove existing file: {$destPath}");
            }
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            // fallback: allow server-side moves for already-staged files
            if ($tmp !== '' && file_exists($tmp)) {
                if (!@rename($tmp, $destPath)) {
                    throw new \RuntimeException("Failed to move file (rename fallback) to {$destPath}");
                }
                @chmod($destPath, 0664);
                return;
            }
            throw new \RuntimeException('Invalid upload (tmp_name missing or not an uploaded file).');
        }
        if (!@move_uploaded_file($tmp, $destPath)) {
            throw new \RuntimeException("Failed to move uploaded file to {$destPath}");
        }
        @chmod($destPath, 0664);
    }


    private function convertMaskToAlphaIfNeeded(string $absPath, string $role): void
    {
        $im = @imagecreatefrompng($absPath);
        if (!$im) {
            throw new \RuntimeException("Unable to open mask for alpha conversion: {$role}");
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
        if ($hasOpaque && $hasTransparent) {
            imagedestroy($im);
            return;
        }

        $bg = $this->sampleMaskBackground($im, $w, $h);
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
    }

    private function sampleMaskBackground(\GdImage $im, int $w, int $h): array
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

    /** Returns [width, height, bytes] (bytes=filesize) */
    private function probeImage(string $path, ?string $mimeHint = null): array
    {
        $width = $height = null;
        if (is_file($path)) {
            $info = @getimagesize($path);
            if ($info && isset($info[0], $info[1])) {
                $width = (int)$info[0];
                $height = (int)$info[1];
            }
        }
        $bytes = is_file($path) ? (int)@filesize($path) : null;
        return [$width, $height, $bytes];
    }

    private function publicPath(string $absPath): string
    {
        // Convert absolute filesystem path to public path segment after photos root
        $root = rtrim($this->photosRoot, '/');
        if (str_starts_with($absPath, $root)) {
            return '/photos' . substr($absPath, strlen($root));
        }
        return $absPath;
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        $role = preg_replace('/[^a-z0-9_-]/', '-', $role);
        return $role === '' ? 'role' : $role;
    }

    private function defaultSubpath(): string
    {
        return "uncategorized";
    }

    private function normalizeCategoryPath(?string $path): ?string
    {
        if (!$path) return null;
        $trim = trim($path, "/ \t\n\r\0\x0B");
        if ($trim === '') return null;
        $trim = str_replace('\\', '/', $trim);
        $segments = array_filter(array_map(function ($seg) {
            $seg = strtolower(trim($seg));
            $seg = preg_replace('/[^a-z0-9\-]+/', '-', $seg);
            $seg = trim($seg, '-');
            return $seg;
        }, explode('/', $trim)));
        if (!$segments) return null;
        return implode('/', $segments);
    }

    private function assetRootPath(string $assetId): string
    {
        $base = $this->assetPathOverrides[$assetId] ?? $this->defaultSubpath();
        return rtrim($this->photosRoot, '/')."/{$base}/{$assetId}";
    }

    private function findPreparedBasePath(string $assetId): ?string
    {
        $dir = $this->assetRootPath($assetId) . '/prepared';
        if (!is_dir($dir)) return null;
        $candidates = glob($dir . '/base.*');
        if (!$candidates) return null;
        return $candidates[0];
    }

    private function persistMaskStats(int $photoId, string $assetId, string $role, string $maskPublicPath, string $maskAbsPath): void
    {
        $preparedAbs = $this->findPreparedBasePath($assetId);
        if (!$preparedAbs || !is_file($preparedAbs)) return;
        $maskAbs = $maskAbsPath;
        if (!is_file($maskAbs)) return;

        $renderer = new PhotoRenderingService($this->repo, $this->pdo);
        $stats = $renderer->measureMaskStats($preparedAbs, $maskAbs);
        if (!$stats) return;

        $preparedPublic = $this->publicPath($preparedAbs);
        $preparedBytes = (int)@filesize($preparedAbs);
        $maskBytes     = (int)@filesize($maskAbs);

        $this->repo->upsertMaskStats(
            $photoId,
            $role,
            $preparedPublic,
            $maskPublicPath,
            $preparedBytes,
            $maskBytes,
            $stats['l_avg'],
            $stats['l_p10'],
            $stats['l_p90'],
            $stats['px_covered']
        );
    }
}
