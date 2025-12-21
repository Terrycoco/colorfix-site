<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoRepository;
use RuntimeException;

final class MaskOverlayService
{
    private const BASE_BUCKETS = [
        'darkMax' => 45.0,
        'lightMin' => 88.0,
    ];

    private const GRID = [
        'dark' => [
            'dark' => ['mode' => 'overlay',   'opacity' => 0.15],
            'medium' => ['mode' => 'softlight','opacity' => 0.4],
            'light' => ['mode' => 'overlay',  'opacity' => 0.35],
        ],
        'medium' => [
            'dark' => ['mode' => 'multiply',  'opacity' => 0.35],
            'medium' => ['mode' => 'softlight','opacity' => 0.35],
            'light' => ['mode' => 'softlight','opacity' => 0.3],
        ],
        'light' => [
            'dark' => ['mode' => 'multiply',  'opacity' => 0.45],
            'medium' => ['mode' => 'overlay', 'opacity' => 0.3],
            'light' => ['mode' => 'softlight','opacity' => 0.3],
        ],
    ];

    public function __construct(
        private PdoPhotoRepository $repo,
        private \PDO $pdo
    ) {}

    public function applyDefaultsForAsset(string $assetId, bool $force = false, bool $ensureStats = true): array
    {
        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new RuntimeException('asset_id required');
        }

        $photo = $this->repo->getPhotoByAssetId($assetId);
        if (!$photo) {
            throw new RuntimeException("asset not found: {$assetId}");
        }
        $photoId = (int)$photo['id'];

        $variants = $this->repo->listVariants($photoId);
        $maskVariants = array_values(array_filter($variants, function (array $v): bool {
            return ($v['kind'] ?? '') === 'masks' && !empty($v['role']);
        }));
        if (!$maskVariants) {
            return ['asset_id' => $assetId, 'updated' => 0, 'skipped' => 0, 'note' => 'no masks'];
        }

        $statsMap = $this->statsByRole($photoId);
        if ($ensureStats) {
            $missing = [];
            foreach ($maskVariants as $v) {
                $role = (string)($v['role'] ?? '');
                if ($role === '') continue;
                if (!isset($statsMap[$role])) $missing[] = $role;
            }
            if ($missing) {
                try {
                    $svc = new PhotoRenderingService($this->repo, $this->pdo);
                    $svc->recalcLmForRoles($assetId, $missing);
                } catch (\Throwable $e) {
                    // If stats fail, continue with what we have.
                }
                $statsMap = $this->statsByRole($photoId);
            }
        }

        $updated = 0;
        $skipped = 0;

        foreach ($maskVariants as $variant) {
            $role = (string)($variant['role'] ?? '');
            if ($role === '') continue;

            $baseL = isset($statsMap[$role]['l_avg01']) ? (float)$statsMap[$role]['l_avg01'] : null;
            if ($baseL === null) {
                $skipped++;
                continue;
            }

            if (!$force && $this->hasOverlayValues($variant)) {
                $skipped++;
                continue;
            }

            $overlay = $this->overlayForBaseLightness($baseL);
            $ok = $this->repo->updateMaskOverlay(
                $photoId,
                $role,
                $overlay,
                $variant['original_texture'] ?? null
            );
            if ($ok) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        return [
            'asset_id' => $assetId,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function statsByRole(int $photoId): array
    {
        $rows = $this->repo->getRoleStats($photoId);
        $map = [];
        foreach ($rows as $row) {
            $role = (string)($row['role'] ?? '');
            if ($role !== '') $map[$role] = $row;
        }
        return $map;
    }

    private function hasOverlayValues(array $variant): bool
    {
        $fields = [
            'overlay_mode_dark','overlay_opacity_dark',
            'overlay_mode_medium','overlay_opacity_medium',
            'overlay_mode_light','overlay_opacity_light',
            'overlay_shadow_l_offset','overlay_shadow_tint','overlay_shadow_tint_opacity',
        ];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $variant)) continue;
            $val = $variant[$field];
            if ($val !== null && $val !== '') return true;
        }
        return false;
    }

    private function overlayForBaseLightness(float $baseL): array
    {
        $baseBucket = $this->bucketForLightness($baseL);
        $tiers = ['dark','medium','light'];
        $out = [];
        foreach ($tiers as $tier) {
            $preset = self::GRID[$baseBucket][$tier] ?? null;
            $out[$tier] = [
                'mode' => $preset['mode'] ?? null,
                'opacity' => $preset['opacity'] ?? null,
            ];
        }
        $out['_shadow'] = [
            'l_offset' => 0,
            'tint_hex' => null,
            'tint_opacity' => 0,
        ];
        return $out;
    }

    private function bucketForLightness(float $value): string
    {
        if ($value < self::BASE_BUCKETS['darkMax']) return 'dark';
        if ($value >= self::BASE_BUCKETS['lightMin']) return 'light';
        return 'medium';
    }
}
