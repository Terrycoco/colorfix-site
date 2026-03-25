<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoLibraryRepository;

final class PhotoLibraryUsageService
{
    public function __construct(
        private PdoPhotoLibraryRepository $repo
    ) {}

    public function getUsageSummary(int $photoLibraryId): array
    {
        if ($photoLibraryId <= 0) {
            return [];
        }

        $rows = $this->repo->listUsages($photoLibraryId);
        return array_map(static function (array $row): array {
            return [
                'usage_type' => (string)($row['usage_type'] ?? ''),
                'ref_id' => isset($row['ref_id']) ? (int)$row['ref_id'] : null,
                'ref_title' => (string)($row['ref_title'] ?? ''),
                'label' => (string)($row['label'] ?? ''),
                'detail' => (string)($row['detail'] ?? ''),
                'admin_path' => (string)($row['admin_path'] ?? ''),
            ];
        }, $rows);
    }
}
