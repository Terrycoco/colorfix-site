<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AssetCreatorProposalService
{
    public function __construct(
        private PDO $pdo,
        private string $baseUrl = ''
    ) {}

    public function propose(array $payload): array
    {
        $creatorKey = trim((string)($payload['creator_key'] ?? ''));
        $sourceType = trim((string)($payload['source_type'] ?? 'playlist'));
        $sourceId = (int)($payload['source_id'] ?? $payload['playlist_id'] ?? 0);

        if ($creatorKey !== 'pinterest.before_after_composite') {
            throw new RuntimeException('Unsupported creator type');
        }
        if ($sourceType !== 'playlist') {
            throw new RuntimeException('Only playlist sources are supported right now');
        }
        if ($sourceId <= 0) {
            throw new RuntimeException('playlist_id required');
        }

        $playlist = $this->playlist($sourceId);
        if (!$playlist) {
            throw new RuntimeException('Playlist not found');
        }

        $warnings = [];
        $items = $this->playlistItems($sourceId);
        $hasAnalyzerRoles = $this->hasAnalyzerRoles($items);
        $pairs = $this->pairsFromAnalyzerRoles($items, $playlist);
        if ($pairs) {
            $warnings[] = 'Using playlist analyzer roles.';
        } elseif ($hasAnalyzerRoles) {
            $warnings[] = 'Analyzer roles were found, but no complete before/after pair was marked.';
        } else {
            $pairs = $this->pairsFromSavedPaletteSets($items, $playlist);
        }
        if (!$pairs && !$hasAnalyzerRoles) {
            $pairs = $this->fallbackPairsFromPlaylistOrder($items, $playlist);
            if ($pairs) {
                $warnings[] = 'No saved palette before/after pairs were found. These are low-confidence playlist-order guesses.';
            }
        }

        if (!$pairs) {
            $warnings[] = 'No before/after pairs found. Add pairs manually or mark photos as before/full on palette sets.';
        }

        return [
            'creator_key' => $creatorKey,
            'source_type' => 'playlist',
            'source_id' => $sourceId,
            'playlist' => [
                'playlist_id' => (int)$playlist['playlist_id'],
                'title' => (string)($playlist['title'] ?? ''),
                'type' => (string)($playlist['type'] ?? ''),
            ],
            'instructions' => [
                'asset_type' => 'pin_composite',
                'layout' => 'before_after_vertical',
                'input_strategy' => $hasAnalyzerRoles ? 'playlist_analyzer_roles' : 'saved_palette_set_photo_roles',
            ],
            'pairs' => array_values($pairs),
            'ignored' => $this->ignoredItems($items, $pairs),
            'warnings' => $warnings,
        ];
    }

    private function playlist(int $playlistId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT playlist_id, title, type
               FROM playlists
              WHERE playlist_id = :playlist_id
              LIMIT 1'
        );
        $stmt->execute(['playlist_id' => $playlistId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function playlistItems(int $playlistId): array
    {
        $hasPhotoLibraryId = $this->columnExists('playlist_items', 'photo_library_id');
        $hasSavedPaletteSetId = $this->columnExists('playlist_items', 'saved_palette_set_id');
        $hasAnalyzerRole = $this->columnExists('playlist_items', 'analyzer_role');
        $photoSelect = $hasPhotoLibraryId ? 'photo_library_id' : 'NULL AS photo_library_id';
        $setSelect = $hasSavedPaletteSetId ? 'saved_palette_set_id' : 'NULL AS saved_palette_set_id';
        $analyzerRoleSelect = $hasAnalyzerRole ? 'analyzer_role' : "'ignore' AS analyzer_role";

        $stmt = $this->pdo->prepare(
            "SELECT playlist_item_id,
                    playlist_id,
                    order_index,
                    item_type,
                    title,
                    subtitle,
                    image_url,
                    {$photoSelect},
                    {$setSelect},
                    {$analyzerRoleSelect}
               FROM playlist_items
              WHERE playlist_id = :playlist_id
                AND is_active = 1
           ORDER BY order_index ASC, playlist_item_id ASC"
        );
        $stmt->execute(['playlist_id' => $playlistId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function pairsFromAnalyzerRoles(array $items, array $playlist): array
    {
        $beforeItems = [];
        $afterItems = [];
        foreach ($items as $item) {
            $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
            if ($role === 'before') {
                $beforeItems[] = $item;
            } elseif ($role === 'after') {
                $afterItems[] = $item;
            }
        }

        $count = min(count($beforeItems), count($afterItems));
        if ($count <= 0) {
            return [];
        }

        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $before = $beforeItems[$i];
            $after = $afterItems[$i];
            $sort = $i + 1;
            $title = trim((string)($after['title'] ?? '')) ?: trim((string)($playlist['title'] ?? '')) ?: 'Pin ' . $sort;
            $description = $this->defaultDescription((string)($playlist['title'] ?? ''), $title);
            $pairs[] = [
                'pair_key' => 'analyzer-items-' . (int)$before['playlist_item_id'] . '-' . (int)$after['playlist_item_id'],
                'include' => true,
                'sort_order' => $sort,
                'source' => 'playlist_analyzer_roles',
                'confidence' => 1.0,
                'saved_palette_set_id' => null,
                'search_title' => $this->defaultSearchTitle($title, (string)($playlist['title'] ?? '')),
                'description' => $description,
                'title' => $title,
                'caption' => $description,
                'before' => $this->playlistItemAssetPayload($before),
                'after' => $this->playlistItemAssetPayload($after),
            ];
        }

        return $pairs;
    }

    private function hasAnalyzerRoles(array $items): bool
    {
        foreach ($items as $item) {
            $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
            if (in_array($role, ['before', 'after'], true)) {
                return true;
            }
        }
        return false;
    }

    private function pairsFromSavedPaletteSets(array $items, array $playlist): array
    {
        $setIds = [];
        foreach ($items as $item) {
            $setId = (int)($item['saved_palette_set_id'] ?? 0);
            if ($setId > 0) {
                $setIds[$setId] = $setId;
            }
        }
        if (!$setIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($setIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT spsp.id AS saved_palette_set_photo_id,
                    spsp.saved_palette_set_id,
                    spsp.photo_library_id,
                    spsp.rel_path AS set_rel_path,
                    spsp.photo_type,
                    spsp.caption,
                    spsp.alt_text,
                    spsp.order_index,
                    sps.title AS set_title,
                    sp.nickname,
                    sp.display_title,
                    pl.title AS photo_title,
                    pl.rel_path AS photo_rel_path,
                    COALESCE(al_direct.asset_library_id, al_legacy.asset_library_id, pl.asset_library_id) AS existing_asset_library_id,
                    COALESCE(pl.title, al_direct.title, al_legacy.title) AS asset_title,
                    COALESCE(pl.rel_path, spsp.rel_path, al_direct.rel_path, al_legacy.rel_path) AS asset_rel_path,
                    COALESCE(al_direct.client_id, al_legacy.client_id, pl.client_id) AS client_id,
                    pl.photo_library_id AS permission_photo_library_id,
                    c.name AS client_name,
                    c.email AS client_email,
                    COALESCE(NULLIF(pl.photo_permission_status, ''), c.photo_permission_status, 'unknown') AS photo_permission_status
               FROM saved_palette_set_photos spsp
               JOIN saved_palette_sets sps
                 ON sps.id = spsp.saved_palette_set_id
               JOIN saved_palettes sp
                 ON sp.id = sps.saved_palette_id
          LEFT JOIN photo_library pl
                 ON pl.photo_library_id = spsp.photo_library_id
          LEFT JOIN asset_library al_direct
                 ON al_direct.asset_library_id = pl.asset_library_id
          LEFT JOIN asset_library al_legacy
                 ON al_legacy.legacy_photo_library_id = pl.photo_library_id
          LEFT JOIN clients c
                 ON c.id = COALESCE(al_direct.client_id, al_legacy.client_id, pl.client_id)
              WHERE spsp.saved_palette_set_id IN ({$placeholders})
           ORDER BY spsp.saved_palette_set_id ASC,
                    CASE
                      WHEN spsp.photo_type = 'before' THEN 0
                      WHEN spsp.photo_type IN ('full', 'main', 'after') THEN 1
                      ELSE 2
                    END,
                    spsp.order_index ASC,
                    spsp.id ASC"
        );
        $stmt->execute(array_values($setIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bySet = [];
        foreach ($rows as $row) {
            $setId = (int)$row['saved_palette_set_id'];
            $bySet[$setId][] = $row;
        }

        $pairs = [];
        $sort = 1;
        foreach ($bySet as $setId => $photos) {
            $before = null;
            $after = null;
            foreach ($photos as $photo) {
                $type = strtolower((string)($photo['photo_type'] ?? ''));
                if ($type === 'before' && !$before) {
                    $before = $photo;
                }
                if (in_array($type, ['full', 'main', 'after'], true) && !$after) {
                    $after = $photo;
                }
            }
            if (!$before || !$after) {
                continue;
            }

            $title = trim((string)($after['display_title'] ?? ''))
                ?: trim((string)($after['nickname'] ?? ''))
                ?: trim((string)($after['set_title'] ?? ''));
            $description = $this->defaultDescription((string)($playlist['title'] ?? ''), $title);

            $pairs[] = [
                'pair_key' => 'set-' . $setId,
                'include' => true,
                'sort_order' => $sort++,
                'source' => 'saved_palette_set',
                'confidence' => 0.95,
                'saved_palette_set_id' => $setId,
                'search_title' => $this->defaultSearchTitle($title, (string)($playlist['title'] ?? '')),
                'description' => $description,
                'title' => $title,
                'caption' => $description,
                'before' => $this->assetPayload($before),
                'after' => $this->assetPayload($after),
            ];
        }

        return $pairs;
    }

    private function fallbackPairsFromPlaylistOrder(array $items, array $playlist): array
    {
        $photoItems = [];
        foreach ($items as $item) {
            if ((int)($item['photo_library_id'] ?? 0) > 0 || trim((string)($item['image_url'] ?? '')) !== '') {
                $photoItems[] = $item;
            }
        }

        $pairs = [];
        $sort = 1;
        for ($i = 0; $i + 1 < count($photoItems); $i += 2) {
            $before = $photoItems[$i];
            $after = $photoItems[$i + 1];
            $title = trim((string)($after['title'] ?? '')) ?: 'Pin ' . $sort;
            $description = $this->defaultDescription((string)($playlist['title'] ?? ''), $title);
            $pairs[] = [
                'pair_key' => 'playlist-items-' . (int)$before['playlist_item_id'] . '-' . (int)$after['playlist_item_id'],
                'include' => true,
                'sort_order' => $sort,
                'source' => 'playlist_order_guess',
                'confidence' => 0.35,
                'saved_palette_set_id' => null,
                'search_title' => $this->defaultSearchTitle($title, (string)($playlist['title'] ?? '')),
                'description' => $description,
                'title' => $title,
                'caption' => $description,
                'before' => $this->playlistItemAssetPayload($before),
                'after' => $this->playlistItemAssetPayload($after),
            ];
            $sort++;
        }
        return $pairs;
    }

    private function ignoredItems(array $items, array $pairs): array
    {
        $used = [];
        foreach ($pairs as $pair) {
            foreach (['before', 'after'] as $side) {
                $photoId = (int)($pair[$side]['photo_library_id'] ?? 0);
                if ($photoId > 0) {
                    $used[$photoId] = true;
                }
            }
        }

        $ignored = [];
        foreach ($items as $item) {
            $photoId = (int)($item['photo_library_id'] ?? 0);
            if ($photoId <= 0 || isset($used[$photoId])) {
                continue;
            }
            $ignored[] = [
                'playlist_item_id' => (int)($item['playlist_item_id'] ?? 0),
                'photo_library_id' => $photoId,
                'title' => (string)($item['title'] ?? ''),
                'reason' => 'Not part of a proposed before/after pair.',
            ];
        }
        return $ignored;
    }

    private function assetPayload(array $row): array
    {
        $relPath = trim((string)($row['asset_rel_path'] ?? $row['photo_rel_path'] ?? $row['set_rel_path'] ?? ''));
        return [
            'asset_library_id' => null,
            'existing_asset_library_id' => isset($row['existing_asset_library_id']) && (int)$row['existing_asset_library_id'] > 0 ? (int)$row['existing_asset_library_id'] : null,
            'photo_library_id' => isset($row['photo_library_id']) && (int)$row['photo_library_id'] > 0 ? (int)$row['photo_library_id'] : null,
            'permission_photo_library_id' => isset($row['permission_photo_library_id']) && (int)$row['permission_photo_library_id'] > 0 ? (int)$row['permission_photo_library_id'] : null,
            'photo_type' => (string)($row['photo_type'] ?? ''),
            'title' => (string)($row['asset_title'] ?? $row['photo_title'] ?? ''),
            'caption' => (string)($row['caption'] ?? ''),
            'rel_path' => $relPath,
            'public_url' => $this->publicUrl($relPath),
            'client_id' => isset($row['client_id']) && (int)$row['client_id'] > 0 ? (int)$row['client_id'] : null,
            'client_name' => (string)($row['client_name'] ?? ''),
            'client_email' => (string)($row['client_email'] ?? ''),
            'photo_permission_status' => (string)($row['photo_permission_status'] ?? 'unknown'),
        ];
    }

    private function playlistItemAssetPayload(array $item): array
    {
        $photoId = (int)($item['photo_library_id'] ?? 0);
        $asset = $photoId > 0 ? $this->photoForCreator($photoId) : null;
        $relPath = trim((string)($asset['rel_path'] ?? $item['image_url'] ?? ''));
        return [
            'asset_library_id' => null,
            'existing_asset_library_id' => isset($asset['existing_asset_library_id']) ? (int)$asset['existing_asset_library_id'] : null,
            'photo_library_id' => $photoId > 0 ? $photoId : null,
            'permission_photo_library_id' => $photoId > 0 ? $photoId : null,
            'photo_type' => '',
            'title' => (string)($asset['title'] ?? $item['title'] ?? ''),
            'caption' => (string)($item['subtitle'] ?? ''),
            'rel_path' => $relPath,
            'public_url' => $this->publicUrl($relPath),
            'client_id' => isset($asset['client_id']) && (int)$asset['client_id'] > 0 ? (int)$asset['client_id'] : null,
            'client_name' => (string)($asset['client_name'] ?? ''),
            'client_email' => (string)($asset['client_email'] ?? ''),
            'photo_permission_status' => (string)($asset['photo_permission_status'] ?? 'unknown'),
        ];
    }

    private function photoForCreator(int $photoLibraryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(al_direct.asset_library_id, al_legacy.asset_library_id, pl.asset_library_id) AS existing_asset_library_id,
                    pl.rel_path AS rel_path,
                    pl.title AS title,
                    COALESCE(al_direct.client_id, al_legacy.client_id, pl.client_id) AS client_id,
                    pl.photo_library_id AS permission_photo_library_id,
                    c.name AS client_name,
                    c.email AS client_email,
                    COALESCE(NULLIF(pl.photo_permission_status, \'\'), c.photo_permission_status, \'unknown\') AS photo_permission_status
               FROM photo_library pl
          LEFT JOIN asset_library al_direct
                 ON al_direct.asset_library_id = pl.asset_library_id
          LEFT JOIN asset_library al_legacy
                 ON al_legacy.legacy_photo_library_id = pl.photo_library_id
          LEFT JOIN clients c
                 ON c.id = COALESCE(al_direct.client_id, al_legacy.client_id, pl.client_id)
              WHERE pl.photo_library_id = :photo_library_id
              LIMIT 1'
        );
        $stmt->execute(['photo_library_id' => $photoLibraryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function defaultSearchTitle(string $pairTitle, string $playlistTitle): string
    {
        $pairTitle = trim($pairTitle);
        if ($pairTitle !== '') {
            return $pairTitle;
        }
        $playlistTitle = trim($playlistTitle);
        return $playlistTitle !== '' ? $playlistTitle : 'ColorFix before and after';
    }

    private function defaultDescription(string $playlistTitle, string $pairTitle): string
    {
        return 'This [house style / room type] was struggling with [problem]. By [what you changed], the eye is now drawn toward [focal point or benefit]. See the complete before-and-after makeover, color palette, and design reasoning.';
    }

    private function publicUrl(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '' || preg_match('/^https?:\/\//i', $relPath)) {
            return $relPath;
        }
        $base = rtrim($this->baseUrl, '/');
        return $base !== '' ? $base . '/' . ltrim($relPath, '/') : $relPath;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column'
        );
        $stmt->execute([
            'table' => $table,
            'column' => $column,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
