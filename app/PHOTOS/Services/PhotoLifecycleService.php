<?php
declare(strict_types=1);
namespace App\PHOTOS\Services;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PHOTOS\Repos\PdoPhotoLifecycleRepository;
use PDO;
use Throwable;

/** Owns row disposal, reference reassignment and post-commit file disposal. */
final class PhotoLifecycleService
{
    private PdoPhotoLibraryRepository $photos;
    private PdoPhotoLifecycleRepository $refs;
    private string $root;
    public function __construct(private PDO $pdo, ?string $root = null, private ?\Closure $removeFile = null)
    {
        $this->photos = new PdoPhotoLibraryRepository($pdo);
        $this->refs = new PdoPhotoLifecycleRepository($pdo);
        $this->root = $root ?? ((string)($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 3));
    }

    private function lock(array $ids): void
    {
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $stmt = $this->pdo->prepare('SELECT photo_library_id FROM photo_library WHERE photo_library_id = ?'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $stmt->execute([$id]);
            $stmt->fetchColumn();
        }
    }

    public function delete(int $id): array
    {
        // Never unlink files before a caller's still-pending transaction commits.
        if ($this->pdo->inTransaction()) { throw new \LogicException('Photo deletion requires its own transaction; finish the association transaction first.'); }
        $this->pdo->beginTransaction();
        try {
            $this->lock([$id]);
            $row = $this->photos->findById($id);
            if (!$row) { $this->pdo->rollBack(); return ['ok' => false, 'status' => 404, 'error' => 'Photo not found']; }
            $usages = (new PhotoLibraryUsageService($this->photos))->getUsageSummary($id);
            if ($usages) {
                $this->pdo->rollBack();
                return ['ok' => false, 'status' => 409, 'error' => 'Photo is still in use elsewhere. Unlink the listed dependencies before deleting.', 'usages' => $usages];
            }
            $this->pdo->prepare('DELETE FROM photo_library WHERE photo_library_id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
        PhotoThumbnailService::invalidate($id, $this->root);
        return ['ok' => true, ...$this->disposeFile((string)($row['rel_path'] ?? ''))];
    }

    /** Other departments may dispose their own outputs, never Photo Library source bytes. */
    public function assertNotLibraryFile(string $file): void
    {
        $actual = realpath($file);
        if (!$actual) { return; }
        foreach ($this->pdo->query('SELECT rel_path FROM photo_library')->fetchAll(PDO::FETCH_COLUMN) as $rel) {
            $candidate = realpath(str_starts_with((string)$rel, rtrim($this->root, '/') . '/') ? (string)$rel : rtrim($this->root, '/') . PdoPhotoLifecycleRepository::path((string)$rel));
            if ($candidate && $candidate === $actual) {
                throw new \DomainException('Physical file belongs to Photo Library. Remove its dependencies and delete it through PHOTOS instead.');
            }
        }
    }

    /** Also used when a palette detaches a compatibility photo row without a library ID. */
    public function disposeFile(string $rel): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('File disposal must follow commit.'); }
        $retain = static fn(string $reason): array => ['file_deleted' => false, 'file_retained' => true, 'file_reason' => $reason];
        try {
            $docRoot = rtrim((string)realpath($this->root), '/');
            if ($docRoot !== '' && str_starts_with($rel, $docRoot . '/')) { $rel = substr($rel, strlen($docRoot)); }
            $path = PdoPhotoLifecycleRepository::path($rel);
            if ($rel === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $rel) || !str_starts_with($path, '/photos/')) {
                return $retain('Outside managed Photo Library storage');
            }
            $root = realpath($this->root . '/photos');
            $abs = realpath(rtrim($this->root, '/') . $path);
            if (!$abs || !is_file($abs)) { return ['file_deleted' => false, 'file_retained' => false, 'file_reason' => 'File absent']; }
            if (!$root || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || is_link(rtrim($this->root, '/') . $path)) { return $retain('Unsafe file path'); }
            $refs = $this->refs->fileReferences($path, $this->root);
            // A second spelling or symlink of the same file also protects its bytes.
            foreach ($this->pdo->query('SELECT rel_path FROM photo_library UNION SELECT rel_path FROM asset_library')->fetchAll(PDO::FETCH_COLUMN) as $other) {
                if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', (string)$other)) { continue; }
                if (realpath(rtrim($this->root, '/') . PdoPhotoLifecycleRepository::path((string)$other)) === $abs) { return $retain('File shared by another photo or asset record'); }
            }
            if ($refs) { return $retain('File still referenced by ' . $refs[0]['label']); }
            $deleted = $this->removeFile ? ($this->removeFile)($abs) : @unlink($abs);
            return $deleted ? ['file_deleted' => true, 'file_retained' => false] : $retain('Filesystem removal failed; database deletion committed');
        } catch (Throwable $e) {
            error_log('PHOTOS file disposal retained file: ' . $e->getMessage());
            return $retain('Dependency check or filesystem operation failed; file retained');
        }
    }

    /** Palette owns unlinking its association; PHOTOS decides whether its detached assets can be removed. */
    public function cleanupDetachedPalettePhotos(array $photos): array
    {
        // Palettes own associations, never Photo Library assets. No source-ID cascades remain.
        return array_map(static fn(array $photo): array => [
            'photo_library_id'=>(int)($photo['photo_library_id'] ?? $photo['id'] ?? 0),
            'retained'=>true, 'reason'=>'Photo Library asset retained after palette unlink',
        ],$photos);
    }

    public function deleteInactive(): array
    {
        $rows = $this->photos->listInactiveRows();
        $result = ['checked' => count($rows), 'deleted' => 0, 'skipped' => 0, 'items' => []];
        foreach ($rows as $row) {
            $id = (int)$row['photo_library_id'];
            try { $outcome = $this->delete($id); }
            catch (Throwable $e) { $outcome = ['ok' => false, 'error' => 'Deletion failed; photograph retained']; error_log('PHOTOS bulk delete: ' . $e->getMessage()); }
            $httpStatus = $outcome['status'] ?? null;
            unset($outcome['status']);
            $ok = !empty($outcome['ok']);
            $result[$ok ? 'deleted' : 'skipped']++;
            $result['items'][] = ['photo_library_id' => $id, 'status' => $ok ? 'deleted' : (isset($outcome['usages']) ? 'in_use' : 'error'),
                'rel_path' => (string)$row['rel_path'], 'title' => (string)$row['title'],
                'usage_count' => count($outcome['usages'] ?? []), 'http_status' => $httpStatus, ...$outcome];
        }
        return $result;
    }

    public function merge(int $fromId, int $toId, bool $apply = false, bool $retire = true): array
    {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) { throw new \InvalidArgumentException('Two distinct positive photo IDs required'); }
        $owned = !$this->pdo->inTransaction();
        if ($owned) { $this->pdo->beginTransaction(); } else { $this->pdo->exec('SAVEPOINT photos_merge'); }
        try {
            $this->lock([$fromId, $toId]);
            $from = $this->refs->find($fromId); $to = $this->refs->find($toId);
            if (!$from || !$to) { throw new \OutOfBoundsException('Source or target photo not found'); }
            if (!empty($to['is_inactive']) || !empty($to['is_retired'])) { throw new \DomainException('Keeper photo must be active and not retired before references can be moved to it.'); }
            $this->refs->assertMergeConflicts($from, $to);
            if ((int)($from['client_id'] ?? 0) !== (int)($to['client_id'] ?? 0)) {
                throw new \DomainException('Photos belong to different client access contexts. Resolve explicitly before merging.');
            }
            if (trim((string)($from['photo_permission_status'] ?? '')) !== trim((string)($to['photo_permission_status'] ?? ''))) {
                throw new \DomainException('Photos have different permission statuses. Resolve them explicitly before merging.');
            }
            $usage = new PhotoLibraryUsageService($this->photos);
            $describe = static fn(array $row, array $usages): array => ['title' => $row['title'] ?? '', 'source_type' => $row['source_type'] ?? '',
                'rel_path' => $row['rel_path'] ?? '', 'usage_count' => count($usages), 'usages' => $usages];
            $result = ['from_photo_library_id' => $fromId, 'to_photo_library_id' => $toId,
                'from' => $describe($from, $usage->getUsageSummary($fromId)), 'to' => $describe($to, $usage->getUsageSummary($toId)),
                'applied' => false, 'retired_source_row' => false,
                'note' => 'This merge moves DB usages to the keeper row and retires the duplicate photo_library row. It does not delete files from disk.'];
            if ($apply) {
                // Transfer associations only when the keeper has none; preserve all visibility flags.
                foreach (['palette_id', 'asset_library_id'] as $column) {
                    if (!empty($from[$column]) && empty($to[$column])) {
                        $this->pdo->prepare('UPDATE photo_library SET ' . $column . ' = ? WHERE photo_library_id = ?')->execute([$from[$column], $toId]);
                        if ($column === 'palette_id') { $this->pdo->prepare('UPDATE photo_library SET has_palette = 1 WHERE photo_library_id = ?')->execute([$toId]); }
                    }
                }
                $this->refs->reassign($fromId, $toId, PdoPhotoLifecycleRepository::path((string)$from['rel_path']), PdoPhotoLifecycleRepository::path((string)$to['rel_path']));
                if ($retire) { $this->pdo->prepare('UPDATE photo_library SET is_inactive = 1 WHERE photo_library_id = ?')->execute([$fromId]); }
                $result['applied'] = true; $result['retired_source_row'] = $retire;
            }
            if ($owned) { $this->pdo->commit(); } else { $this->pdo->exec('RELEASE SAVEPOINT photos_merge'); }
            return $result;
        } catch (Throwable $e) {
            if ($owned && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            elseif (!$owned) { $this->pdo->exec('ROLLBACK TO SAVEPOINT photos_merge'); $this->pdo->exec('RELEASE SAVEPOINT photos_merge'); }
            throw $e;
        }
    }
}
