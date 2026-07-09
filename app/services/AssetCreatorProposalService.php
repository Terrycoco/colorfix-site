<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPlaylistInstanceUrlReservationRepository;
use App\Repos\PdoPublishingDefaultTemplateRepository;
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

        if (!in_array($creatorKey, ['pinterest.before_after_composite', 'youtube.playlist_video'], true)) {
            throw new RuntimeException('Unsupported channel');
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

        if ($creatorKey === 'youtube.playlist_video') {
            return $this->proposeYoutubePlaylistVideo($creatorKey, $sourceId, $playlist, $payload);
        }

        $reservationRepo = new PdoPlaylistInstanceUrlReservationRepository($this->pdo);
        $reservation = $reservationRepo->reserveForPlaylistChannel(
            $sourceId,
            'pinterest',
            trim((string)($playlist['title'] ?? '')) ?: "Playlist {$sourceId}",
            $this->baseUrl
        );

        $warnings = [];
        $items = $this->playlistItems($sourceId);
        $hasAnalyzerRoles = $this->hasAnalyzerRoles($items);
        $rows = $this->pinRowsFromAnalyzerRoles($items, $playlist);
        if (!$rows && $hasAnalyzerRoles) {
            $warnings[] = 'Analyzer roles were found, but no publishable pin rows were found.';
        } elseif (!$hasAnalyzerRoles) {
            $rows = $this->pairsFromSavedPaletteSets($items, $playlist);
        }
        if (!$rows && !$hasAnalyzerRoles) {
            $rows = $this->fallbackPairsFromPlaylistOrder($items, $playlist);
            if ($rows) {
                $warnings[] = 'No saved palette before/after pairs were found. These are low-confidence playlist-order guesses.';
            }
        }

        if (!$rows) {
            $warnings[] = 'No pin rows found. Tag playlist photos as before/after/single or add rows manually.';
        }
        $rows = $this->applyPayloadDefaults($rows, $payload, $playlist, $reservation, 'pinterest', 'pinterest_pin');
        $defaultDescription = $this->defaultDescriptionFor(
            $payload,
            'pinterest',
            'pinterest_pin',
            (string)($playlist['type'] ?? ''),
            $playlist,
            $reservation
        );

        return [
            'creator_key' => $creatorKey,
            'source_type' => 'playlist',
            'source_id' => $sourceId,
            'playlist' => [
                'playlist_id' => (int)$playlist['playlist_id'],
                'title' => (string)($playlist['title'] ?? ''),
                'type' => (string)($playlist['type'] ?? ''),
            ],
            'url_reservation' => $this->reservationPayload($reservation),
            'instructions' => [
                'asset_type' => 'pinterest_pin',
                'layout' => 'platform_specific',
                'input_strategy' => $hasAnalyzerRoles ? 'playlist_analyzer_roles' : 'saved_palette_set_photo_roles',
                'playlist_instance_url_reservation' => $this->reservationPayload($reservation),
                'analyzer_defaults' => [
                    'description' => $defaultDescription,
                    'template_context' => [
                        'platform' => 'pinterest',
                        'asset_type' => 'pinterest_pin',
                        'playlist_type' => (string)($playlist['type'] ?? ''),
                    ],
                ],
            ],
            'pin_rows' => array_values($rows),
            'pairs' => array_values($rows),
            'ignored' => $this->ignoredItems($items, $rows),
            'warnings' => $warnings,
        ];
    }

    private function proposeYoutubePlaylistVideo(string $creatorKey, int $playlistId, array $playlist, array $payload): array
    {
        $reservationRepo = new PdoPlaylistInstanceUrlReservationRepository($this->pdo);
        $reservation = $reservationRepo->reserveForPlaylistChannel(
            $playlistId,
            'youtube',
            trim((string)($playlist['title'] ?? '')) ?: "Playlist {$playlistId}",
            $this->baseUrl
        );
        $items = array_values(array_filter(
            $this->playlistItems($playlistId),
            fn(array $item): bool => $this->youtubeEnabled($item)
        ));

        $warnings = [];
        if (!$items) {
            $warnings[] = 'No YouTube-enabled playlist rows were found. Turn on yt for at least one playlist item.';
        }

        $title = trim((string)($payload['default_title'] ?? ''))
            ?: trim((string)($playlist['title'] ?? ''))
            ?: "Playlist {$playlistId}";
        $description = $this->defaultDescriptionFor(
            $payload,
            'youtube',
            'youtube_playlist_video',
            (string)($playlist['type'] ?? ''),
            $playlist,
            $reservation
        );
        $music = $this->musicPayloadFromRequest($payload);

        $slides = array_map(function (array $item, int $index): array {
            $asset = $this->playlistItemAssetPayload($item);
            return [
                'playlist_item_id' => (int)($item['playlist_item_id'] ?? 0),
                'sort_order' => $index + 1,
                'item_type' => (string)($item['item_type'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'subtitle' => (string)($item['subtitle'] ?? ''),
                'body' => (string)($item['body'] ?? ''),
                'duration_ms' => isset($item['duration_ms']) ? (int)$item['duration_ms'] : null,
                'asset' => $asset,
            ];
        }, $items, array_keys($items));

        $row = [
            'pair_key' => 'youtube-playlist-' . $playlistId,
            'pin_type' => 'youtube_video',
            'asset_type' => 'youtube_playlist_video',
            'include' => count($items) > 0,
            'sort_order' => 1,
            'source' => 'playlist_yt_rows',
            'confidence' => 1,
            'search_title' => $title,
            'description' => $description,
            'title' => $title,
            'caption' => $description,
            'before' => null,
            'after' => $slides[0]['asset'] ?? null,
            'asset' => $slides[0]['asset'] ?? null,
            'slides' => $slides,
            'slide_count' => count($slides),
            'output' => [
                'format' => 'mp4',
                'width' => 1920,
                'height' => 1080,
                'ratio' => '16:9',
            ],
            'music' => $music,
        ];

        return [
            'creator_key' => $creatorKey,
            'source_type' => 'playlist',
            'source_id' => $playlistId,
            'playlist' => [
                'playlist_id' => (int)$playlist['playlist_id'],
                'title' => (string)($playlist['title'] ?? ''),
                'type' => (string)($playlist['type'] ?? ''),
            ],
            'url_reservation' => $this->reservationPayload($reservation),
            'instructions' => [
                'asset_type' => 'youtube_playlist_video',
                'layout' => 'remotion_video',
                'input_strategy' => 'playlist_yt_rows',
                'playlist_instance_url_reservation' => $this->reservationPayload($reservation),
                'music' => $music,
                'analyzer_defaults' => [
                    'description' => $description,
                    'template_context' => [
                        'platform' => 'youtube',
                        'asset_type' => 'youtube_playlist_video',
                        'playlist_type' => (string)($playlist['type'] ?? ''),
                    ],
                ],
            ],
            'music' => $music,
            'video_rows' => [$row],
            'pairs' => [$row],
            'ignored' => $this->ignoredYoutubeItems($this->playlistItems($playlistId), $items),
            'warnings' => $warnings,
        ];
    }

    private function musicPayloadFromRequest(array $payload): ?array
    {
        $music = is_array($payload['music'] ?? null) ? $payload['music'] : [];
        $assetId = (int)($music['asset_library_id'] ?? $payload['music_asset_library_id'] ?? 0);
        if ($assetId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT asset_library_id, asset_kind, mime_type, rel_path, title
               FROM asset_library
              WHERE asset_library_id = :asset_library_id
                AND asset_kind = \'audio\'
                AND is_inactive = 0
                AND is_retired = 0
              LIMIT 1'
        );
        $stmt->execute([':asset_library_id' => $assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            throw new RuntimeException("Music asset not found or not active audio: #{$assetId}");
        }

        $relPath = (string)($asset['rel_path'] ?? '');
        $base = rtrim($this->baseUrl, '/');
        $publicUrl = preg_match('/^https?:\/\//i', $relPath)
            ? $relPath
            : ($base !== '' ? $base . '/' . ltrim($relPath, '/') : $relPath);
        $volume = (float)($music['volume'] ?? $payload['music_volume'] ?? 0.18);
        if ($volume < 0) {
            $volume = 0;
        } elseif ($volume > 1) {
            $volume = 1;
        }

        return [
            'asset_library_id' => (int)$asset['asset_library_id'],
            'title' => (string)($asset['title'] ?? ''),
            'rel_path' => $relPath,
            'public_url' => $publicUrl,
            'mime_type' => (string)($asset['mime_type'] ?? ''),
            'volume' => $volume,
        ];
    }

    private function reservationPayload(array $reservation): array
    {
        return [
            'reservation_id' => (int)($reservation['playlist_instance_url_reservation_id'] ?? 0),
            'reservation_key' => (string)($reservation['reservation_key'] ?? ''),
            'playlist_id' => isset($reservation['playlist_id']) ? (int)$reservation['playlist_id'] : null,
            'channel' => (string)($reservation['channel'] ?? ''),
            'slug' => (string)($reservation['slug'] ?? ''),
            'path' => (string)($reservation['path'] ?? ''),
            'public_url' => (string)($reservation['public_url'] ?? ''),
            'status' => (string)($reservation['status'] ?? ''),
            'playlist_instance_id' => isset($reservation['playlist_instance_id']) && $reservation['playlist_instance_id'] !== null
                ? (int)$reservation['playlist_instance_id']
                : null,
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
        $hasPin = $this->columnExists('playlist_items', 'pin');
        $hasYt = $this->columnExists('playlist_items', 'yt');
        $hasSite = $this->columnExists('playlist_items', 'site');
        $hasDurationMs = $this->columnExists('playlist_items', 'duration_ms');
        $hasPaletteHash = $this->columnExists('playlist_items', 'palette_hash');
        $hasApId = $this->columnExists('playlist_items', 'ap_id');
        $photoSelect = $hasPhotoLibraryId ? 'pi.photo_library_id' : 'NULL AS photo_library_id';
        $setSelect = $hasSavedPaletteSetId ? 'pi.saved_palette_set_id' : 'NULL AS saved_palette_set_id';
        $analyzerRoleSelect = $hasAnalyzerRole ? 'pi.analyzer_role' : "'ignore' AS analyzer_role";
        $pinSelect = $hasPin ? 'pi.pin' : '1 AS pin';
        $ytSelect = $hasYt ? 'pi.yt' : '1 AS yt';
        $siteSelect = $hasSite ? 'pi.site' : '1 AS site';
        $durationSelect = $hasDurationMs ? 'pi.duration_ms' : 'NULL AS duration_ms';
        $paletteHashSelect = $hasPaletteHash ? 'pi.palette_hash' : 'NULL AS palette_hash';
        $apIdSelect = $hasApId ? 'pi.ap_id' : 'NULL AS ap_id';
        $photoJoin = $hasPhotoLibraryId ? 'LEFT JOIN photo_library pl ON pl.photo_library_id = pi.photo_library_id' : '';
        $photoHasPaletteSelect = $hasPhotoLibraryId ? 'COALESCE(pl.has_palette, 0) AS photo_has_palette' : '0 AS photo_has_palette';
        $savedPaletteIdSelect = $hasSavedPaletteSetId
            ? '(SELECT sps.saved_palette_id FROM saved_palette_sets sps WHERE sps.id = pi.saved_palette_set_id LIMIT 1) AS saved_palette_id'
            : 'NULL AS saved_palette_id';

        $stmt = $this->pdo->prepare(
            "SELECT pi.playlist_item_id,
                    pi.playlist_id,
                    pi.order_index,
                    pi.item_type,
                    pi.title,
                    pi.subtitle,
                    pi.body,
                    pi.image_url,
                    {$paletteHashSelect},
                    {$apIdSelect},
                    {$photoSelect},
                    {$setSelect},
                    {$savedPaletteIdSelect},
                    {$analyzerRoleSelect},
                    {$pinSelect},
                    {$ytSelect},
                    {$siteSelect},
                    {$durationSelect},
                    {$photoHasPaletteSelect}
               FROM playlist_items pi
                    {$photoJoin}
              WHERE pi.playlist_id = :playlist_id
                AND pi.is_active = 1
           ORDER BY pi.order_index ASC, pi.playlist_item_id ASC"
        );
        $stmt->execute(['playlist_id' => $playlistId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function youtubeEnabled(array $item): bool
    {
        $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
        return $role !== 'ignore' && (int)($item['yt'] ?? 0) === 1;
    }

    private function ignoredYoutubeItems(array $allItems, array $includedItems): array
    {
        $used = [];
        foreach ($includedItems as $item) {
            $id = (int)($item['playlist_item_id'] ?? 0);
            if ($id > 0) {
                $used[$id] = true;
            }
        }

        $ignored = [];
        foreach ($allItems as $item) {
            $id = (int)($item['playlist_item_id'] ?? 0);
            if ($id <= 0 || isset($used[$id])) {
                continue;
            }
            $ignored[] = [
                'playlist_item_id' => $id,
                'photo_library_id' => isset($item['photo_library_id']) ? (int)$item['photo_library_id'] : null,
                'title' => (string)($item['title'] ?? ''),
                'reason' => $this->youtubeIgnoreReason($item),
            ];
        }
        return $ignored;
    }

    private function youtubeIgnoreReason(array $item): string
    {
        $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
        $yt = (int)($item['yt'] ?? 0);
        if ($role === 'ignore' && $yt !== 1) {
            return 'Analyzer is ignore and yt is off.';
        }
        if ($role === 'ignore') {
            return 'Analyzer is ignore.';
        }
        if ($yt !== 1) {
            return 'yt is off for this playlist row.';
        }
        return 'Not included in YouTube video.';
    }

    private function pinRowsFromAnalyzerRoles(array $items, array $playlist): array
    {
        $rows = [];
        $sort = 1;

        foreach ($items as $item) {
            if (!$this->pinEnabled($item)) {
                continue;
            }
            $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
            if ($role === 'before') {
                foreach ($this->followingAfterItems($items, $item) as $after) {
                    $rows[] = $this->compositePinRow($item, $after, $playlist, $sort++, 'playlist_analyzer_roles', 1.0);
                }
                continue;
            }

            if (!in_array($role, ['after', 'single'], true)) {
                continue;
            }

            $rows[] = $this->ideaPinRow($item, $playlist, $sort++, 'idea', 'playlist_analyzer_roles', 1.0);
            if ($this->itemHasPalette($item)) {
                $rows[] = $this->ideaPinRow($item, $playlist, $sort++, 'idea_palette', 'playlist_analyzer_roles', 1.0);
            }
        }

        return $rows;
    }

    private function hasAnalyzerRoles(array $items): bool
    {
        foreach ($items as $item) {
            $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
            if (in_array($role, ['before', 'after', 'single'], true)) {
                return true;
            }
        }
        return false;
    }

    private function followingAfterItems(array $items, array $before): array
    {
        $afterItems = [];
        $beforeOrder = (float)($before['order_index'] ?? -1);
        $beforeId = (int)($before['playlist_item_id'] ?? 0);
        foreach ($items as $item) {
            $order = (float)($item['order_index'] ?? -1);
            $id = (int)($item['playlist_item_id'] ?? 0);
            if ($order < $beforeOrder || ($order === $beforeOrder && $id <= $beforeId)) {
                continue;
            }

            $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
            if ($role === 'before' || $role === 'single') {
                break;
            }
            if ($role === 'after') {
                if ($this->pinEnabled($item)) {
                    $afterItems[] = $item;
                }
            }
        }
        return $afterItems;
    }

    private function pinEnabled(array $item): bool
    {
        return !array_key_exists('pin', $item) || (int)($item['pin'] ?? 1) === 1;
    }

    private function compositePinRow(array $before, array $after, array $playlist, int $sort, string $source, float $confidence): array
    {
        $title = trim((string)($after['title'] ?? '')) ?: trim((string)($playlist['title'] ?? '')) ?: 'Pin ' . $sort;
        $description = $this->defaultDescription((string)($playlist['title'] ?? ''), $title);
        return [
            'pair_key' => 'analyzer-composite-' . (int)$before['playlist_item_id'] . '-' . (int)$after['playlist_item_id'],
            'pin_type' => 'composite',
            'asset_type' => 'pin_composite',
            'include' => true,
            'sort_order' => $sort,
            'source' => $source,
            'confidence' => $confidence,
            'saved_palette_set_id' => null,
            'search_title' => $this->defaultSearchTitle($title, (string)($playlist['title'] ?? '')),
            'description' => $description,
            'title' => $title,
            'caption' => $description,
            'before' => $this->playlistItemAssetPayload($before),
            'after' => $this->playlistItemAssetPayload($after),
        ];
    }

    private function ideaPinRow(array $item, array $playlist, int $sort, string $pinType, string $source, float $confidence): array
    {
        $asset = $this->playlistItemAssetPayload($item);
        $baseTitle = trim((string)($asset['title'] ?? ''))
            ?: trim((string)($item['title'] ?? ''))
            ?: trim((string)($playlist['title'] ?? ''))
            ?: 'Pin ' . $sort;
        $searchTitle = $this->defaultIdeaSearchTitle($baseTitle, $pinType);
        $description = $this->defaultDescription((string)($playlist['title'] ?? ''), $baseTitle);

        return [
            'pair_key' => 'analyzer-' . $pinType . '-' . (int)$item['playlist_item_id'],
            'pin_type' => $pinType,
            'asset_type' => $pinType === 'idea_palette' ? 'pin_idea_palette' : 'pin_idea',
            'include' => true,
            'sort_order' => $sort,
            'source' => $source,
            'confidence' => $confidence,
            'saved_palette_set_id' => isset($item['saved_palette_set_id']) && (int)$item['saved_palette_set_id'] > 0 ? (int)$item['saved_palette_set_id'] : null,
            'search_title' => $searchTitle,
            'description' => $description,
            'title' => $searchTitle,
            'caption' => $description,
            'asset' => $asset,
            'after' => $asset,
            'before' => null,
            'has_palette' => $this->itemHasPalette($item),
            'palette_hash' => trim((string)($item['palette_hash'] ?? '')) ?: null,
            'ap_id' => isset($item['ap_id']) && (int)$item['ap_id'] > 0 ? (int)$item['ap_id'] : null,
        ];
    }

    private function itemHasPalette(array $item): bool
    {
        return (int)($item['saved_palette_set_id'] ?? 0) > 0
            || trim((string)($item['palette_hash'] ?? '')) !== ''
            || (int)($item['ap_id'] ?? 0) > 0
            || (int)($item['photo_has_palette'] ?? 0) > 0;
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
                    sps.saved_palette_id,
                    spsp.photo_library_id,
                    spsp.rel_path AS set_rel_path,
                    spsp.photo_type,
                    spsp.caption,
                    spsp.alt_text,
                    spsp.order_index,
                    sps.title AS set_title,
                    sp.nickname,
                    sp.display_title,
                    sp.palette_hash,
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
                'pin_type' => 'composite',
                'asset_type' => 'pin_composite',
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
                'pin_type' => 'composite',
                'asset_type' => 'pin_composite',
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
                $asset = is_array($pair[$side] ?? null) ? $pair[$side] : [];
                $photoId = (int)($asset['photo_library_id'] ?? 0);
                if ($photoId > 0) {
                    $used[$photoId] = true;
                }
            }
            $asset = is_array($pair['asset'] ?? null) ? $pair['asset'] : [];
            $photoId = (int)($asset['photo_library_id'] ?? 0);
            if ($photoId > 0) {
                $used[$photoId] = true;
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

    private function applyPayloadDefaults(
        array $rows,
        array $payload,
        array $playlist,
        array $reservation,
        string $platform,
        string $assetType
    ): array
    {
        $defaultTitle = trim((string)($payload['default_title'] ?? ''));
        $defaultDescription = $this->defaultDescriptionFor(
            $payload,
            $platform,
            $assetType,
            (string)($playlist['type'] ?? ''),
            $playlist,
            $reservation
        );
        if ($defaultTitle === '' && $defaultDescription === '') {
            return $rows;
        }

        return array_map(function (array $row) use ($defaultTitle, $defaultDescription): array {
            if ($defaultTitle !== '') {
                $title = $this->titleForPinType($defaultTitle, (string)($row['pin_type'] ?? ''));
                $row['search_title'] = $title;
                $row['title'] = $title;
            }
            if ($defaultDescription !== '') {
                $row['description'] = $defaultDescription;
                $row['caption'] = $defaultDescription;
            }
            return $row;
        }, $rows);
    }

    private function defaultDescriptionFor(
        array $payload,
        string $platform,
        string $assetType,
        string $playlistType,
        array $playlist,
        array $reservation
    ): string {
        $template = trim((string)($payload['default_description'] ?? ''));
        if ($template === '') {
            $repo = new PdoPublishingDefaultTemplateRepository($this->pdo);
            $row = $repo->findBest($platform, $assetType, $playlistType, 'description');
            $template = trim((string)($row['template_text'] ?? ''));
        }
        if ($template === '') {
            return '';
        }
        return $this->renderTemplate($template, $platform, $assetType, $playlistType, $playlist, $reservation);
    }

    private function renderTemplate(
        string $template,
        string $platform,
        string $assetType,
        string $playlistType,
        array $playlist,
        array $reservation
    ): string {
        $values = [
            'playlist_url' => (string)($reservation['public_url'] ?? ''),
            'final_playlist_url' => (string)($reservation['public_url'] ?? ''),
            'playlist_title' => (string)($playlist['title'] ?? ''),
            'playlist_id' => (string)($playlist['playlist_id'] ?? ''),
            'playlist_type' => $playlistType,
            'channel' => $platform,
            'platform' => $platform,
            'asset_type' => $assetType,
            'summary' => '',
        ];

        return preg_replace_callback('/{{\\s*([a-zA-Z0-9_\\-.]+)\\s*}}/', static function (array $matches) use ($values): string {
            $key = strtolower((string)($matches[1] ?? ''));
            return array_key_exists($key, $values) ? $values[$key] : $matches[0];
        }, $template) ?? $template;
    }

    private function titleForPinType(string $title, string $pinType): string
    {
        $title = trim($title);
        if ($pinType === 'idea_palette') {
            return preg_replace('/\bIdeas\b/i', 'Palettes', $title) ?? $title;
        }
        return $title;
    }

    private function assetPayload(array $row): array
    {
        $relPath = trim((string)($row['asset_rel_path'] ?? $row['photo_rel_path'] ?? $row['set_rel_path'] ?? ''));
        return [
            'asset_library_id' => null,
            'existing_asset_library_id' => isset($row['existing_asset_library_id']) && (int)$row['existing_asset_library_id'] > 0 ? (int)$row['existing_asset_library_id'] : null,
            'photo_library_id' => isset($row['photo_library_id']) && (int)$row['photo_library_id'] > 0 ? (int)$row['photo_library_id'] : null,
            'permission_photo_library_id' => isset($row['permission_photo_library_id']) && (int)$row['permission_photo_library_id'] > 0 ? (int)$row['permission_photo_library_id'] : null,
            'saved_palette_id' => isset($row['saved_palette_id']) && (int)$row['saved_palette_id'] > 0 ? (int)$row['saved_palette_id'] : null,
            'saved_palette_set_id' => isset($row['saved_palette_set_id']) && (int)$row['saved_palette_set_id'] > 0 ? (int)$row['saved_palette_set_id'] : null,
            'palette_hash' => trim((string)($row['palette_hash'] ?? '')) ?: null,
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
        $savedPaletteId = (int)($item['saved_palette_id'] ?? $asset['saved_palette_id'] ?? 0);
        $savedPaletteSetId = (int)($item['saved_palette_set_id'] ?? $asset['saved_palette_set_id'] ?? 0);
        $paletteHash = trim((string)($item['palette_hash'] ?? $asset['palette_hash'] ?? ''));
        return [
            'asset_library_id' => null,
            'existing_asset_library_id' => isset($asset['existing_asset_library_id']) ? (int)$asset['existing_asset_library_id'] : null,
            'photo_library_id' => $photoId > 0 ? $photoId : null,
            'permission_photo_library_id' => $photoId > 0 ? $photoId : null,
            'saved_palette_id' => $savedPaletteId > 0 ? $savedPaletteId : null,
            'saved_palette_set_id' => $savedPaletteSetId > 0 ? $savedPaletteSetId : null,
            'palette_hash' => $paletteHash !== '' ? $paletteHash : null,
            'ap_id' => isset($item['ap_id']) && (int)$item['ap_id'] > 0 ? (int)$item['ap_id'] : null,
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
                    (
                        SELECT sps.saved_palette_id
                          FROM saved_palette_set_photos spsp
                          JOIN saved_palette_sets sps
                            ON sps.id = spsp.saved_palette_set_id
                         WHERE spsp.photo_library_id = pl.photo_library_id
                         ORDER BY spsp.id DESC
                         LIMIT 1
                    ) AS saved_palette_id,
                    (
                        SELECT spsp.saved_palette_set_id
                          FROM saved_palette_set_photos spsp
                         WHERE spsp.photo_library_id = pl.photo_library_id
                         ORDER BY spsp.id DESC
                         LIMIT 1
                    ) AS saved_palette_set_id,
                    (
                        SELECT sp.palette_hash
                          FROM saved_palette_set_photos spsp
                          JOIN saved_palette_sets sps
                            ON sps.id = spsp.saved_palette_set_id
                          JOIN saved_palettes sp
                            ON sp.id = sps.saved_palette_id
                         WHERE spsp.photo_library_id = pl.photo_library_id
                         ORDER BY spsp.id DESC
                         LIMIT 1
                    ) AS palette_hash,
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

    private function defaultIdeaSearchTitle(string $title, string $pinType): string
    {
        $title = trim($title);
        if ($title === '') {
            return $pinType === 'idea_palette' ? 'ColorFix paint palette' : 'ColorFix color idea';
        }

        if ($pinType === 'idea_palette' && !preg_match('/\bpalette(s)?\b/i', $title)) {
            return $title . ' Palettes';
        }
        if ($pinType === 'idea' && !preg_match('/\bidea(s)?\b/i', $title)) {
            return $title . ' Ideas';
        }

        return $title;
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
