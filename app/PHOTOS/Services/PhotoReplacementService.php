<?php
declare(strict_types=1);

namespace App\PHOTOS\Services;

use App\Repos\PdoPhotoLibraryRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoAssetLibraryRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PhotoReplacementService
{
    public function __construct(private PDO $pdo, private string $root) {}

    public function replace(int $id, string $source, array $metadata = []): array
    {
        $repo = new PdoPhotoLibraryRepository($this->pdo);
        $row = $repo->findById($id);
        if (!$row) { throw new InvalidArgumentException('Photo not found.'); }
        $info = @getimagesize($source);
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null,
        };
        if (!$ext) { throw new InvalidArgumentException('Photo must be JPG, PNG, or WEBP.'); }
        $relPath = '/photos/replacements/photo-' . $id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $this->root . $relPath;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Could not create replacement folder.');
        }
        if (!copy($source, $target)) { throw new RuntimeException('Could not write replacement photo.'); }
        try {
            $this->pdo->beginTransaction();
            $lock = $this->pdo->prepare('SELECT rel_path FROM photo_library WHERE photo_library_id = ?'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$id]);
            if ($lock->fetchColumn() !== $row['rel_path']) {
                throw new RuntimeException('This photo changed during replacement. Reload and try again.');
            }
            $fields = ['rel_path' => $relPath];
            foreach (['title', 'tags', 'alt_text'] as $key) {
                if (trim((string)($metadata[$key] ?? '')) !== '') { $fields[$key] = trim((string)$metadata[$key]); }
            }
            $repo->update($id, $fields);
            $saved = new PdoSavedPaletteRepository($this->pdo);
            if ((int)($row['source_id'] ?? 0) > 0 && in_array($row['source_type'], ['saved_palette_photo', 'saved_before'], true)) {
                $photo = $saved->getPhotoById((int)$row['source_id']);
                if ($photo) { $saved->updatePhoto((int)$row['source_id'], (int)$photo['saved_palette_id'], ['rel_path' => $relPath]); }
            }
            $saved->syncRelPathFromPhotoLibrary($id, $relPath);
            if ((int)($row['asset_library_id'] ?? 0) > 0) {
                (new PdoAssetLibraryRepository($this->pdo))->update((int)$row['asset_library_id'], [
                    'rel_path' => $relPath, 'mime_type' => $info['mime'], 'width' => $info[0], 'height' => $info[1],
                    'file_size_bytes' => filesize($target), 'checksum' => hash_file('sha256', $target),
                ]);
            }
            $imageUrl = $relPath . '?v=' . rawurlencode(basename($relPath));
            $stmt = $this->pdo->prepare('UPDATE playlist_items SET image_url = ?, photo_library_id = ? WHERE photo_library_id = ? OR image_url LIKE ?');
            $stmt->execute(['photo:' . $id . '|' . $imageUrl, $id, $id, 'photo:' . $id . '|%']);
            $fresh = $repo->findById($id);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            @unlink($target);
            throw $e;
        }

        // Remove superseded bytes only after all references have been committed.
        $oldPath = (string)(parse_url((string)$row['rel_path'], PHP_URL_PATH) ?: '');
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM photo_library WHERE rel_path IN (?, ?)');
        $stmt->execute([$oldPath, ltrim($oldPath, '/')]);
        $shared = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM asset_library WHERE rel_path IN (?, ?)');
        $stmt->execute([$oldPath, ltrim($oldPath, '/')]);
        $shared += (int)$stmt->fetchColumn();
        if (!$shared) {
            $old = $this->localPath((string)$row['rel_path']);
            if ($old && !unlink($old)) { error_log('Could not remove superseded photo: ' . $old); }
        }
        foreach (glob($this->root . '/photos/cache/thumbs/pl-' . $id . '-*') ?: [] as $thumb) { @unlink($thumb); }
        return [...$fresh, 'image_url' => $imageUrl, 'file_path' => $imageUrl, 'raw_rel_path' => $relPath];
    }

    public function localPath(string $relPath): ?string
    {
        if (preg_match('~^https?://~i', $relPath)) { return null; }
        $relPath = (string)(parse_url($relPath, PHP_URL_PATH) ?: '');
        if ($relPath === '') { return null; }
        $root = realpath($this->root);
        $path = realpath($this->root . '/' . ltrim($relPath, '/'));
        return $root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
    }
}
