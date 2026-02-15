<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PlaylistInstance;
use PDO;

final class PdoPlaylistInstanceRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getById(int $id): ?PlaylistInstance
    {
        $sql = <<<SQL
            SELECT
              playlist_instance_id,
              playlist_id,
              instance_name,
              display_title,
              instance_notes,
              intro_layout,
              intro_title,
              intro_subtitle,
              intro_body,
              intro_image_url,
              cta_group_id,
              palette_viewer_cta_group_id,
              demo_enabled,
              cta_context_key,
              audience,
              cta_overrides,
              share_enabled,
              share_title,
              share_description,
              share_image_url,
              skip_intro_on_replay,
              hide_stars,
              is_active,
              created_from_instance,
              kicker_id
            FROM playlist_instances
            WHERE playlist_instance_id = :id
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return new PlaylistInstance(
            (int)$row['playlist_instance_id'],
            (int)$row['playlist_id'],
            (string)$row['instance_name'],
            $row['display_title'] !== null ? (string)$row['display_title'] : null,
            $row['instance_notes'] !== null ? (string)$row['instance_notes'] : null,
            (string)$row['intro_layout'],
            $row['intro_title'] !== null ? (string)$row['intro_title'] : null,
            $row['intro_subtitle'] !== null ? (string)$row['intro_subtitle'] : null,
            $row['intro_body'] !== null ? (string)$row['intro_body'] : null,
            $row['intro_image_url'] !== null ? (string)$row['intro_image_url'] : null,
            $row['cta_group_id'] !== null ? (int)$row['cta_group_id'] : null,
            $row['palette_viewer_cta_group_id'] !== null ? (int)$row['palette_viewer_cta_group_id'] : null,
            (bool)$row['demo_enabled'],
            $row['cta_context_key'] !== null ? (string)$row['cta_context_key'] : null,
            $row['audience'] !== null ? (string)$row['audience'] : null,
            $row['cta_overrides'] !== null ? (string)$row['cta_overrides'] : null,
            (bool)$row['share_enabled'],
            $row['share_title'] !== null ? (string)$row['share_title'] : null,
            $row['share_description'] !== null ? (string)$row['share_description'] : null,
            $row['share_image_url'] !== null ? (string)$row['share_image_url'] : null,
            (bool)$row['skip_intro_on_replay'],
            (bool)$row['hide_stars'],
            (bool)$row['is_active'],
            $row['created_from_instance'] !== null ? (int)$row['created_from_instance'] : null,
            $row['kicker_id'] !== null ? (int)$row['kicker_id'] : null
        );
    }

    public function insert(PlaylistInstance $instance): PlaylistInstance
    {
        $sql = <<<SQL
            INSERT INTO playlist_instances (
            playlist_id,
            instance_name,
            display_title,
            instance_notes,
            intro_layout,
            intro_title,
            intro_subtitle,
            intro_body,
            intro_image_url,
            cta_group_id,
            palette_viewer_cta_group_id,
            demo_enabled,
            cta_context_key,
            audience,
            cta_overrides,
            share_enabled,
            share_title,
            share_description,
            share_image_url,
            skip_intro_on_replay,
            hide_stars,
            is_active,
            created_from_instance,
            kicker_id
            ) VALUES (
            :playlist_id,
            :instance_name,
            :display_title,
            :instance_notes,
            :intro_layout,
            :intro_title,
            :intro_subtitle,
            :intro_body,
            :intro_image_url,
            :cta_group_id,
            :palette_viewer_cta_group_id,
            :demo_enabled,
            :cta_context_key,
            :audience,
            :cta_overrides,
            :share_enabled,
            :share_title,
            :share_description,
            :share_image_url,
            :skip_intro_on_replay,
            :hide_stars,
            :is_active,
            :created_from_instance,
            :kicker_id
            )
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'playlist_id' => $instance->playlistId,
            'instance_name' => $instance->instanceName,
            'display_title' => $instance->displayTitle,
            'instance_notes' => $instance->instanceNotes,
            'intro_layout' => $instance->introLayout,
            'intro_title' => $instance->introTitle,
            'intro_subtitle' => $instance->introSubtitle,
            'intro_body' => $instance->introBody,
            'intro_image_url' => $instance->introImageUrl,
            'cta_group_id' => $instance->ctaGroupId,
            'palette_viewer_cta_group_id' => $instance->paletteViewerCtaGroupId,
            'demo_enabled' => $instance->demoEnabled ? 1 : 0,
            'cta_context_key' => $instance->ctaContextKey,
            'audience' => $instance->audience,
            'cta_overrides' => $instance->ctaOverrides,
            'share_enabled' => $instance->shareEnabled ? 1 : 0,
            'share_title' => $instance->shareTitle,
            'share_description' => $instance->shareDescription,
            'share_image_url' => $instance->shareImageUrl,
            'skip_intro_on_replay' => $instance->skipIntroOnReplay ? 1 : 0,
            'hide_stars' => $instance->hideStars ? 1 : 0,
            'is_active' => $instance->isActive ? 1 : 0,
            'created_from_instance' => $instance->createdFromInstance,
            'kicker_id' => $instance->kickerId,
        ]);

        $instance->id = (int)$this->pdo->lastInsertId();
        return $instance;
    }

    public function update(PlaylistInstance $instance): void
    {
        if ($instance->id === null) {
            throw new \RuntimeException('Cannot update playlist instance without id');
        }

        $sql = <<<SQL
            UPDATE playlist_instances
            SET
            playlist_id = :playlist_id,
            instance_name = :instance_name,
            display_title = :display_title,
            instance_notes = :instance_notes,
            intro_layout = :intro_layout,
            intro_title = :intro_title,
            intro_subtitle = :intro_subtitle,
            intro_body = :intro_body,
            intro_image_url = :intro_image_url,
            cta_group_id = :cta_group_id,
            palette_viewer_cta_group_id = :palette_viewer_cta_group_id,
            demo_enabled = :demo_enabled,
            cta_context_key = :cta_context_key,
            audience = :audience,
            cta_overrides = :cta_overrides,
            share_enabled = :share_enabled,
            share_title = :share_title,
            share_description = :share_description,
            share_image_url = :share_image_url,
            skip_intro_on_replay = :skip_intro_on_replay,
            hide_stars = :hide_stars,
            is_active = :is_active,
            created_from_instance = :created_from_instance,
            kicker_id = :kicker_id
            WHERE playlist_instance_id = :id
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $instance->id,
            'playlist_id' => $instance->playlistId,
            'instance_name' => $instance->instanceName,
            'display_title' => $instance->displayTitle,
            'instance_notes' => $instance->instanceNotes,
            'intro_layout' => $instance->introLayout,
            'intro_title' => $instance->introTitle,
            'intro_subtitle' => $instance->introSubtitle,
            'intro_body' => $instance->introBody,
            'intro_image_url' => $instance->introImageUrl,
            'cta_group_id' => $instance->ctaGroupId,
            'palette_viewer_cta_group_id' => $instance->paletteViewerCtaGroupId,
            'demo_enabled' => $instance->demoEnabled ? 1 : 0,
            'cta_context_key' => $instance->ctaContextKey,
            'audience' => $instance->audience,
            'cta_overrides' => $instance->ctaOverrides,
            'share_enabled' => $instance->shareEnabled ? 1 : 0,
            'share_title' => $instance->shareTitle,
            'share_description' => $instance->shareDescription,
            'share_image_url' => $instance->shareImageUrl,
            'skip_intro_on_replay' => $instance->skipIntroOnReplay ? 1 : 0,
            'hide_stars' => $instance->hideStars ? 1 : 0,
            'is_active' => $instance->isActive ? 1 : 0,
            'created_from_instance' => $instance->createdFromInstance,
            'kicker_id' => $instance->kickerId,
        ]);
    }

    public function save(PlaylistInstance $instance): PlaylistInstance
    {
        if ($instance->id === null) {
            return $this->insert($instance);
        }

        $this->update($instance);
        return $instance;
    }

    /**
     * @return PlaylistInstance[]
     */
    public function listAll(bool $onlyActive = false): array
    {
        $sql = <<<SQL
            SELECT
              playlist_instance_id,
              playlist_id,
              instance_name,
              display_title,
              instance_notes,
              intro_layout,
              intro_title,
              intro_subtitle,
              intro_body,
              intro_image_url,
              cta_group_id,
              palette_viewer_cta_group_id,
              demo_enabled,
              cta_context_key,
              audience,
              cta_overrides,
              share_enabled,
              share_title,
              share_description,
              share_image_url,
              skip_intro_on_replay,
              hide_stars,
              is_active,
              created_from_instance,
              kicker_id
            FROM playlist_instances
            SQL;

        if ($onlyActive) {
            $sql .= "\nWHERE is_active = 1";
        }

        $sql .= "\nORDER BY playlist_instance_id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        $instances = [];
        foreach ($rows as $row) {
            $instances[] = new PlaylistInstance(
                (int)$row['playlist_instance_id'],
                (int)$row['playlist_id'],
                (string)$row['instance_name'],
                $row['display_title'] !== null ? (string)$row['display_title'] : null,
                $row['instance_notes'] !== null ? (string)$row['instance_notes'] : null,
                (string)$row['intro_layout'],
                $row['intro_title'] !== null ? (string)$row['intro_title'] : null,
                $row['intro_subtitle'] !== null ? (string)$row['intro_subtitle'] : null,
                $row['intro_body'] !== null ? (string)$row['intro_body'] : null,
                $row['intro_image_url'] !== null ? (string)$row['intro_image_url'] : null,
                $row['cta_group_id'] !== null ? (int)$row['cta_group_id'] : null,
                $row['palette_viewer_cta_group_id'] !== null ? (int)$row['palette_viewer_cta_group_id'] : null,
                (bool)$row['demo_enabled'],
                $row['cta_context_key'] !== null ? (string)$row['cta_context_key'] : null,
                $row['audience'] !== null ? (string)$row['audience'] : null,
                $row['cta_overrides'] !== null ? (string)$row['cta_overrides'] : null,
                (bool)$row['share_enabled'],
                $row['share_title'] !== null ? (string)$row['share_title'] : null,
                $row['share_description'] !== null ? (string)$row['share_description'] : null,
                $row['share_image_url'] !== null ? (string)$row['share_image_url'] : null,
                (bool)$row['skip_intro_on_replay'],
                (bool)$row['hide_stars'],
                (bool)$row['is_active'],
                $row['created_from_instance'] !== null ? (int)$row['created_from_instance'] : null,
                $row['kicker_id'] !== null ? (int)$row['kicker_id'] : null
            );
        }

        return $instances;
    }
}
