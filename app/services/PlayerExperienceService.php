<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPlaylistRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoCtaRepository;
use App\Repos\PdoArticleRepository;
use App\Entities\Playlist;
use App\Entities\PlaylistItem;
use App\Entities\PlaylistInstance;
use PDO;
use RuntimeException;

final class PlayerExperienceService
{
    /** @var array<string, float> */
    private array $lastTiming = [];

    public function __construct(
        private PDO $pdo
    ) {}

    public function buildPlaybackPlanFromInstance(
        int $playlistInstanceId,
        ?int $start = null,
        ?string $ctaContext = null,
        ?int $addCtaGroupId = null
    ): array {
        $startedAt = microtime(true);

        // 1. Load playlist instance
        $instanceRepo = new PdoPlaylistInstanceRepository($this->pdo);
        $instance = $instanceRepo->getById($playlistInstanceId);
        $this->markTiming('load_instance', $startedAt);

        if (!$instance instanceof PlaylistInstance) {
            throw new RuntimeException("Playlist instance not found: {$playlistInstanceId}");
        }

        // 2. Load playlist
        $playlistStartedAt = microtime(true);
        $playlistRepo = new PdoPlaylistRepository($this->pdo);
        $playlist = $playlistRepo->getById((string)$instance->playlistId);
        $this->markTiming('load_playlist', $playlistStartedAt);

        if (!$playlist instanceof Playlist) {
            throw new RuntimeException(
                "Playlist {$instance->playlistId} not found for instance {$playlistInstanceId}"
            );
        }

        // 3. Flatten playlist items
        $itemsStartedAt = microtime(true);
        $items = $this->flattenItems($playlist);
        $this->hydrateItemImages($items);
        $this->markTiming('hydrate_items', $itemsStartedAt);
        $startIndex = $this->normalizeStartIndex($start, count($items));

        // 4. Load CTAs for this instance (optionally scoped by context) + optional add-on group.
        $ctaStartedAt = microtime(true);
        $overrides = [];
        if (!empty($instance->ctaOverrides)) {
            $decoded = json_decode($instance->ctaOverrides, true);
            if (is_array($decoded)) {
                $overrides = $decoded;
            }
        }

        $ctas = [];
        $ctaRepo = null;

        $overrideIds = $overrides['_cta_ids'] ?? null;
        if (is_array($overrideIds)) {
            // If explicit CTA ids are provided, use only those.
            $ctaRepo = new PdoCtaRepository($this->pdo);
            $ctas = $ctaRepo->getByIds($overrideIds);
        } else {
            if ($instance->ctaGroupId !== null) {
                $ctaRepo = new PdoCtaRepository($this->pdo);
                $ctas = $ctaRepo->getByGroupId($instance->ctaGroupId);
            }
            if ($addCtaGroupId !== null && $addCtaGroupId > 0) {
                if ($instance->ctaGroupId !== null && (int)$instance->ctaGroupId === (int)$addCtaGroupId) {
                    // Avoid re-adding the same default group.
                    $addCtaGroupId = null;
                }
            }
            if ($addCtaGroupId !== null && $addCtaGroupId > 0) {
                if ($ctaRepo === null) $ctaRepo = new PdoCtaRepository($this->pdo);
                $extra = $ctaRepo->getByGroupId($addCtaGroupId);
                if ($extra) {
                    $ctas = $this->mergeCtas($ctas, $extra);
                }
            }
            $ctas = $this->applyCtaInclusions($ctas, $overrides);
        }

        if (!empty($ctas)) {
            $ctas = $this->applyCtaExclusions($ctas, $overrides);
            $ctas = $this->applyCtaOverrides($ctas, $overrides);
            $ctas = $this->hydrateArticleCtas($ctas);
        }
        $this->markTiming('load_ctas', $ctaStartedAt);

        $setsStartedAt = microtime(true);
        $thumbsEnabled = $this->shouldUseThumbs($items);
        $setIds = $this->findPlaylistInstanceSetIds($instance->id ?? 0);
        $this->markTiming('load_sets', $setsStartedAt);
        $this->lastTiming['total'] = round((microtime(true) - $startedAt) * 1000, 1);

        // 5. Return full playback plan
        $displayTitle = $instance->displayTitle ?? $instance->instanceName ?? $playlist->title;
        return [
            'playlist_instance_id' => $instance->id,
            'playlist_id'          => $playlist->playlist_id,
            'title'                => $playlist->title,
            'display_title'        => $displayTitle,
            'type'                 => $playlist->type,
            'total_items'          => count($items),
            'start_index'          => $startIndex,
            'items'                => $items,
            'ctas'                 => $ctas,
            'cta_context_key'      => $instance->ctaContextKey,
            'audience'             => $instance->audience,
            'palette_viewer_cta_group_id' => $instance->paletteViewerCtaGroupId,
            'thumbs_enabled'       => $thumbsEnabled,
            'demo_enabled'         => $instance->demoEnabled,
            'share_enabled'        => $instance->shareEnabled,
            'share_title'          => $instance->shareTitle,
            'share_description'    => $instance->shareDescription,
            'share_image_url'      => $instance->shareImageUrl,
            'skip_intro_on_replay' => $instance->skipIntroOnReplay,
            'hide_stars'           => $instance->hideStars,
            'playlist_instance_set_ids' => $setIds,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function getLastTiming(): array
    {
        return $this->lastTiming;
    }

    /**
     * @return PlaylistItem[]
     */
    private function flattenItems(Playlist $playlist): array
    {
        $flat = [];
        foreach ($playlist->steps as $step) {
            foreach ($step->items as $item) {
                $flat[] = $item;
            }
        }
        return $flat;
    }

    private function normalizeStartIndex(?int $start, int $count): int
    {
        if ($start === null || $start < 0 || $start >= $count) {
            return 0;
        }
        return $start;
    }

    /**
     * @param PlaylistItem[] $items
     */
    private function hydrateItemImages(array $items): void
    {
        $photoIds = [];
        $assetIds = [];
        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) continue;
            $photoId = $item->photo_library_id ?? null;
            $imageUrl = (string)($item->image_url ?? '');
            if ($photoId) {
                $photoIds[$photoId] = true;
                continue;
            }
            $assetId = $this->extractAssetId($imageUrl);
            if ($assetId !== '') {
                $assetIds[$assetId] = true;
            }
        }

        $photoMetaMap = $this->loadPhotoLibraryMeta(array_keys($photoIds));
        $assetUrlMap = $this->loadAssetVariantUrls(array_keys($assetIds));

        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) continue;
            $imageUrl = (string)($item->image_url ?? '');
            $photoId = $item->photo_library_id ?? null;
            if ($photoId) {
                $meta = $photoMetaMap[(int)$photoId] ?? null;
                $resolved = (string)($meta['url'] ?? '');
                if ($resolved !== '') {
                    $item->image_url = "photo:{$photoId}|{$resolved}";
                }
                $resolvedSetMeta = null;
                if (empty($item->saved_palette_set_id) && !empty($item->palette_hash)) {
                    $resolvedSetMeta = $this->resolveSavedPaletteSetForPhoto(
                        (int)$photoId,
                        (string)$item->palette_hash
                    );
                }
                if (empty($item->palette_hash) && !empty($meta['palette_hash'])) {
                    $item->palette_hash = (string)$meta['palette_hash'];
                }
                if (empty($item->saved_palette_set_id) && !empty($resolvedSetMeta['saved_palette_set_id'])) {
                    $item->saved_palette_set_id = (int)$resolvedSetMeta['saved_palette_set_id'];
                } elseif (empty($item->saved_palette_set_id) && !empty($meta['saved_palette_set_id'])) {
                    $item->saved_palette_set_id = (int)$meta['saved_palette_set_id'];
                }
                if (empty($item->saved_palette_photo_type) && !empty($resolvedSetMeta['saved_palette_photo_type'])) {
                    $item->saved_palette_photo_type = (string)$resolvedSetMeta['saved_palette_photo_type'];
                } elseif (empty($item->saved_palette_photo_type) && !empty($meta['saved_palette_photo_type'])) {
                    $item->saved_palette_photo_type = (string)$meta['saved_palette_photo_type'];
                }
                continue;
            }

