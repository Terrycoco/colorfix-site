<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPublishingDefaultTemplateRepository
{
    public function __construct(private PDO $pdo) {}

    public function findBest(string $platform, string $assetType, string $playlistType, string $fieldKey): ?array
    {
        $platform = $this->normalizeKey($platform, 'any');
        $assetType = $this->normalizeKey($assetType, 'any');
        $playlistType = $this->normalizePlaylistType($playlistType);
        $fieldKey = $this->normalizeKey($fieldKey, 'description');

        if (!$this->tableExists()) {
            return null;
        }

        $candidates = [
            [$platform, $assetType, $playlistType, $fieldKey],
            [$platform, $assetType, 'any', $fieldKey],
            [$platform, 'any', $playlistType, $fieldKey],
            [$platform, 'any', 'any', $fieldKey],
            ['any', $assetType, $playlistType, $fieldKey],
            ['any', $assetType, 'any', $fieldKey],
            ['any', 'any', 'any', $fieldKey],
        ];

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_default_templates
              WHERE is_active = 1
                AND platform = :platform
                AND asset_type = :asset_type
                AND playlist_type = :playlist_type
                AND field_key = :field_key
           ORDER BY updated_at DESC
              LIMIT 1'
        );

        foreach ($candidates as $rank => [$p, $a, $t, $f]) {
            $stmt->execute([
                ':platform' => $p,
                ':asset_type' => $a,
                ':playlist_type' => $t,
                ':field_key' => $f,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['match_rank'] = $rank + 1;
                return $row;
            }
        }
        return null;
    }

    public function upsert(string $platform, string $assetType, string $playlistType, string $fieldKey, string $templateText, ?string $label = null): array
    {
        $platform = $this->normalizeKey($platform, 'any');
        $assetType = $this->normalizeKey($assetType, 'any');
        $playlistType = $this->normalizePlaylistType($playlistType);
        $fieldKey = $this->normalizeKey($fieldKey, 'description');
        $label = $label !== null ? trim($label) : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO publishing_default_templates
                (platform, asset_type, playlist_type, field_key, label, template_text, is_active)
             VALUES
                (:platform, :asset_type, :playlist_type, :field_key, :label, :template_text, 1)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                template_text = VALUES(template_text),
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':platform' => $platform,
            ':asset_type' => $assetType,
            ':playlist_type' => $playlistType,
            ':field_key' => $fieldKey,
            ':label' => $label,
            ':template_text' => $templateText,
        ]);

        return $this->findBest($platform, $assetType, $playlistType, $fieldKey) ?? [
            'platform' => $platform,
            'asset_type' => $assetType,
            'playlist_type' => $playlistType,
            'field_key' => $fieldKey,
            'label' => $label,
            'template_text' => $templateText,
            'match_rank' => 1,
        ];
    }

    private function normalizePlaylistType(string $value): string
    {
        $value = $this->normalizeKey($value, 'any');
        return match ($value) {
            'palette' => 'palettes',
            'makeover' => 'makeovers',
            default => $value,
        };
    }

    private function normalizeKey(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\\-.]+/', '_', $value) ?? '';
        $value = trim($value, '_-.');
        return $value !== '' ? $value : $fallback;
    }

    private function tableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => 'publishing_default_templates']);
        $exists = (int)$stmt->fetchColumn() > 0;
        return $exists;
    }
}
