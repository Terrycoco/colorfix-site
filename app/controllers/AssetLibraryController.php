<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AssetLibraryService;

final class AssetLibraryController
{
    public function __construct(private AssetLibraryService $service) {}

    public function list(array $query): array
    {
        return $this->service->listAssets([
            'q' => $query['q'] ?? '',
            'asset_kind' => $query['asset_kind'] ?? '',
            'source_type' => $query['source_type'] ?? '',
            'include_inactive' => !empty($query['include_inactive']) && $query['include_inactive'] !== '0',
            'inactive_only' => !empty($query['inactive_only']) && $query['inactive_only'] !== '0',
            'sort' => $query['sort'] ?? 'newest',
            'limit' => isset($query['limit']) ? (int)$query['limit'] : 100,
            'offset' => isset($query['offset']) ? (int)$query['offset'] : 0,
        ]);
    }

    public function hardDeleteUnpublished(int $assetLibraryId, string $rootDir): array
    {
        return $this->service->hardDeleteUnpublishedAsset($assetLibraryId, $rootDir);
    }

    public function hardDeleteUnpublishedGenerated(array $assetLibraryIds, string $rootDir): array
    {
        return $this->service->hardDeleteUnpublishedAssets($assetLibraryIds, $rootDir);
    }
}
