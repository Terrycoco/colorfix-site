<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoFileLockerRepository;
use InvalidArgumentException;

final class FileLockerService
{
    public const SLOT_KEY = 'photoshop_work';
    private const MAX_FILE_SIZE = 524288000; // 500 MB

    public function __construct(private PdoFileLockerRepository $repo) {}

    public function getCurrent(): ?array
    {
        $row = $this->repo->findBySlot(self::SLOT_KEY);
        if (!$row) {
            return null;
        }

        $path = $this->absolutePath((string)$row['stored_name']);
        return [
            'id' => (int)$row['id'],
            'slot_key' => (string)$row['slot_key'],
            'original_name' => (string)$row['original_name'],
            'stored_name' => (string)$row['stored_name'],
            'mime_type' => isset($row['mime_type']) ? (string)$row['mime_type'] : '',
            'file_size' => (int)($row['file_size'] ?? 0),
            'note' => isset($row['note']) ? (string)$row['note'] : '',
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
            'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
            'exists' => is_file($path),
        ];
    }

    public function replace(array $file, ?string $note = null): array
    {
        $this->ensureStorageReady();
        $this->validateUpload($file);

        $existing = $this->repo->findBySlot(self::SLOT_KEY);
        $originalName = (string)($file['name'] ?? 'working-file');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = $extension !== ''
            ? sprintf('%s-%s.%s', self::SLOT_KEY, bin2hex(random_bytes(8)), $extension)
            : sprintf('%s-%s', self::SLOT_KEY, bin2hex(random_bytes(8)));
        $destination = $this->absolutePath($storedName);

        if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
            throw new InvalidArgumentException('Failed to move uploaded file');
        }

        $this->repo->upsert(self::SLOT_KEY, [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => (string)($file['type'] ?? 'application/octet-stream'),
            'file_size' => (int)($file['size'] ?? 0),
            'note' => $this->normalizeNote($note),
        ]);

        if ($existing && !empty($existing['stored_name']) && $existing['stored_name'] !== $storedName) {
            $oldPath = $this->absolutePath((string)$existing['stored_name']);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return $this->getCurrent() ?? [];
    }

    public function deleteCurrent(): void
    {
        $existing = $this->repo->findBySlot(self::SLOT_KEY);
        if ($existing && !empty($existing['stored_name'])) {
            $path = $this->absolutePath((string)$existing['stored_name']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->repo->deleteBySlot(self::SLOT_KEY);
    }

    public function getDownloadInfo(): ?array
    {
        $current = $this->getCurrent();
        if (!$current || !$current['exists']) {
            return null;
        }

        return [
            'path' => $this->absolutePath((string)$current['stored_name']),
            'download_name' => (string)$current['original_name'],
            'mime_type' => (string)($current['mime_type'] ?: 'application/octet-stream'),
            'file_size' => (int)$current['file_size'],
        ];
    }

    private function validateUpload(array $file): void
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload failed');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new InvalidArgumentException('Uploaded file is empty');
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File is larger than the 500 MB limit');
        }
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Invalid upload payload');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $value = trim((string)$note);
        return $value !== '' ? $value : null;
    }

    private function ensureStorageReady(): void
    {
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new InvalidArgumentException('Failed to create file locker directory');
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        $index = $dir . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    private function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/file-locker';
    }

    private function absolutePath(string $storedName): string
    {
        return $this->storageDir() . '/' . ltrim($storedName, '/');
    }
}
