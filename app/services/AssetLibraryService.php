<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoAssetLibraryRepository;
use RuntimeException;

final class AssetLibraryService
{
    public function __construct(
        private PdoAssetLibraryRepository $repo,
        private string $baseUrl = ''
    ) {}

    public function getAsset(int $assetLibraryId): ?array
    {
        $asset = $this->repo->findById($assetLibraryId);
        return $asset ? $this->withResolvedPaths($asset) : null;
    }

    public function listAssets(array $filters = []): array
    {
        return array_map(
            fn(array $asset): array => $this->withResolvedPaths($asset),
            $this->repo->list($filters)
        );
    }

    public function getAssetForLegacyPhoto(int $photoLibraryId): ?array
    {
        $asset = $this->repo->findByLegacyPhotoLibraryId($photoLibraryId);
        return $asset ? $this->withResolvedPaths($asset) : null;
    }

    public function createAsset(array $payload): array
    {
        $relPath = trim((string)($payload['rel_path'] ?? ''));
        if ($relPath === '') {
            throw new RuntimeException('rel_path required');
        }

        $payload['asset_kind'] = $this->normalizeAssetKind($payload['asset_kind'] ?? null, $relPath);
        $payload['mime_type'] = $payload['mime_type'] ?? $this->guessMimeType($relPath);
        $id = $this->repo->create($payload);
        $asset = $this->getAsset($id);
        if (!$asset) {
            throw new RuntimeException('Failed to create asset library row');
        }
        return $asset;
    }

    public function upsertAssetByPath(string $relPath, array $payload = []): array
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            throw new RuntimeException('rel_path required');
        }
        $payload['asset_kind'] = $this->normalizeAssetKind($payload['asset_kind'] ?? null, $relPath);
        $payload['mime_type'] = $payload['mime_type'] ?? $this->guessMimeType($relPath);
        $id = $this->repo->upsertByRelPath($relPath, $payload);
        $asset = $this->getAsset($id);
        if (!$asset) {
            throw new RuntimeException('Failed to upsert asset library row');
        }
        return $asset;
    }

    public function retireAsset(int $assetLibraryId): void
    {
        $this->repo->retire($assetLibraryId);
    }

    public function publicUrlForRelPath(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $relPath)) {
            return $relPath;
        }
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return $relPath;
        }
        return $base . '/' . ltrim($relPath, '/');
    }

    public function diskPathForRelPath(string $relPath, string $rootDir): string
    {
        $path = parse_url($relPath, PHP_URL_PATH);
        $path = $path !== false ? (string)$path : $relPath;
        return rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/');
    }

    private function withResolvedPaths(array $asset): array
    {
        $asset['library_asset_id'] = $asset['asset_library_id'];
        $asset['public_url'] = $this->publicUrlForRelPath((string)($asset['rel_path'] ?? ''));
        return $asset;
    }

    private function normalizeAssetKind(mixed $assetKind, string $relPath): string
    {
        $kind = strtolower(trim((string)($assetKind ?? '')));
        if (in_array($kind, ['image', 'video', 'document', 'audio', 'other'], true)) {
            return $kind;
        }
        $path = strtolower(parse_url($relPath, PHP_URL_PATH) ?: $relPath);
        return match (true) {
            preg_match('/\.(mp4|mov|webm|m4v)$/', $path) === 1 => 'video',
            preg_match('/\.(mp3|wav|m4a)$/', $path) === 1 => 'audio',
            preg_match('/\.(pdf|ppt|pptx|key)$/', $path) === 1 => 'document',
            default => 'image',
        };
    }

    private function guessMimeType(string $relPath): ?string
    {
        $path = strtolower(parse_url($relPath, PHP_URL_PATH) ?: $relPath);
        return match (true) {
            preg_match('/\.jpe?g$/', $path) === 1 => 'image/jpeg',
            preg_match('/\.png$/', $path) === 1 => 'image/png',
            preg_match('/\.gif$/', $path) === 1 => 'image/gif',
            preg_match('/\.webp$/', $path) === 1 => 'image/webp',
            preg_match('/\.mp4$/', $path) === 1 => 'video/mp4',
            preg_match('/\.mov$/', $path) === 1 => 'video/quicktime',
            preg_match('/\.webm$/', $path) === 1 => 'video/webm',
            preg_match('/\.mp3$/', $path) === 1 => 'audio/mpeg',
            preg_match('/\.wav$/', $path) === 1 => 'audio/wav',
            preg_match('/\.pdf$/', $path) === 1 => 'application/pdf',
            preg_match('/\.pptx$/', $path) === 1 => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => null,
        };
    }
}
