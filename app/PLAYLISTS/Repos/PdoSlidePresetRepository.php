<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Repos;

use PDO;

final class PdoSlidePresetRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(
        bool $includeDisabled = true
    ): array {
        $where =
            $includeDisabled
                ? ''
                : 'WHERE is_enabled = 1';

        $stmt =
            $this->pdo->query(
                "SELECT
                    preset_key,
                    label,
                    item_type,
                    insert_position,
                    default_title,
                    default_subtitle,
                    default_subtitle_2,
                    default_layout,
                    default_title_mode,
                    default_star,
                    default_transition,
                    default_duration_ms,
                    default_is_active,
                    default_exclude_from_thumbs,
                    default_is_share_image,
                    default_site,
                    default_yt,
                    default_pin,
                    default_concept,
                    default_client,
                    default_analyzer_role,
                    default_finder_start,
                    default_version_number,
                    default_is_final,
                    sort_order,
                    is_enabled,
                    created_at,
                    updated_at
                 FROM playlist_slide_presets
                 {$where}
                 ORDER BY sort_order ASC, label ASC, preset_key ASC"
            );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(
        string $presetKey
    ): ?array {
        $stmt =
            $this->pdo->prepare(
                'SELECT
                    preset_key,
                    label,
                    item_type,
                    insert_position,
                    default_title,
                    default_subtitle,
                    default_subtitle_2,
                    default_layout,
                    default_title_mode,
                    default_star,
                    default_transition,
                    default_duration_ms,
                    default_is_active,
                    default_exclude_from_thumbs,
                    default_is_share_image,
                    default_site,
                    default_yt,
                    default_pin,
                    default_concept,
                    default_client,
                    default_analyzer_role,
                    default_finder_start,
                    default_version_number,
                    default_is_final,
                    sort_order,
                    is_enabled,
                    created_at,
                    updated_at
                 FROM playlist_slide_presets
                 WHERE preset_key = :preset_key
                 LIMIT 1'
            );

        $stmt->execute([
            ':preset_key' =>
                trim($presetKey),
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function save(
        array $row
    ): array {
        $sql = <<<'SQL'
            INSERT INTO playlist_slide_presets (
                preset_key,
                label,
                item_type,
                insert_position,
                default_title,
                default_subtitle,
                default_subtitle_2,
                default_layout,
                default_title_mode,
                default_star,
                default_transition,
                default_duration_ms,
                default_is_active,
                default_exclude_from_thumbs,
                default_is_share_image,
                default_site,
                default_yt,
                default_pin,
                default_concept,
                default_client,
                default_analyzer_role,
                default_finder_start,
                default_version_number,
                default_is_final,
                sort_order,
                is_enabled
            ) VALUES (
                :preset_key,
                :label,
                :item_type,
                :insert_position,
                :default_title,
                :default_subtitle,
                :default_subtitle_2,
                :default_layout,
                :default_title_mode,
                :default_star,
                :default_transition,
                :default_duration_ms,
                :default_is_active,
                :default_exclude_from_thumbs,
                :default_is_share_image,
                :default_site,
                :default_yt,
                :default_pin,
                :default_concept,
                :default_client,
                :default_analyzer_role,
                :default_finder_start,
                :default_version_number,
                :default_is_final,
                :sort_order,
                :is_enabled
            )
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                item_type = VALUES(item_type),
                insert_position = VALUES(insert_position),
                default_title = VALUES(default_title),
                default_subtitle = VALUES(default_subtitle),
                default_subtitle_2 = VALUES(default_subtitle_2),
                default_layout = VALUES(default_layout),
                default_title_mode = VALUES(default_title_mode),
                default_star = VALUES(default_star),
                default_transition = VALUES(default_transition),
                default_duration_ms = VALUES(default_duration_ms),
                default_is_active = VALUES(default_is_active),
                default_exclude_from_thumbs = VALUES(default_exclude_from_thumbs),
                default_is_share_image = VALUES(default_is_share_image),
                default_site = VALUES(default_site),
                default_yt = VALUES(default_yt),
                default_pin = VALUES(default_pin),
                default_concept = VALUES(default_concept),
                default_client = VALUES(default_client),
                default_analyzer_role = VALUES(default_analyzer_role),
                default_finder_start = VALUES(default_finder_start),
                default_version_number = VALUES(default_version_number),
                default_is_final = VALUES(default_is_final),
                sort_order = VALUES(sort_order),
                is_enabled = VALUES(is_enabled),
                updated_at = CURRENT_TIMESTAMP
            SQL;

        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute(
            $row
        );

        return $this->findByKey(
            (string)$row[':preset_key']
        ) ?? [];
    }
}
