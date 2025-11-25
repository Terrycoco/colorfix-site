<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoRepository;

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
 *
 * Variants in DB (UNIQUE(photo_id, kind, role)):
 *   - Base (legacy single): kind='prepared' role=''
 *   - New trio: kind='prepared' role in {'dark','medium','light'}
 *   - repaired base: kind='repaired' role=''
 *   - masks: kind='masks' role='<role>'
 *   - renders: kind='renders' role='<role or composite>'
 */
final class PhotosUploadService
{
    public function __construct(
        private PdoPhotoRepository $repo,
        private string $photosRoot // e.g., $_SERVER['DOCUMENT_ROOT'].'/colorfix/photos'
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

    /**
     * Save a prepared tier image (dark|medium|light) under prepared/<tier>.<ext>
     * DB: kind='prepared', role='<tier>'
     */
    public function savePreparedTier(int $photoId, string $tier, array $file): array
    {
        $tier = strtolower(trim($tier));
        if (!in_array($tier, ['dark','medium','light'], true)) {
            throw new \InvalidArgumentException("Invalid prepared tier: {$tier}");
        }

        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, 'prepared');
        $this->ensureDir($destDir);

        [$ext, $mime] = $this->extFromUpload($file);
        $filename = "{$tier}.{$ext}";
        $destPath = rtrim($destDir, '/')."/{$filename}";

        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, $mime);

        $this->repo->upsertVariant($photoId, 'prepared', $tier, $this->publicPath($destPath), $mime, $bytes, $width, $height, []);

        return [
            'ok'      => true,
            'kind'    => 'prepared',
            'role'    => $tier,
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'mime'    => $mime,
            'assetId' => $assetId,
        ];
    }

    /** Convenience: save any provided of the trio. Keys: prepared_dark|prepared_medium|prepared_light */
    public function savePreparedTrio(int $photoId, array $files): array
    {
        $out = [];
        if (!empty($files['prepared_dark'])   && $files['prepared_dark']['error']   === UPLOAD_ERR_OK) {
            $out[] = $this->savePreparedTier($photoId, 'dark',   $files['prepared_dark']);
        }
        if (!empty($files['prepared_medium']) && $files['prepared_medium']['error'] === UPLOAD_ERR_OK) {
            $out[] = $this->savePreparedTier($photoId, 'medium', $files['prepared_medium']);
        }
        if (!empty($files['prepared_light'])  && $files['prepared_light']['error']  === UPLOAD_ERR_OK) {
            $out[] = $this->savePreparedTier($photoId, 'light',  $files['prepared_light']);
        }
        return $out;
    }

    /** Save a role mask PNG into masks/<role>.png */
    public function saveMask(
        int $photoId,
        string $role,
        array $file,
        array $overlaySettings = [],
        ?string $originalTexture = null
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

        $this->repo->upsertVariant(
            $photoId,
            'masks',
            $role,
            $this->publicPath($destPath),
            'image/png',
            $bytes,
            $width,
            $height,
            $overlaySettings,
            $originalTexture
        );

        return [
            'ok'      => true,
            'kind'    => 'masks',
            'role'    => $role,
            'path'    => $this->publicPath($destPath),
            'width'   => $width,
            'height'  => $height,
            'assetId' => $assetId,
            'original_texture' => $originalTexture,
        ];
    }

    /** Save a texture overlay PNG into textures/overlay.png */
    public function saveTextureOverlay(int $photoId, array $file): array
    {
        $photo = $this->repo->getPhotoById($photoId);
        if (!$photo || empty($photo['asset_id'])) throw new \RuntimeException("Photo not found: {$photoId}");
        $assetId = (string)$photo['asset_id'];

        $destDir = $this->buildDir($assetId, 'textures');
        $this->ensureDir($destDir);

        $filename = "overlay.png";
        $destPath = rtrim($destDir, '/')."/{$filename}";
        $this->moveUpload($file, $destPath);

        [$width, $height, $bytes] = $this->probeImage($destPath, 'image/png');

        $this->repo->upsertVariant($photoId, 'texture', '', $this->publicPath($destPath), 'image/png', $bytes, $width, $height, []);

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

    /** Generic variant replace for arbitrary kind/role. */
    public function replaceVariant(int $photoId, string $kind, ?string $role, array $file): array
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['prepared','repaired','masks','renders','thumb','texture'], true)) {
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
        $base = $this->assetPathOverrides[$assetId] ?? $this->defaultSubpath();
        return rtrim($this->photosRoot, '/')."/{$base}/{$assetId}/{$leaf}";
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
        $y   = date('Y');
        $ym  = date('Y-m');
        return "{$y}/{$ym}";
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
}
