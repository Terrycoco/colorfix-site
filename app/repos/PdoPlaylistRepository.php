<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\Playlist;
use App\Entities\PlaylistItem;
use App\Entities\PlaylistStep;
use PDO;

class PdoPlaylistRepository
{
    private const SLIDE_FLAGS = [
        'site',
        'client',
        'concept',
        'yt',
        'pin',
    ];

    public function __construct(
        private PDO $pdo
    ) {}

    public function getById(string $playlistId, string $venue = 'site'): ?Playlist
    {
        $meta = $this->getPlaylistMeta($playlistId);
        if ($meta === null) {
            return null;
        }

        $items = $this->getItemsFromDb($playlistId, $venue) ?? [];

        return new Playlist(
            $playlistId,
            $meta['type'],
            $meta['title'],
            [
                new PlaylistStep('all', false, $items),
            ],
            [
                'slug' => $meta['slug'] ?? null,
                'headline' => $meta['headline'] ?? null,
                'meta_description' => $meta['meta_description'] ?? null,
                'dek' => $meta['dek'] ?? null,
            ]
        );
    }

    public function getAdminRowById(int $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            WHERE p.playlist_id = :playlist_id
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => $playlistId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydrateSeoRow($row);
    }

    public function findSeoLandingBySlug(string $slug): ?array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title,
                shareable.playlist_instance_id AS watch_playlist_instance_id
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            LEFT JOIN (
                SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
                FROM playlist_instances
                WHERE is_active = 1
                  AND share_enabled = 1
                GROUP BY playlist_id
            ) shareable
              ON shareable.playlist_id = p.playlist_id
            WHERE p.slug = :slug
              AND p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND p.headline IS NOT NULL
              AND TRIM(p.headline) <> ''
              AND p.meta_description IS NOT NULL
              AND TRIM(p.meta_description) <> ''
              AND p.dek IS NOT NULL
              AND TRIM(p.dek) <> ''
              AND p.intro_html IS NOT NULL
              AND TRIM(p.intro_html) <> ''
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        return $this->hydratePublicSeoResult($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function findWatchPlaylistInstanceIdBySlug(string $slug): ?int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $sql = <<<SQL
            SELECT pi.playlist_instance_id
            FROM playlist_instances pi
            JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            WHERE pi.slug = :slug
              AND pi.is_active = 1
              AND pi.share_enabled = 1
              AND p.is_active = 1
            ORDER BY pi.playlist_instance_id ASC
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findSeoLandingByPlaylistId(int $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title,
                shareable.playlist_instance_id AS watch_playlist_instance_id
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            LEFT JOIN (
                SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
                FROM playlist_instances
                WHERE is_active = 1
                  AND share_enabled = 1
                GROUP BY playlist_id
            ) shareable
              ON shareable.playlist_id = p.playlist_id
            WHERE p.playlist_id = :playlist_id
              AND p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND p.slug IS NOT NULL
              AND TRIM(p.slug) <> ''
              AND p.headline IS NOT NULL
              AND TRIM(p.headline) <> ''
              AND p.meta_description IS NOT NULL
              AND TRIM(p.meta_description) <> ''
              AND p.dek IS NOT NULL
              AND TRIM(p.dek) <> ''
              AND p.intro_html IS NOT NULL
              AND TRIM(p.intro_html) <> ''
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => $playlistId]);
        return $this->hydratePublicSeoResult($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSeoLandingPages(): array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title,
                shareable.playlist_instance_id AS watch_playlist_instance_id
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            INNER JOIN (
                SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
                FROM playlist_instances
                WHERE is_active = 1
                  AND share_enabled = 1
                GROUP BY playlist_id
            ) shareable
              ON shareable.playlist_id = p.playlist_id
            WHERE p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND p.slug IS NOT NULL
              AND p.slug <> ''
              AND p.headline IS NOT NULL
              AND TRIM(p.headline) <> ''
              AND p.meta_description IS NOT NULL
              AND TRIM(p.meta_description) <> ''
              AND p.dek IS NOT NULL
              AND TRIM(p.dek) <> ''
              AND p.intro_html IS NOT NULL
              AND TRIM(p.intro_html) <> ''
            ORDER BY
              COALESCE(p.published_at, p.updated_at) DESC,
              p.playlist_id DESC
            SQL;

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->hydrateSeoRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublicPlayerPages(): array
    {
        $sql = <<<SQL
            SELECT
                pi.playlist_instance_id,
                pi.playlist_id,
                COALESCE(NULLIF(pi.display_title, ''), p.title) AS title,
                pi.slug,
                p.headline,
                p.meta_description,
                p.dek,
                p.updated_at,
                p.published_at
            FROM playlists p
            JOIN playlist_instances pi
              ON pi.playlist_id = p.playlist_id
            WHERE p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND pi.is_active = 1
              AND pi.share_enabled = 1
              AND pi.slug IS NOT NULL
              AND TRIM(pi.slug) <> ''
            ORDER BY
                COALESCE(p.published_at, p.updated_at) DESC,
                pi.playlist_instance_id DESC
            SQL;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function slugExists(string $slug, ?int $excludePlaylistId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM playlists WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($excludePlaylistId !== null && $excludePlaylistId > 0) {
            $sql .= ' AND playlist_id <> :exclude_playlist_id';
            $params['exclude_playlist_id'] = $excludePlaylistId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function generateUniqueSlug(string $baseSlug, ?int $excludePlaylistId = null): string
    {
        $slug = trim($baseSlug);
        if ($slug === '') {
            $slug = 'playlist';
        }

        $candidate = $slug;
        $suffix = 2;
        while ($this->slugExists($candidate, $excludePlaylistId)) {
            $candidate = sprintf('%s-%d', $slug, $suffix);
            $suffix++;
        }
        return $candidate;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function hydratePublicSeoResult(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (empty($row['watch_playlist_instance_id'])) {
            return null;
        }

        return $this->hydrateSeoRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listItemRows(?int $playlistId = null): array
    {
        $photoSelect = $this->getPhotoLibraryIdSelect();
        $sql = <<<SQL
            SELECT
                playlist_item_id,
                playlist_id,
                image_url,
                {$photoSelect},
                title,
                item_type,
                is_active
            FROM playlist_items
            SQL;

        $params = [];
        $where = [];
        if ($playlistId !== null && $playlistId > 0) {
            $where[] = 'playlist_id = :playlist_id';
            $params['playlist_id'] = $playlistId;
        }
        if ($where) {
            $sql .= "\nWHERE " . implode(' AND ', $where);
        }
        $sql .= "\nORDER BY playlist_id ASC, order_index ASC, playlist_item_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSavedPaletteReferencesByPlaylist(?int $playlistId = null): array
    {
        $hasSavedPaletteSetId = $this->hasPlaylistItemColumn('saved_palette_set_id');
        $hasPaletteHash = $this->hasPlaylistItemColumn('palette_hash');

        if (!$hasSavedPaletteSetId && !$hasPaletteHash) {
            return [];
        }

        $setJoin = $hasSavedPaletteSetId
            ? 'LEFT JOIN saved_palette_sets sps ON sps.id = pi.saved_palette_set_id'
            : '';
        $hashJoin = $hasPaletteHash
            ? 'LEFT JOIN saved_palettes sph ON sph.palette_hash = pi.palette_hash'
            : '';
        $setIdSelect = $hasSavedPaletteSetId ? 'pi.saved_palette_set_id' : 'NULL AS saved_palette_set_id';
        $savedPaletteIdSelect = $hasSavedPaletteSetId && $hasPaletteHash
            ? 'COALESCE(sps.saved_palette_id, sph.id) AS saved_palette_id'
            : ($hasSavedPaletteSetId ? 'sps.saved_palette_id AS saved_palette_id' : 'sph.id AS saved_palette_id');
        $setOrHashWhere = [];
        if ($hasSavedPaletteSetId) {
            $setOrHashWhere[] = '(pi.saved_palette_set_id IS NOT NULL AND pi.saved_palette_set_id > 0 AND sps.saved_palette_id IS NOT NULL)';
        }
        if ($hasPaletteHash) {
            $setOrHashWhere[] = "(pi.palette_hash IS NOT NULL AND pi.palette_hash <> '' AND sph.id IS NOT NULL)";
        }
        $referenceWhere = $this->implodeSqlOr($setOrHashWhere);

        $sql = <<<SQL
            SELECT
                pi.playlist_item_id,
                pi.playlist_id,
                pi.order_index,
                {$setIdSelect},
                {$savedPaletteIdSelect}
            FROM playlist_items pi
            {$setJoin}
            {$hashJoin}
            WHERE pi.is_active = 1
              AND (
                {$referenceWhere}
              )
            SQL;

        $params = [];
        if ($playlistId !== null && $playlistId > 0) {
            $sql .= "\n              AND pi.playlist_id = :playlist_id";
            $params['playlist_id'] = $playlistId;
        }

        $sql .= "\n            ORDER BY pi.playlist_id ASC, pi.order_index ASC, pi.playlist_item_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string[] $parts
     */
    private function implodeSqlOr(array $parts): string
    {
        return $parts ? implode("\n                OR ", $parts) : '0 = 1';
    }

    public function updateItemImageReference(int $playlistItemId, string $imageUrl, ?int $photoLibraryId): void
    {
        if ($playlistItemId <= 0) {
            return;
        }

        if ($this->hasPhotoLibraryIdColumn()) {
            $stmt = $this->pdo->prepare(
                "UPDATE playlist_items
                    SET image_url = :image_url,
                        photo_library_id = :photo_library_id
                  WHERE playlist_item_id = :playlist_item_id"
            );
            $stmt->execute([
                'image_url' => $imageUrl,
                'photo_library_id' => $photoLibraryId,
                'playlist_item_id' => $playlistItemId,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE playlist_items
                SET image_url = :image_url
              WHERE playlist_item_id = :playlist_item_id"
        );
        $stmt->execute([
            'image_url' => $imageUrl,
            'playlist_item_id' => $playlistItemId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdminItemRows(int $playlistId): array
    {
        $excludeSelect = $this->getExcludeFromThumbsSelect();
        $photoSelect = $this->getPhotoLibraryIdSelect();
        $savedPaletteSetSelect = $this->getSavedPaletteSetIdSelect();
        $colorPlanSelect = $this->hasPlaylistItemColumn('color_plan_id')
            ? 'color_plan_id'
            : 'NULL AS color_plan_id';
        $shareImageSelect = $this->getIsShareImageSelect();
        $siteSelect = $this->getPlaylistItemFlagSelect('site');
        $ytSelect = $this->getPlaylistItemFlagSelect('yt');
        $conceptSelect = $this->getPlaylistItemFlagSelect('concept');
        $clientSelect = $this->getPlaylistItemFlagSelect('client');
        $pinSelect = $this->getPlaylistItemFlagSelect('pin');
        $analyzerRoleSelect = $this->hasPlaylistItemColumn('analyzer_role') ? 'analyzer_role' : "'ignore' AS analyzer_role";
        $finderStartSelect = $this->hasPlaylistItemColumn('finder_start') ? 'finder_start' : "'auto' AS finder_start";
        $versionNumberSelect = $this->hasPlaylistItemColumn('version_number') ? 'version_number' : '1 AS version_number';
        $isFinalSelect = $this->hasPlaylistItemColumn('is_final') ? 'is_final' : '0 AS is_final';
        $sql = <<<SQL
            SELECT
              playlist_item_id,
              playlist_id,
              order_index,
              ap_id,
              palette_hash,
              image_url,
              {$photoSelect},
              {$savedPaletteSetSelect},
              {$colorPlanSelect},
              title,
              subtitle,
              subtitle_2,
              body,
              item_type,
              layout,
              title_mode,
              star,
              transition,
              duration_ms,
              {$excludeSelect},
              {$shareImageSelect},
              {$siteSelect},
              {$ytSelect},
              {$conceptSelect},
              {$clientSelect},
              {$pinSelect},
              {$analyzerRoleSelect},
              {$finderStartSelect},
              {$versionNumberSelect},
              {$isFinalSelect},
              is_active
            FROM playlist_items
            WHERE playlist_id = :playlist_id
              AND is_active = 1
            ORDER BY order_index ASC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => $playlistId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }


/**
 * Return active Public playlist slides for publishing.
 *
 * Hardcoded intentionally:
 *   - playlist_items.is_active = 1
 *   - playlist_items.site = 1
 *
 * Does NOT filter by:
 *   - Pinterest eligibility
 *   - pin flag
 *   - analyzer_role
 *   - before / after / single
 *
 * Those decisions belong to the Analyzer.
 *
 * IMPORTANT:
 * playlist_items.image_url is legacy when a
 * photo_library_id exists.
 *
 * Current photo location is resolved through:
 *
 *   playlist_items.photo_library_id
 *       → photo_library.photo_library_id
 *       → photo_library.rel_path
 *
 * @return array<int, array<string, mixed>>
 */

/**
 * Return active Public playlist slides for publishing.
 *
 * Hardcoded intentionally:
 *   - playlist_items.is_active = 1
 *   - playlist_items.site = 1
 *
 * Does NOT filter by:
 *   - Pinterest eligibility
 *   - pin flag
 *   - analyzer_role
 *   - before / after / single
 *
 * Those decisions belong to the Analyzer.
 *
 * IMPORTANT:
 * playlist_items.image_url is legacy when a
 * photo_library_id exists.
 *
 * Current photo location is resolved through:
 *
 *   playlist_items.photo_library_id
 *       → photo_library.photo_library_id
 *       → photo_library.rel_path
 *
 * @return array<int, array<string, mixed>>
 */
public function getPublicActiveSlides(
    int $playlistId
): array {
    if ($playlistId <= 0) {
        return [];
    }

    $excludeSelect =
        $this->getExcludeFromThumbsSelect();

    $savedPaletteSetSelect =
        $this->getSavedPaletteSetIdSelect();

    $colorPlanSelect =
        $this->hasPlaylistItemColumn(
            'color_plan_id'
        )
            ? 'color_plan_id'
            : 'NULL AS color_plan_id';

    $shareImageSelect =
        $this->getIsShareImageSelect();

    $ytSelect =
        $this->getPlaylistItemFlagSelect(
            'yt'
        );

    $conceptSelect =
        $this->getPlaylistItemFlagSelect(
            'concept'
        );

    $clientSelect =
        $this->getPlaylistItemFlagSelect(
            'client'
        );

    $pinSelect =
        $this->getPlaylistItemFlagSelect(
            'pin'
        );

    $analyzerRoleSelect =
        $this->hasPlaylistItemColumn(
            'analyzer_role'
        )
            ? 'analyzer_role'
            : "'ignore' AS analyzer_role";

    $finderStartSelect =
        $this->hasPlaylistItemColumn(
            'finder_start'
        )
            ? 'finder_start'
            : "'auto' AS finder_start";

    $versionNumberSelect =
        $this->hasPlaylistItemColumn(
            'version_number'
        )
            ? 'version_number'
            : '1 AS version_number';

    $isFinalSelect =
        $this->hasPlaylistItemColumn(
            'is_final'
        )
            ? 'is_final'
            : '0 AS is_final';


    $sql = <<<SQL
        SELECT
            slides.playlist_item_id,
            slides.playlist_id,
            slides.order_index,

            slides.ap_id,
            slides.palette_hash,
            slides.photo_library_id,
            slides.saved_palette_set_id,
            slides.color_plan_id,

            CASE
                WHEN
                    slides.photo_library_id IS NOT NULL
                    AND slides.photo_library_id > 0
                    AND pl.rel_path IS NOT NULL
                    AND TRIM(pl.rel_path) <> ''
                THEN pl.rel_path

                ELSE slides.legacy_image_url
            END AS image_url,

            slides.title,
            slides.subtitle,
            slides.subtitle_2,
            slides.body,
            slides.item_type,
            slides.layout,
            slides.title_mode,
            slides.star,
            slides.transition,
            slides.duration_ms,

            slides.exclude_from_thumbs,
            slides.is_share_image,

            slides.site,
            slides.yt,
            slides.concept,
            slides.client,
            slides.pin,

            slides.analyzer_role,
            slides.finder_start,
            slides.version_number,
            slides.is_final,
            slides.is_active

        FROM (
            SELECT
                playlist_item_id,
                playlist_id,
                order_index,

                ap_id,
                palette_hash,

                image_url AS legacy_image_url,

                photo_library_id,

                {$savedPaletteSetSelect},
                {$colorPlanSelect},

                title,
                subtitle,
                subtitle_2,
                body,
                item_type,
                layout,
                title_mode,
                star,
                transition,
                duration_ms,

                {$excludeSelect},
                {$shareImageSelect},

                site,
                {$ytSelect},
                {$conceptSelect},
                {$clientSelect},
                {$pinSelect},

                {$analyzerRoleSelect},
                {$finderStartSelect},
                {$versionNumberSelect},
                {$isFinalSelect},

                is_active

            FROM playlist_items

            WHERE playlist_id = :playlist_id
              AND is_active = 1
              AND site = 1
        ) AS slides

        LEFT JOIN photo_library pl
            ON pl.photo_library_id =
               slides.photo_library_id

        ORDER BY
            slides.order_index ASC
        SQL;

    $stmt =
        $this->pdo->prepare(
            $sql
        );

    $stmt->execute([
        'playlist_id' =>
            $playlistId,
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    ) ?: [];
}





    /**
     * @param array<int, array<string, mixed>> $items
     * @param callable(array<string, mixed>): array<string, mixed> $normalizeItem
     */
    public function saveAdminItems(int $playlistId, array $items, callable $normalizeItem): void
    {
        $hasExcludeFromThumbs = $this->hasPlaylistItemColumn('exclude_from_thumbs');
        $hasPhotoLibraryId = $this->hasPlaylistItemColumn('photo_library_id');
        $hasSavedPaletteSetId = $this->hasPlaylistItemColumn('saved_palette_set_id');
        $hasColorPlanId = $this->hasPlaylistItemColumn('color_plan_id');
        $hasIsShareImage = $this->hasPlaylistItemColumn('is_share_image');
        $hasSite = $this->hasPlaylistItemColumn('site');
        $hasYt = $this->hasPlaylistItemColumn('yt');
        $hasConcept = $this->hasPlaylistItemColumn('concept');
        $hasClient = $this->hasPlaylistItemColumn('client');
        $hasPin = $this->hasPlaylistItemColumn('pin');
        $hasAnalyzerRole = $this->hasPlaylistItemColumn('analyzer_role');
        $hasFinderStart = $this->hasPlaylistItemColumn('finder_start');
        $hasVersionNumber = $this->hasPlaylistItemColumn('version_number');
        $hasIsFinal = $this->hasPlaylistItemColumn('is_final');

        $selectedShareIndex = null;
        foreach ($items as $idx => $candidate) {
            $candidateHasPhoto = (
                (isset($candidate['photo_library_id']) && $candidate['photo_library_id'] !== '')
                || trim((string)($candidate['image_url'] ?? '')) !== ''
            );
            if ($candidateHasPhoto && !empty($candidate['is_share_image'])) {
                $selectedShareIndex = $idx;
                break;
            }
        }

        $stmt = $this->pdo->prepare('UPDATE playlist_items SET order_index = order_index + 10000 WHERE playlist_id = :playlist_id');
        $stmt->execute(['playlist_id' => $playlistId]);

        $orderIndex = 0;
        $keepIds = [];
        foreach ($items as $item) {
            $itemId = isset($item['playlist_item_id']) ? (int)$item['playlist_item_id'] : 0;
            $hasPhoto = (
                (isset($item['photo_library_id']) && $item['photo_library_id'] !== '')
                || trim((string)($item['image_url'] ?? '')) !== ''
            );
            $data = [
                'playlist_id' => $playlistId,
                'order_index' => $orderIndex,
                'ap_id' => isset($item['ap_id']) && $item['ap_id'] !== '' ? (int)$item['ap_id'] : null,
                'palette_hash' => isset($item['palette_hash']) && $item['palette_hash'] !== '' ? (string)$item['palette_hash'] : null,
                'image_url' => $item['image_url'] ?? null,
                'photo_library_id' => isset($item['photo_library_id']) && $item['photo_library_id'] !== '' ? (int)$item['photo_library_id'] : null,
                'saved_palette_set_id' => isset($item['saved_palette_set_id']) && $item['saved_palette_set_id'] !== '' ? (int)$item['saved_palette_set_id'] : null,
                'color_plan_id' => isset($item['color_plan_id']) && $item['color_plan_id'] !== '' ? (int)$item['color_plan_id'] : null,
                'title' => $item['title'] ?? null,
                'subtitle' => $item['subtitle'] ?? null,
                'subtitle_2' => $item['subtitle_2'] ?? null,
                'body' => $item['body'] ?? null,
                'item_type' => $item['item_type'] ?? 'non-palette',
                'layout' => $item['layout'] ?? 'default',
                'title_mode' => $item['title_mode'] ?? null,
                'star' => isset($item['star']) ? (int)(bool)$item['star'] : 1,
                'transition' => $item['transition'] ?? null,
                'duration_ms' => isset($item['duration_ms']) && $item['duration_ms'] !== '' ? (int)$item['duration_ms'] : null,
                'is_active' => isset($item['is_active']) ? (int)(bool)$item['is_active'] : 1,
            ];

            if ($hasVersionNumber) {
                $data['version_number'] = max(1, (int)($item['version_number'] ?? 1));
            }
            if ($hasIsFinal) {
                $data['is_final'] = !empty($item['is_final']) ? 1 : 0;
            }

            if ($hasSite) {
                $data['site'] = array_key_exists('site', $item) ? (int)(bool)$item['site'] : 1;
            }
            if ($hasYt) {
                $data['yt'] = array_key_exists('yt', $item) ? (int)(bool)$item['yt'] : 1;
            }
            if ($hasConcept) {
                $data['concept'] = array_key_exists('concept', $item) ? (int)(bool)$item['concept'] : 1;
            }
            if ($hasClient) {
                $data['client'] = array_key_exists('client', $item) ? (int)(bool)$item['client'] : 1;
            }
            if ($hasPin) {
                $data['pin'] = array_key_exists('pin', $item) ? (int)(bool)$item['pin'] : 1;
            }
            if ($hasAnalyzerRole) {
                $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
                $data['analyzer_role'] = in_array($role, ['ignore', 'before', 'after', 'single'], true) ? $role : 'ignore';
            }
            if ($hasFinderStart) {
                $finderStart = strtolower(trim((string)($item['finder_start'] ?? 'auto')));
                $data['finder_start'] = in_array($finderStart, ['auto', 'this', 'previous'], true) ? $finderStart : 'auto';
            }
            if ($hasExcludeFromThumbs) {
                $data['exclude_from_thumbs'] = isset($item['exclude_from_thumbs']) ? (int)(bool)$item['exclude_from_thumbs'] : 0;
            }
            if ($hasIsShareImage) {
                $data['is_share_image'] = ($hasPhoto && $selectedShareIndex === $orderIndex) ? 1 : 0;
            }

            $data = $normalizeItem($data);

            $columns = [
                'playlist_id',
                'order_index',
                'ap_id',
                'palette_hash',
                'image_url',
                'photo_library_id',
                'saved_palette_set_id',
                'color_plan_id',
                'title',
                'subtitle',
                'subtitle_2',
                'body',
                'item_type',
                'layout',
                'title_mode',
                'star',
                'transition',
                'duration_ms',
                'is_active',
                'is_share_image',
                'site',
                'yt',
                'concept',
                'client',
                'pin',
                'analyzer_role',
                'finder_start',
                'version_number',
                'is_final',
            ];
            if (!$hasPhotoLibraryId) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'photo_library_id'));
                unset($data['photo_library_id']);
            }
            if (!$hasSavedPaletteSetId) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'saved_palette_set_id'));
                unset($data['saved_palette_set_id']);
            }
            if (!$hasColorPlanId) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'color_plan_id'));
                unset($data['color_plan_id']);
            }
            if (!$hasIsShareImage) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'is_share_image'));
                unset($data['is_share_image']);
            }
            if (!$hasSite) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'site'));
                unset($data['site']);
            }
            if (!$hasYt) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'yt'));
                unset($data['yt']);
            }
            if (!$hasConcept) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'concept'));
                unset($data['concept']);
            }
            if (!$hasClient) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'client'));
                unset($data['client']);
            }
            if (!$hasPin) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'pin'));
                unset($data['pin']);
            }
            if (!$hasAnalyzerRole) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'analyzer_role'));
                unset($data['analyzer_role']);
            }
            if (!$hasFinderStart) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'finder_start'));
                unset($data['finder_start']);
            }
            if (!$hasVersionNumber) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'version_number'));
                unset($data['version_number']);
            }
            if (!$hasIsFinal) {
                $columns = array_values(array_filter($columns, fn($col) => $col !== 'is_final'));
                unset($data['is_final']);
            }
            if (!$hasExcludeFromThumbs) {
                unset($data['exclude_from_thumbs']);
            } elseif (!in_array('exclude_from_thumbs', $columns, true)) {
                $columns[] = 'exclude_from_thumbs';
            }

            $updateColumns = array_values(array_filter($columns, fn($col) => $col !== 'playlist_id'));
            $setSql = implode(",\n                  ", array_map(fn($col) => "{$col} = :{$col}", $updateColumns));
            $insertSqlCols = implode(",\n                  ", $columns);
            $insertSqlVals = implode(",\n                  ", array_map(fn($col) => ":{$col}", $columns));

            if ($itemId > 0) {
                $sql = <<<SQL
                    UPDATE playlist_items
                    SET
                      {$setSql}
                    WHERE playlist_item_id = :playlist_item_id
                      AND playlist_id = :playlist_id
                    SQL;
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_merge($data, [
                    'playlist_item_id' => $itemId,
                ]));
                $keepIds[] = $itemId;
            } else {
                $sql = <<<SQL
                    INSERT INTO playlist_items (
                      {$insertSqlCols}
                    ) VALUES (
                      {$insertSqlVals}
                    )
                    SQL;
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($data);
                $keepIds[] = (int)$this->pdo->lastInsertId();
            }

            $orderIndex++;
        }

        if ($keepIds) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $sql = "DELETE FROM playlist_items WHERE playlist_id = ? AND playlist_item_id NOT IN ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$playlistId], $keepIds));
            return;
        }

        $stmt = $this->pdo->prepare("DELETE FROM playlist_items WHERE playlist_id = ?");
        $stmt->execute([$playlistId]);
    }

    /**
     * @return PlaylistItem[]|null
     */
    private function getItemsFromDb(string $playlistId, string $venue = 'site'): ?array
    {
        $excludeSelect = $this->getExcludeFromThumbsSelect();
        $photoSelect = $this->getPhotoLibraryIdSelect();
        $savedPaletteSetSelect = $this->getSavedPaletteSetIdSelect();
        $colorPlanSelect = $this->hasPlaylistItemColumn('color_plan_id')
            ? 'color_plan_id'
            : 'NULL AS color_plan_id';
        $shareImageSelect = $this->getIsShareImageSelect();
        $siteSelect = $this->getPlaylistItemFlagSelect('site');
        $ytSelect = $this->getPlaylistItemFlagSelect('yt');
        $conceptSelect = $this->getPlaylistItemFlagSelect('concept');
        $clientSelect = $this->getPlaylistItemFlagSelect('client');
        $pinSelect = $this->getPlaylistItemFlagSelect('pin');
        $analyzerRoleSelect = $this->hasPlaylistItemColumn('analyzer_role') ? 'analyzer_role' : "'ignore' AS analyzer_role";
        $versionNumberSelect = $this->hasPlaylistItemColumn('version_number') ? 'version_number' : '1 AS version_number';
        $isFinalSelect = $this->hasPlaylistItemColumn('is_final') ? 'is_final' : '0 AS is_final';
        $venueColumn = strtolower(trim($venue));
        if (!in_array($venueColumn, self::SLIDE_FLAGS, true)) {
            throw new \DomainException(
                "Unsupported playlist slide flag: {$venueColumn}"
            );
        }
        $venueWhere = $this->hasPlaylistItemColumn($venueColumn) ? "\n              AND {$venueColumn} = 1" : '';
        $sql = <<<SQL
            SELECT
                playlist_item_id,
                playlist_id,
                order_index,
                ap_id,
                palette_hash,
                image_url,
                {$photoSelect},
                {$savedPaletteSetSelect},
                {$colorPlanSelect},
                title,
                subtitle,
                body,
                item_type,
                layout,
                title_mode,
                star,
                transition,
                duration_ms,
                {$excludeSelect},
                {$shareImageSelect},
                {$siteSelect},
                {$ytSelect},
                {$conceptSelect},
                {$clientSelect},
                {$pinSelect},
                {$analyzerRoleSelect},
                {$versionNumberSelect},
                {$isFinalSelect}
            FROM playlist_items
            WHERE playlist_id = :playlist_id
              AND is_active = 1
              {$venueWhere}
            ORDER BY order_index ASC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => (int)$playlistId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            $star = null;
            if (array_key_exists('star', $row)) {
                $star = $row['star'] === null ? null : (bool)$row['star'];
            }

            $items[] = new PlaylistItem(
                (string)($row['ap_id'] ?? ''),
                $row['palette_hash'] ?? null,
                $row['image_url'] ?? null,
                isset($row['photo_library_id']) ? (int)$row['photo_library_id'] : null,
                isset($row['saved_palette_set_id']) ? (int)$row['saved_palette_set_id'] : null,
                null,
                $row['title'] ?? null,
                $row['subtitle'] ?? null,
                $row['body'] ?? null,
                $row['item_type'] ?? null,
                $star,
                $row['layout'] ?? null,
                $row['transition'] ?? null,
                $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
                $row['title_mode'] ?? null,
                isset($row['exclude_from_thumbs']) ? (bool)$row['exclude_from_thumbs'] : null,
                isset($row['is_share_image']) ? (bool)$row['is_share_image'] : null,
                isset($row['site']) ? (bool)$row['site'] : true,
                isset($row['yt']) ? (bool)$row['yt'] : true,
                isset($row['concept']) ? (bool)$row['concept'] : true,
                isset($row['client']) ? (bool)$row['client'] : true,
                isset($row['pin']) ? (bool)$row['pin'] : true,
                $row['analyzer_role'] ?? 'ignore',
                null,
                null,
                isset($row['playlist_item_id']) ? (int)$row['playlist_item_id'] : null,
                null,
                null,
                isset($row['version_number']) ? max(1, (int)$row['version_number']) : 1,
                isset($row['is_final']) ? (bool)$row['is_final'] : false,
                isset($row['color_plan_id']) && $row['color_plan_id'] !== null ? (int)$row['color_plan_id'] : null
            );
        }

        return $items;
    }

    private function getExcludeFromThumbsSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'exclude_from_thumbs'
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $hasColumn = (int)$stmt->fetchColumn() > 0;
        $cached = $hasColumn ? 'exclude_from_thumbs' : '0 AS exclude_from_thumbs';
        return $cached;
    }

    private function getPhotoLibraryIdSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $hasColumn = $this->hasPhotoLibraryIdColumn();
        $cached = $hasColumn ? 'photo_library_id' : 'NULL AS photo_library_id';
        return $cached;
    }

    private function getIsShareImageSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'is_share_image'
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $hasColumn = (int)$stmt->fetchColumn() > 0;
        $cached = $hasColumn ? 'is_share_image' : '0 AS is_share_image';
        return $cached;
    }

    private function getSavedPaletteSetIdSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'saved_palette_set_id'
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $cached = (int)$stmt->fetchColumn() > 0 ? 'saved_palette_set_id' : 'NULL AS saved_palette_set_id';
        return $cached;
    }

    private function getPlaylistItemFlagSelect(string $column): string
    {
        return $this->hasPlaylistItemColumn($column) ? $column : "1 AS {$column}";
    }

    private function hasPlaylistItemColumn(string $column): bool
    {
        static $cached = [];
        if (array_key_exists($column, $cached)) return $cached[$column];
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = :column
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['column' => $column]);
        $cached[$column] = (int)$stmt->fetchColumn() > 0;
        return $cached[$column];
    }

    private function hasPhotoLibraryIdColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'photo_library_id'
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $cached = (int)$stmt->fetchColumn() > 0;
        return $cached;
    }

    private function getPlaylistMeta(string $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT playlist_id, title, type, slug, headline, meta_description, dek
            FROM playlists
            WHERE playlist_id = :playlist_id
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => (int)$playlistId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'playlist_id' => (string)$row['playlist_id'],
            'title' => (string)$row['title'],
            'type' => (string)$row['type'],
            'slug' => $row['slug'] !== null ? (string)$row['slug'] : null,
            'headline' => $row['headline'] !== null ? (string)$row['headline'] : null,
            'meta_description' => $row['meta_description'] !== null ? (string)$row['meta_description'] : null,
            'dek' => $row['dek'] !== null ? (string)$row['dek'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateSeoRow(array $row): array
    {
        $heroUrl = trim((string)($row['hero_image_url'] ?? ''));
        if ($heroUrl === '') {
            $heroUrl = trim((string)($row['hero_rel_path'] ?? ''));
        }

        $heroAlt = trim((string)($row['hero_alt'] ?? ''));
        if ($heroAlt === '') {
            $heroAlt = trim((string)($row['hero_photo_alt'] ?? ''));
        }

        return [
            'playlist_id' => (int)($row['playlist_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
            'is_public' => (int)($row['is_public'] ?? 0),
            'slug' => $row['slug'] !== null ? (string)$row['slug'] : null,
            'headline' => $row['headline'] !== null ? (string)$row['headline'] : null,
            'page_title' => $row['page_title'] !== null ? (string)$row['page_title'] : null,
            'meta_description' => $row['meta_description'] !== null ? (string)$row['meta_description'] : null,
            'dek' => $row['dek'] !== null ? (string)$row['dek'] : null,
            'intro_html' => $row['intro_html'] !== null ? (string)$row['intro_html'] : null,
            'body_html' => $row['body_html'] !== null ? (string)$row['body_html'] : null,
            'hero_image_id' => isset($row['hero_image_id']) && $row['hero_image_id'] !== null ? (int)$row['hero_image_id'] : null,
            'hero_image_url' => $heroUrl !== '' ? $heroUrl : null,
            'hero_alt' => $heroAlt !== '' ? $heroAlt : null,
            'hero_photo_title' => $row['hero_photo_title'] !== null ? (string)$row['hero_photo_title'] : null,
            'indexable' => (int)($row['indexable'] ?? 1),
            'published_at' => $row['published_at'] !== null ? (string)$row['published_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            'watch_playlist_instance_id' => isset($row['watch_playlist_instance_id']) && $row['watch_playlist_instance_id'] !== null
                ? (int)$row['watch_playlist_instance_id']
                : null,
        ];
    }
}
