<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Central coordinator for soft-retire / restore behavior across admin content.
 *
 * Design rules:
 * - Nothing is physically deleted here.
 * - "Retire" means setting is_retired = 1 and retired_at = NOW().
 * - "Restore" means setting is_retired = 0 and retired_at = NULL.
 * - Cascades are explicit and previewable; never implicit.
 *
 * Planned supported subjects:
 * - articles
 * - playlists
 * - playlist_instances
 * - playlist_instance_sets
 * - photo_library
 *
 * Dialog contract this service is meant to support:
 * - Yes    => retire parent + submembers
 * - No     => retire parent only
 * - Cancel => no-op in UI, service not called
 */
final class RetireService
{
    public const TYPE_ARTICLE = 'article';
    public const TYPE_PLAYLIST = 'playlist';
    public const TYPE_PLAYLIST_INSTANCE = 'playlist_instance';
    public const TYPE_PLAYLIST_INSTANCE_SET = 'playlist_instance_set';
    public const TYPE_PHOTO_LIBRARY = 'photo_library';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Preview what would be retired or restored before the UI asks
     * for Yes / No / Cancel.
     *
     * Intended return shape:
     * [
     *   'type' => 'playlist',
     *   'id' => 21,
     *   'label' => 'Playlist #21: Mojdeh',
     *   'is_retired' => false,
     *   'children' => [
     *     [
     *       'type' => 'playlist_instance',
     *       'count' => 3,
     *       'label' => 'Playlist instances',
     *       'items' => [...optional lightweight rows...],
     *     ],
     *     [
     *       'type' => 'playlist_instance_set_membership',
     *       'count' => 2,
     *       'label' => 'Playlist set memberships',
     *       'items' => [...optional lightweight rows...],
     *     ],
     *   ],
     *   'warnings' => [
     *     'Photos are shared assets and are not retired automatically.',
     *   ],
     * ]
     */
    public function preview(string $type, int $id): array
    {
        return match ($this->normalizeType($type)) {
            self::TYPE_ARTICLE => $this->previewArticle($id),
            self::TYPE_PLAYLIST => $this->previewPlaylist($id),
            self::TYPE_PLAYLIST_INSTANCE => $this->previewPlaylistInstance($id),
            self::TYPE_PLAYLIST_INSTANCE_SET => $this->previewPlaylistInstanceSet($id),
            self::TYPE_PHOTO_LIBRARY => $this->previewPhotoLibrary($id),
            default => throw new InvalidArgumentException("Unsupported retire type: {$type}"),
        };
    }

    /**
     * Retire an item.
     *
     * Pseudocode:
     * 1. Begin transaction.
     * 2. Validate parent exists.
     * 3. Retire parent row.
     * 4. If $cascade === true, retire supported child rows.
     * 5. Commit.
     * 6. Return summary for UI/audit.
     */
    public function retire(string $type, int $id, bool $cascade = false): array
    {
        $normalizedType = $this->normalizeType($type);

        // Pseudocode only for now.
        return [
            'ok' => false,
            'implemented' => false,
            'action' => 'retire',
            'type' => $normalizedType,
            'id' => $id,
            'cascade' => $cascade,
            'message' => 'RetireService::retire() scaffold created; table-specific execution not implemented yet.',
        ];
    }

    /**
     * Restore an item.
     *
     * Pseudocode:
     * 1. Begin transaction.
     * 2. Validate parent exists.
     * 3. Restore parent row.
     * 4. If $cascade === true, restore child rows that were retired with it.
     * 5. Commit.
     */
    public function restore(string $type, int $id, bool $cascade = false): array
    {
        $normalizedType = $this->normalizeType($type);

        // Pseudocode only for now.
        return [
            'ok' => false,
            'implemented' => false,
            'action' => 'restore',
            'type' => $normalizedType,
            'id' => $id,
            'cascade' => $cascade,
            'message' => 'RetireService::restore() scaffold created; table-specific execution not implemented yet.',
        ];
    }