            $assetId = $this->extractAssetId($imageUrl);
            if ($assetId === '') continue;
            $resolved = $assetUrlMap[$assetId] ?? '';
            if ($resolved !== '') {
                $item->image_url = $resolved;
            }
        }
    }

    /**
     * @param int[] $photoIds
     * @return array<int, array{url:string,palette_hash:?string,saved_palette_set_id:?int,saved_palette_photo_type:?string}>
     */
    private function loadPhotoLibraryMeta(array $photoIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $photoIds)));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT
                pl.photo_library_id,
                pl.rel_path,
                pl.updated_at,
                (
                  SELECT spalette.palette_hash
                    FROM saved_palette_set_photos spsp
                    JOIN saved_palette_sets sps
                      ON sps.id = spsp.saved_palette_set_id
                    JOIN saved_palettes spalette
                      ON spalette.id = sps.saved_palette_id
                   WHERE spsp.photo_library_id = pl.photo_library_id
                   ORDER BY sps.is_default DESC, spsp.id ASC
                   LIMIT 1
                ) AS palette_hash,
                (
                  SELECT sps.id
                    FROM saved_palette_set_photos spsp
                    JOIN saved_palette_sets sps
                      ON sps.id = spsp.saved_palette_set_id
                   WHERE spsp.photo_library_id = pl.photo_library_id
                   ORDER BY sps.is_default DESC, spsp.id ASC
                   LIMIT 1
                ) AS saved_palette_set_id,
                (
                  SELECT spsp.photo_type
                    FROM saved_palette_set_photos spsp
                    JOIN saved_palette_sets sps
                      ON sps.id = spsp.saved_palette_set_id
                   WHERE spsp.photo_library_id = pl.photo_library_id
                   ORDER BY sps.is_default DESC, spsp.id ASC
                   LIMIT 1
                ) AS saved_palette_photo_type
             FROM photo_library pl
             WHERE pl.photo_library_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relPath = (string)($row['rel_path'] ?? '');
            $updatedAt = (string)($row['updated_at'] ?? '');
            $map[(int)$row['photo_library_id']] = [
                'url' => $this->appendCacheBuster($relPath, $updatedAt),
                'palette_hash' => isset($row['palette_hash']) && $row['palette_hash'] !== '' ? (string)$row['palette_hash'] : null,
                'saved_palette_set_id' => isset($row['saved_palette_set_id']) && (int)$row['saved_palette_set_id'] > 0 ? (int)$row['saved_palette_set_id'] : null,
                'saved_palette_photo_type' => isset($row['saved_palette_photo_type']) && $row['saved_palette_photo_type'] !== '' ? strtolower((string)$row['saved_palette_photo_type']) : null,
            ];
        }
        return $map;
    }

    /**
     * @return array{saved_palette_set_id:?int,saved_palette_photo_type:?string}|null
     */
    private function resolveSavedPaletteSetForPhoto(int $photoLibraryId, string $paletteHash): ?array
    {
        $photoLibraryId = (int)$photoLibraryId;
        $paletteHash = trim($paletteHash);
        if ($photoLibraryId <= 0 || $paletteHash === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                sps.id AS saved_palette_set_id,
                spsp.photo_type AS saved_palette_photo_type
             FROM saved_palette_set_photos spsp
             JOIN saved_palette_sets sps
               ON sps.id = spsp.saved_palette_set_id
             JOIN saved_palettes sp
               ON sp.id = sps.saved_palette_id
             WHERE spsp.photo_library_id = :photo_library_id
               AND sp.palette_hash = :palette_hash
             ORDER BY
               CASE
                 WHEN spsp.photo_type = 'full' THEN 0
                 WHEN spsp.photo_type = 'zoom' THEN 1
                 WHEN spsp.photo_type = 'before' THEN 2
                 ELSE 3
               END ASC,
               sps.is_default DESC,
               spsp.id ASC
             LIMIT 1"
        );
        $stmt->execute([
            ':photo_library_id' => $photoLibraryId,
            ':palette_hash' => $paletteHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'saved_palette_set_id' => isset($row['saved_palette_set_id']) && (int)$row['saved_palette_set_id'] > 0
                ? (int)$row['saved_palette_set_id']
                : null,
            'saved_palette_photo_type' => isset($row['saved_palette_photo_type']) && $row['saved_palette_photo_type'] !== ''
                ? strtolower((string)$row['saved_palette_photo_type'])
                : null,
        ];
    }

    /**
     * @param string[] $assetIds
     * @return array<string, string>
     */
    private function loadAssetVariantUrls(array $assetIds): array
    {
        $ids = array_values(array_filter(array_map(
            static fn($id) => trim((string)$id),
            $assetIds
        )));
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<SQL
            SELECT
                p.asset_id,
                v.path,
                v.kind,
                v.role
            FROM photos p
            JOIN photos_variants v
              ON v.photo_id = p.id
            WHERE p.asset_id IN ({$placeholders})
              AND (
                v.kind = 'thumb'
                OR (v.kind = 'prepared' AND v.role = '')
                OR v.kind = 'prepared_base'
                OR (v.kind = 'repaired' AND v.role = '')
                OR v.kind = 'repaired_base'
              )
            ORDER BY
                CASE
                    WHEN v.kind = 'thumb' THEN 0
                    WHEN v.kind = 'prepared' AND v.role = '' THEN 1
                    WHEN v.kind = 'prepared_base' THEN 2
                    WHEN v.kind = 'repaired' AND v.role = '' THEN 3
                    ELSE 4
                END ASC,
                v.id ASC
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assetId = trim((string)($row['asset_id'] ?? ''));
            if ($assetId === '' || isset($map[$assetId])) continue;
            $path = trim((string)($row['path'] ?? ''));
            if ($path === '') continue;
            $map[$assetId] = $path;
        }
        return $map;
    }

    private function extractAssetId(string $value): string
    {
        if (!str_starts_with($value, 'asset:')) return '';
        return trim(substr($value, strlen('asset:')));
    }

    private function isPhotoRefWithoutUrl(string $value): bool
    {
        if (!str_starts_with($value, 'photo:')) return false;
        $parts = explode('|', $value, 2);
        if (count($parts) < 2) return true;
        return trim($parts[1]) === '';
    }

    private function appendCacheBuster(string $url, string $updatedAt): string
    {
        $url = trim($url);
        if ($url === '' || $updatedAt === '') {
            return $url;
        }

        $stamp = strtotime($updatedAt);
        if ($stamp === false || $stamp <= 0) {
            return $url;
        }

        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'v=' . $stamp;
    }

    /**
     * @param PlaylistItem[] $items
     */
    private function shouldUseThumbs(array $items): bool
    {
        $count = 0;
        foreach ($items as $item) {
            $type = strtolower((string)($item->type ?? 'normal'));
            if (in_array($type, ['intro', 'before', 'text', 'non-palette'], true)) {
                continue;
            }
            if (!empty($item->exclude_from_thumbs)) continue;
            if (strtolower((string)($item->saved_palette_photo_type ?? '')) === 'before') continue;
            $hasPalette = !empty($item->ap_id) || !empty($item->palette_hash);
            if (!$hasPalette) continue;
            $count++;
            if ($count > 1) return true;
        }
        return false;
    }

    /**
     * @return int[]
     */
    private function findPlaylistInstanceSetIds(int $playlistInstanceId): array
    {
        if ($playlistInstanceId <= 0) return [];
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT playlist_instance_set_id
             FROM playlist_instance_set_items
             WHERE playlist_instance_id = :pid
             ORDER BY playlist_instance_set_id ASC"
        );
        $stmt->execute(['pid' => $playlistInstanceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_map('intval', $rows));
    }

    /**
     * @param array<int, array<string, mixed>> $ctas
     * @param array<string, array<string, mixed>> $overrides
     * @return array<int, array<string, mixed>>
     */
    private function applyCtaOverrides(array $ctas, array $overrides): array
    {
        foreach ($ctas as $idx => $cta) {
            $ctaId = $cta['cta_id'] ?? null;
            if ($ctaId === null) continue;
            $key = (string)$ctaId;
            if (!isset($overrides[$key]) || !is_array($overrides[$key])) continue;

            $base = [];
            if (!empty($cta['params'])) {
                $decoded = json_decode((string)$cta['params'], true);
                if (is_array($decoded)) $base = $decoded;
            }
            $merged = array_merge($base, $overrides[$key]);
            $cta['params'] = json_encode($merged, JSON_UNESCAPED_SLASHES);
            $ctas[$idx] = $cta;
        }

        return $ctas;
    }

    /**
     * @param array<int, array<string, mixed>> $base
     * @param array<int, array<string, mixed>> $extra
     * @return array<int, array<string, mixed>>
     */
    private function mergeCtas(array $base, array $extra): array
    {
        if (empty($base)) return $extra;
        if (empty($extra)) return $base;

        $seen = [];
        foreach ($base as $cta) {
            if (isset($cta['cta_id'])) $seen[(string)$cta['cta_id']] = true;
        }
        foreach ($extra as $cta) {
            $id = $cta['cta_id'] ?? null;
            if ($id === null) {
                $base[] = $cta;
                continue;
            }
            $key = (string)$id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $base[] = $cta;
        }

        return $base;
    }

    private function applyCtaInclusions(array $ctas, array $overrides): array
    {
        $extra = $overrides['_cta_ids'] ?? [];
        if (!is_array($extra) || !$extra) return $ctas;
        $repo = new PdoCtaRepository($this->pdo);
        $rows = $repo->getByIds($extra);
        if (!$rows) return $ctas;
        $seen = [];
        foreach ($ctas as $cta) {
            $id = $cta['cta_id'] ?? null;
            if ($id !== null) $seen[(string)$id] = true;
        }
        foreach ($rows as $row) {
            $id = $row['cta_id'] ?? null;
            if ($id !== null && isset($seen[(string)$id])) continue;
            if ($id !== null) $seen[(string)$id] = true;
            $ctas[] = $row;
        }
        return $ctas;
    }

    private function applyCtaExclusions(array $ctas, array $overrides): array
    {
        $exclude = $overrides['_cta_exclude_ids'] ?? [];
        if (!is_array($exclude) || !$exclude) return $ctas;
        $set = array_fill_keys(array_map('strval', $exclude), true);
        return array_values(array_filter($ctas, function ($cta) use ($set) {
            $id = $cta['cta_id'] ?? null;
            return $id === null ? true : !isset($set[(string)$id]);
        }));
    }

    private function hydrateArticleCtas(array $ctas): array
    {
        $repo = new PdoArticleRepository($this->pdo);
        foreach ($ctas as $idx => $cta) {
            $actionKey = (string)($cta['action_key'] ?? $cta['action'] ?? $cta['key'] ?? '');
            if ($actionKey !== 'article_link') {
                continue;
            }
            $params = $this->decodeParams($cta['params'] ?? null);
            $articleId = (int)($params['article_id'] ?? $params['articleId'] ?? 0);
            if ($articleId <= 0) {
                continue;
            }
            $article = $repo->getArticleById($articleId);
            if (!$article) {
                continue;
            }
            if (empty($params['title']) && !empty($article['title'])) {
                $params['title'] = $article['title'];
            }
            if (empty($params['dek']) && !empty($article['dek'])) {
                $params['dek'] = $article['dek'];
            }
            if (empty($params['url'])) {
                $params['url'] = "/articles/{$articleId}";
            }
            $cta['params'] = json_encode($params, JSON_UNESCAPED_SLASHES);
            $ctas[$idx] = $cta;
        }
        return $ctas;
    }

    private function decodeParams(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function markTiming(string $key, float $startedAt): void
    {
        $this->lastTiming[$key] = round((microtime(true) - $startedAt) * 1000, 1);
    }
}
