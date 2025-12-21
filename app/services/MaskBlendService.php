<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoMaskBlendSettingRepository;
use App\Repos\PdoPhotoRepository;
use RuntimeException;

final class MaskBlendService
{
    public function __construct(
        private PdoMaskBlendSettingRepository $repo,
        private PdoPhotoRepository $photoRepo
    ) {}

    public function listSettings(string $assetId, string $maskRole): array
    {
        $photo = $this->requirePhoto($assetId);
        $statsMap = $this->roleStatsMap((int)$photo['id']);
        $baseInfo = $statsMap[$maskRole] ?? null;
        $baseLightness = $baseInfo ? (float)$baseInfo['l_avg01'] : null;

        $rows = $this->repo->listForMask((int)$photo['id'], $maskRole);
        $normalized = array_map(function ($row) {
            return $this->normalizeRow($row);
        }, $rows);

        return [
            'photo_id' => (int)$photo['id'],
            'asset_id' => $assetId,
            'mask_role' => $maskRole,
            'base_lightness' => $baseLightness,
            'settings' => $normalized,
        ];
    }

    public function saveSetting(string $assetId, string $maskRole, array $payload): array
    {
        $photo = $this->requirePhoto($assetId);
        $baseLightness = $this->resolveBaseLightness((int)$photo['id'], $maskRole, $payload['base_lightness'] ?? null);
        $existingRow = null;
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id > 0) {
            $existingRow = $this->repo->findById($id);
        }

        $approved = array_key_exists('approved', $payload)
            ? (int)$payload['approved']
            : ($existingRow['approved'] ?? 0);

        $shadowTint = $this->normalizeShadowTint($payload['shadow_tint_hex'] ?? null);
        $shadowOffset = isset($payload['shadow_l_offset']) ? (float)$payload['shadow_l_offset'] : null;
        if ($shadowOffset !== null) {
            $shadowOffset = max(-50.0, min(50.0, $shadowOffset));
        }
        $shadowOpacity = isset($payload['shadow_tint_opacity']) ? (float)$payload['shadow_tint_opacity'] : null;
        if ($shadowOpacity !== null) {
            $shadowOpacity = max(0.0, min(1.0, $shadowOpacity));
        }

        $data = [
            'photo_id' => (int)$photo['id'],
            'asset_id' => $assetId,
            'mask_role' => $maskRole,
            'color_id' => $payload['color_id'] ?? null,
            'color_name' => $payload['color_name'] ?? null,
            'color_brand' => $payload['color_brand'] ?? null,
            'color_code' => $payload['color_code'] ?? null,
            'color_hex' => strtoupper($payload['color_hex'] ?? ''),
            'base_lightness' => $this->roundLightness($baseLightness),
            'blend_mode' => strtolower($payload['blend_mode'] ?? 'colorize'),
            'blend_opacity' => (float)$payload['blend_opacity'],
            'shadow_l_offset' => $shadowOffset,
            'shadow_tint_hex' => $shadowTint,
            'shadow_tint_opacity' => $shadowOpacity,
            'is_preset' => (int)($payload['is_preset'] ?? 0),
            'approved' => $approved,
            'notes' => $payload['notes'] ?? null,
        ];

        if ($existingRow && $this->rowsMatch($existingRow, $data)) {
            return $this->normalizeRow($existingRow);
        }

        $row = $id > 0
            ? $this->repo->update($id, $data)
            : $this->repo->insert($data);