    /**
     * Shared cascade policy.
     *
     * These are the rules we agreed on:
     * - article:
     *   retire article only; never auto-retire photos
     * - playlist:
     *   optionally retire playlist_instances and set memberships
     *   never auto-retire photos
     * - playlist_instance:
     *   optionally retire set memberships referencing it
     * - playlist_instance_set:
     *   optionally retire its visible membership surface
     * - photo_library:
     *   never auto-retire articles/playlists/viewers
     *   only warn about usages
     */
    public function getCascadeRules(): array
    {
        return [
            self::TYPE_ARTICLE => [
                'children' => [],
                'warnings' => [
                    'Article photos are shared assets and are not retired automatically.',
                ],
            ],
            self::TYPE_PLAYLIST => [
                'children' => [
                    'playlist_instances',
                    'playlist_instance_set_memberships',
                ],
                'warnings' => [
                    'Photos are shared assets and are not retired automatically.',
                ],
            ],
            self::TYPE_PLAYLIST_INSTANCE => [
                'children' => [
                    'playlist_instance_set_memberships',
                ],
                'warnings' => [],
            ],
            self::TYPE_PLAYLIST_INSTANCE_SET => [
                'children' => [
                    'playlist_instance_set_items',
                ],
                'warnings' => [],
            ],
            self::TYPE_PHOTO_LIBRARY => [
                'children' => [],
                'warnings' => [
                    'Photos are shared assets. Retiring a photo does not retire articles, playlists, or viewers.',
                    'If the photo is still used, the UI should warn before retiring it.',
                ],
            ],
        ];
    }

    private function previewArticle(int $id): array
    {
        $row = $this->fetchOne(
            'SELECT id, title, is_retired, retired_at FROM articles WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        if (!$row) {
            throw new RuntimeException("Article #{$id} not found");
        }

        return [
            'type' => self::TYPE_ARTICLE,
            'id' => $id,
            'label' => sprintf('Article #%d: %s', $id, (string)($row['title'] ?? 'Untitled')),
            'is_retired' => (bool)($row['is_retired'] ?? false),
            'retired_at' => $row['retired_at'] ?? null,
            'children' => [],
            'warnings' => $this->getCascadeRules()[self::TYPE_ARTICLE]['warnings'],
        ];
    }

    private function previewPlaylist(int $id): array
    {
        $row = $this->fetchOne(
            'SELECT playlist_id, title, is_retired, retired_at FROM playlists WHERE playlist_id = :id LIMIT 1',
            ['id' => $id]
        );
        if (!$row) {
            throw new RuntimeException("Playlist #{$id} not found");
        }

        $instanceCount = (int)$this->fetchValue(
            'SELECT COUNT(*) FROM playlist_instances WHERE playlist_id = :id',
            ['id' => $id]
        );
        $setMembershipCount = (int)$this->fetchValue(
            'SELECT COUNT(*)
               FROM playlist_instance_set_items psi
               JOIN playlist_instances pi
                 ON pi.playlist_instance_id = psi.playlist_instance_id
              WHERE pi.playlist_id = :id',
            ['id' => $id]
        );

        return [
            'type' => self::TYPE_PLAYLIST,
            'id' => $id,
            'label' => sprintf('Playlist #%d: %s', $id, (string)($row['title'] ?? 'Untitled')),
            'is_retired' => (bool)($row['is_retired'] ?? false),
            'retired_at' => $row['retired_at'] ?? null,
            'children' => [
                [
                    'type' => 'playlist_instances',
                    'count' => $instanceCount,
                    'label' => 'Playlist instances',
                ],
                [
                    'type' => 'playlist_instance_set_memberships',
                    'count' => $setMembershipCount,
                    'label' => 'Playlist set memberships',
                ],
            ],
            'warnings' => $this->getCascadeRules()[self::TYPE_PLAYLIST]['warnings'],
        ];
    }

    private function previewPlaylistInstance(int $id): array
    {
        $row = $this->fetchOne(
            'SELECT playlist_instance_id, instance_name, is_retired, retired_at
               FROM playlist_instances
              WHERE playlist_instance_id = :id
              LIMIT 1',
            ['id' => $id]
        );
        if (!$row) {
            throw new RuntimeException("Playlist instance #{$id} not found");
        }

        $setMembershipCount = (int)$this->fetchValue(
            'SELECT COUNT(*) FROM playlist_instance_set_items WHERE playlist_instance_id = :id',
            ['id' => $id]
        );

        return [
            'type' => self::TYPE_PLAYLIST_INSTANCE,
            'id' => $id,
            'label' => sprintf('Playlist Instance #%d: %s', $id, (string)($row['instance_name'] ?? 'Untitled')),
            'is_retired' => (bool)($row['is_retired'] ?? false),
            'retired_at' => $row['retired_at'] ?? null,
            'children' => [
                [
                    'type' => 'playlist_instance_set_memberships',
                    'count' => $setMembershipCount,
                    'label' => 'Playlist set memberships',
                ],
            ],
            'warnings' => $this->getCascadeRules()[self::TYPE_PLAYLIST_INSTANCE]['warnings'],
        ];
    }

