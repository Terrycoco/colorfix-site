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
              slug,
              instance_name,
              display_title,
              display_subtitle,
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
              player_experience_id,
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
            $row['display_subtitle'] !== null ? (string)$row['display_subtitle'] : null,
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
            $row['kicker_id'] !== null ? (int)$row['kicker_id'] : null,
            $row['slug'] !== null ? (string)$row['slug'] : null,
            $row['player_experience_id'] !== null ? (int)$row['player_experience_id'] : null
        );
    }

    public function findIdBySlug(string $slug): ?int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT playlist_instance_id
             FROM playlist_instances
             WHERE slug = :slug
               AND is_active = 1
               AND share_enabled = 1
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function insert(PlaylistInstance $instance): PlaylistInstance
    {
        $slug = $this->resolveUniqueSlug($instance);
        $sql = <<<SQL
            INSERT INTO playlist_instances (
            playlist_id,
            slug,
            instance_name,
            display_title,
            display_subtitle,
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
            player_experience_id,
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
            :slug,
            :instance_name,
            :display_title,
            :display_subtitle,
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
            :player_experience_id,
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
            'slug' => $slug,
            'instance_name' => $instance->instanceName,
            'display_title' => $instance->displayTitle,
            'display_subtitle' => $instance->displaySubtitle,
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
            'player_experience_id' => $instance->playerExperienceId,
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
        $instance->slug = $slug;
        return $instance;
    }

    public function update(PlaylistInstance $instance): void
    {
        if ($instance->id === null) {
            throw new \RuntimeException('Cannot update playlist instance without id');
        }
        $slug = $this->resolveUniqueSlug($instance);

        $sql = <<<SQL
            UPDATE playlist_instances
            SET
            playlist_id = :playlist_id,
            slug = :slug,
            instance_name = :instance_name,
            display_title = :display_title,
            display_subtitle = :display_subtitle,
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
            player_experience_id = :player_experience_id,
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
            'slug' => $slug,
            'instance_name' => $instance->instanceName,
            'display_title' => $instance->displayTitle,
            'display_subtitle' => $instance->displaySubtitle,
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
            'player_experience_id' => $instance->playerExperienceId,
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
        $instance->slug = $slug;
    }

    public function save(PlaylistInstance $instance): PlaylistInstance
    {
        if ($instance->id === null) {
            return $this->insert($instance);
        }

        $this->update($instance);
        return $instance;
    }

    public function deleteIfUnlocked(int $instanceId): array
    {
        if ($instanceId <= 0) {
            throw new \InvalidArgumentException('playlist_instance_id required');
        }

        $instance = $this->getById($instanceId);
        if (!$instance) {
            throw new \RuntimeException('Playlist instance not found');
        }

        $blocking = $this->deleteBlockers($instanceId);
        if ($blocking !== []) {
            return [
                'deleted' => false,
                'playlist_instance_id' => $instanceId,
                'blocked' => true,
                'blockers' => $blocking,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $deletedLandingPages = 0;
            if ($this->tableExists('landing_pages')) {
                $stmt = $this->pdo->prepare(
                    "DELETE FROM landing_pages
                      WHERE primary_playlist_instance_id = :id
                        AND status <> 'public'"
                );
                $stmt->execute(['id' => $instanceId]);
                $deletedLandingPages = $stmt->rowCount();
            }

            $deletedSetItems = 0;
            if ($this->tableExists('playlist_instance_set_items') && $this->columnExists('playlist_instance_set_items', 'playlist_instance_id')) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM playlist_instance_set_items
                      WHERE playlist_instance_id = :id'
                );
                $stmt->execute(['id' => $instanceId]);
                $deletedSetItems = $stmt->rowCount();
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM playlist_instances
                  WHERE playlist_instance_id = :id
                  LIMIT 1'
            );
            $stmt->execute(['id' => $instanceId]);
            $deletedInstances = $stmt->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'deleted' => $deletedInstances > 0,
            'playlist_instance_id' => $instanceId,
            'deleted_landing_pages' => $deletedLandingPages,
            'deleted_set_items' => $deletedSetItems,
        ];
    }

    private function deleteBlockers(int $instanceId): array
    {
        $blockers = [];

        if ($this->tableExists('landing_pages')) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM landing_pages
                  WHERE primary_playlist_instance_id = :id
                    AND status = 'public'"
            );
            $stmt->execute(['id' => $instanceId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = "Linked to {$count} public landing page" . ($count === 1 ? '' : 's');
            }
        }

        if ($this->tableExists('package_batches') && $this->tableExists('packages')) {
            $hasPublications = $this->tableExists('published_assets');
            $publicationJoin = $hasPublications
                ? 'LEFT JOIN published_assets pub ON pub.package_id = pa.package_id'
                : '';
            $publishedConditions = [];
            if ($this->columnExists('package_batches', 'status')) {
                $publishedConditions[] = "pj.status IN ('published', 'posted')";
            }
            if ($this->columnExists('packages', 'status')) {
                $publishedConditions[] = "pa.status IN ('published', 'posted', 'test_published')";
            }
            if ($this->columnExists('packages', 'published_at')) {
                $publishedConditions[] = 'pa.published_at IS NOT NULL';
            }
            if ($hasPublications) {
                $publishedConditions[] = 'pub.published_asset_id IS NOT NULL';
            }
            if ($publishedConditions === []) {
                $publishedConditions[] = '0 = 1';
            }
            $publishedWhere = implode(' OR ', $publishedConditions);
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT pj.package_batch_id)
                   FROM package_batches pj
                   LEFT JOIN packages pa
                     ON pa.package_batch_id = pj.package_batch_id
                   {$publicationJoin}
                  WHERE pj.playlist_instance_id = :id
                    AND ({$publishedWhere})"
            );
            $stmt->execute(['id' => $instanceId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = "Linked to {$count} published publishing job" . ($count === 1 ? '' : 's');
            }
        }

        if ($this->tableExists('playlist_instance_sets') && $this->columnExists('playlist_instance_sets', 'playlist_instance_id')) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                   FROM playlist_instance_sets
                  WHERE playlist_instance_id = :id'
            );
            $stmt->execute(['id' => $instanceId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = "Used in {$count} playlist instance set" . ($count === 1 ? '' : 's');
            }
        }

        return $blockers;
    }

    /**
     * @return PlaylistInstance[]
     */
    public function listAll(bool $onlyActive = false, bool $includeRetired = false): array
    {
        $where = [];
        if ($onlyActive) {
            $where[] = 'is_active = 1';
        }
        if (!$includeRetired && $this->columnExists('playlist_instances', 'is_retired')) {
            $where[] = 'COALESCE(is_retired, 0) = 0';
        }

        $sql = <<<SQL
            SELECT
              playlist_instance_id,
              playlist_id,
              slug,
              instance_name,
              display_title,
              display_subtitle,
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
              player_experience_id,
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

        if ($where !== []) {
            $sql .= "\nWHERE " . implode(' AND ', $where);
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
                $row['display_subtitle'] !== null ? (string)$row['display_subtitle'] : null,
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
                $row['kicker_id'] !== null ? (int)$row['kicker_id'] : null,
                $row['slug'] !== null ? (string)$row['slug'] : null,
                $row['player_experience_id'] !== null ? (int)$row['player_experience_id'] : null
            );
        }

        return $instances;
    }

    private function normalizeSlug(?string $slug): ?string
    {
        $slug = $this->slugify((string)$slug);
        return $slug !== '' ? $slug : null;
    }

    private function resolveUniqueSlug(PlaylistInstance $instance): string
    {
        $base = $this->normalizeSlug($instance->slug);
        if ($base === null && $instance->id !== null) {
            $base = $this->getExistingSlugById($instance->id);
        }
        $base = $base
            ?? $this->normalizeSlug($instance->displayTitle)
            ?? $this->normalizeSlug($instance->instanceName)
            ?? ('playlist-instance-' . ($instance->id ?: $instance->playlistId));

        $candidate = $base;
        $suffix = 2;
        while ($this->slugBelongsToAnotherInstance($candidate, $instance->id)) {
            $candidate = $base . '-' . $suffix;
            $suffix += 1;
        }

        return $candidate;
    }

    private function getExistingSlugById(int $instanceId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug
               FROM playlist_instances
              WHERE playlist_instance_id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $instanceId]);
        $slug = $stmt->fetchColumn();
        $slug = is_string($slug) ? $this->normalizeSlug($slug) : null;
        return $slug !== '' ? $slug : null;
    }

    private function slugBelongsToAnotherInstance(string $slug, ?int $instanceId): bool
    {
        $sql = 'SELECT playlist_instance_id FROM playlist_instances WHERE slug = :slug LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $existingId = $stmt->fetchColumn();
        if (!$existingId) {
            return false;
        }
        return $instanceId === null || (int)$existingId !== $instanceId;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? substr($value, 0, 191) : '';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