        if (!$row) throw new RuntimeException('Failed to save blend setting');
        return $this->normalizeRow($row);
    }

    public function deleteSetting(string $assetId, string $maskRole, int $id): void
    {
        $photo = $this->requirePhoto($assetId);
        $this->repo->delete($id, (int)$photo['id'], $maskRole);
    }

    public function findBestSetting(string $assetId, string $maskRole, float $baseLightness, float $targetLightness): ?array
    {
        $photo = $this->requirePhoto($assetId);
        $row = $this->repo->findClosest(
            (int)$photo['id'],
            $maskRole,
            $this->roundLightness($baseLightness),
            $this->roundLightness($targetLightness)
        );
        return $row ? $this->normalizeRow($row) : null;
    }

    private function requirePhoto(string $assetId): array
    {
        $photo = $this->photoRepo->getPhotoByAssetId($assetId);
        if (!$photo) {
            throw new RuntimeException("asset not found: {$assetId}");
        }
        return $photo;
    }

    private function resolveBaseLightness(int $photoId, string $role, ?float $input): float
    {
        if ($input !== null) {
            return (float)$input;
        }
        $stats = $this->roleStatsMap($photoId);
        if (!isset($stats[$role])) {
            throw new RuntimeException("missing base lightness for {$role}");
        }
        return (float)$stats[$role]['l_avg01'];
    }

    private function roleStatsMap(int $photoId): array
    {
        $rows = $this->photoRepo->getRoleStats($photoId);
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['role']] = $row;
        }
        return $map;
    }

    private function normalizeRow(array $row): array
    {
        $targetLightness = null;
        if (array_key_exists('target_lightness_live', $row) && $row['target_lightness_live'] !== null) {
            $targetLightness = (float)$row['target_lightness_live'];
        } elseif (array_key_exists('target_lightness', $row) && $row['target_lightness'] !== null) {
            $targetLightness = (float)$row['target_lightness'];
        }

        $targetH = null;
        if (array_key_exists('target_h_live', $row) && $row['target_h_live'] !== null) {
            $targetH = (float)$row['target_h_live'];
        } elseif (array_key_exists('target_h', $row) && $row['target_h'] !== null) {
            $targetH = (float)$row['target_h'];
        }

        $targetC = null;
        if (array_key_exists('target_c_live', $row) && $row['target_c_live'] !== null) {
            $targetC = (float)$row['target_c_live'];
        } elseif (array_key_exists('target_c', $row) && $row['target_c'] !== null) {
            $targetC = (float)$row['target_c'];
        }

        $baseBucket = $this->bucketForLightness((float)$row['base_lightness']);
        $targetBucket = $targetLightness !== null ? $this->bucketForLightness($targetLightness) : $baseBucket;
        return [
            'id' => (int)$row['id'],
            'photo_id' => (int)$row['photo_id'],
            'asset_id' => $row['asset_id'],
            'mask_role' => $row['mask_role'],
            'color_id' => $row['color_id'] !== null ? (int)$row['color_id'] : null,
            'color_name' => $row['color_name'],
            'color_brand' => $row['color_brand'],
            'color_code' => $row['color_code'],
            'color_hex' => strtoupper($row['color_hex']),
            'base_lightness' => (float)$row['base_lightness'],
            'target_lightness' => $targetLightness,
            'target_h' => $targetH,
            'target_c' => $targetC,
            'blend_mode' => $row['blend_mode'],
            'blend_opacity' => (float)$row['blend_opacity'],
            'shadow_l_offset' => isset($row['shadow_l_offset']) ? (float)$row['shadow_l_offset'] : null,
            'shadow_tint_hex' => $this->formatShadowTint($row['shadow_tint_hex'] ?? null),
            'shadow_tint_opacity' => isset($row['shadow_tint_opacity']) ? (float)$row['shadow_tint_opacity'] : null,
            'is_preset' => (bool)$row['is_preset'],
            'approved' => isset($row['approved']) ? (bool)$row['approved'] : false,
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'base_bucket' => $baseBucket,
            'target_bucket' => $targetBucket,
        ];
    }

    private function rowsMatch(array $existing, array $next): bool
    {
        $keys = [
            'color_id',
            'color_name',
            'color_brand',
            'color_code',
            'color_hex',
            'base_lightness',
            'blend_mode',
            'blend_opacity',
            'shadow_l_offset',
            'shadow_tint_hex',
            'shadow_tint_opacity',
            'is_preset',
            'approved',
            'notes',
        ];
        foreach ($keys as $key) {
            $left = $existing[$key] ?? null;
            $right = $next[$key] ?? null;
            if (is_numeric($left) && is_numeric($right)) {
                if ((float)$left !== (float)$right) return false;
                continue;
            }
            if ($left !== $right) return false;
        }
        return true;
    }

    private function bucketForLightness(float $value): string
    {
        if ($value < 45) return 'dark';
        if ($value >= 88) return 'light';
        return 'medium';
    }

    private function roundLightness(float $value): float
    {
        return (float)round($value, 0);
    }

    private function normalizeShadowTint($value): ?string
    {
        if (!is_string($value)) return null;
        $trim = strtoupper(trim($value));
        $trim = ltrim($trim, '#');
        if (strlen($trim) === 3) {
            $trim = $trim[0].$trim[0].$trim[1].$trim[1].$trim[2].$trim[2];
        }
        if (!preg_match('/^[0-9A-F]{6}$/', $trim)) return null;
        return $trim;
    }

    private function formatShadowTint($value): ?string
    {
        if (!is_string($value) || $value === '') return null;
        $trim = strtoupper(ltrim($value, '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $trim)) return null;
        return '#' . $trim;
    }
}