    private function previewPlaylistInstanceSet(int $id): array
    {
        $row = $this->fetchOne(
            'SELECT id, title, is_retired, retired_at
               FROM playlist_instance_sets
              WHERE id = :id
              LIMIT 1',
            ['id' => $id]
        );
        if (!$row) {
            throw new RuntimeException("Playlist instance set #{$id} not found");
        }

        $itemCount = (int)$this->fetchValue(
            'SELECT COUNT(*) FROM playlist_instance_set_items WHERE playlist_instance_set_id = :id',
            ['id' => $id]
        );

        return [
            'type' => self::TYPE_PLAYLIST_INSTANCE_SET,
            'id' => $id,
            'label' => sprintf('Playlist Set #%d: %s', $id, (string)($row['title'] ?? 'Untitled')),
            'is_retired' => (bool)($row['is_retired'] ?? false),
            'retired_at' => $row['retired_at'] ?? null,
            'children' => [
                [
                    'type' => 'playlist_instance_set_items',
                    'count' => $itemCount,
                    'label' => 'Set items',
                ],
            ],
            'warnings' => $this->getCascadeRules()[self::TYPE_PLAYLIST_INSTANCE_SET]['warnings'],
        ];
    }

    private function previewPhotoLibrary(int $id): array
    {
        $row = $this->fetchOne(
            'SELECT photo_library_id, title, is_retired, retired_at
               FROM photo_library
              WHERE photo_library_id = :id
              LIMIT 1',
            ['id' => $id]
        );
        if (!$row) {
            throw new RuntimeException("Photo Library #{$id} not found");
        }

        $usageCount = $this->countPhotoUsages($id);

        return [
            'type' => self::TYPE_PHOTO_LIBRARY,
            'id' => $id,
            'label' => sprintf('Photo Library #%d: %s', $id, (string)($row['title'] ?? 'Untitled')),
            'is_retired' => (bool)($row['is_retired'] ?? false),
            'retired_at' => $row['retired_at'] ?? null,
            'children' => [],
            'warnings' => array_merge(
                $this->getCascadeRules()[self::TYPE_PHOTO_LIBRARY]['warnings'],
                $usageCount > 0 ? ["This photo is still used in {$usageCount} place(s)."] : []
            ),
        ];
    }

    private function countPhotoUsages(int $photoLibraryId): int
    {
        return
            (int)$this->fetchValue('SELECT COUNT(*) FROM article_sections WHERE asset_id = :id', ['id' => $photoLibraryId]) +
            (int)$this->fetchValue('SELECT COUNT(*) FROM articles WHERE hero_asset_id = :id', ['id' => $photoLibraryId]) +
            (int)$this->fetchValue('SELECT COUNT(*) FROM playlist_items WHERE photo_library_id = :id', ['id' => $photoLibraryId]) +
            (int)$this->fetchValue('SELECT COUNT(*) FROM playlist_instances WHERE intro_photo_library_id = :id', ['id' => $photoLibraryId]) +
            (int)$this->fetchValue('SELECT COUNT(*) FROM playlist_instances WHERE share_photo_library_id = :id', ['id' => $photoLibraryId]) +
            (int)$this->fetchValue('SELECT COUNT(*) FROM saved_palette_set_photos WHERE photo_library_id = :id', ['id' => $photoLibraryId]) +
            (int)$this->fetchValue('SELECT COUNT(*) FROM photo_group_items WHERE photo_library_id = :id', ['id' => $photoLibraryId]);
    }

    private function normalizeType(string $type): string
    {
        $normalized = trim(strtolower($type));
        return match ($normalized) {
            'article', 'articles' => self::TYPE_ARTICLE,
            'playlist', 'playlists' => self::TYPE_PLAYLIST,
            'playlist_instance', 'playlist-instances', 'playlist-instance', 'playlist_instances' => self::TYPE_PLAYLIST_INSTANCE,
            'playlist_instance_set', 'playlist-instance-set', 'playlist_instance_sets', 'playlist-instance-sets' => self::TYPE_PLAYLIST_INSTANCE_SET,
            'photo', 'photo_library', 'photo-library', 'photos' => self::TYPE_PHOTO_LIBRARY,
            default => $normalized,
        };
    }

    /**
     * Thin helpers so the service stays readable while we flesh it out.
     *
     * Later we can move these query shapes into repositories once the retire flow
     * settles and we know which preview summaries we actually want long-term.
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchValue(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
