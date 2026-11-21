<?php
declare(strict_types=1);

namespace App\ARTICLES\Services;

use App\ARTICLES\Repos\PdoArticleRepository;
use App\PALETTES\Services\PaletteService;
use App\PHOTOS\Services\PhotoDeliveryService;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\Repos\PdoCtaRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\REX\Repos\PdoRexReservationRepository;
use PDO;

/** Builds the public article response. API endpoints only dispatch and respond. */
class PublicArticleService
{
    public function __construct(private PDO $pdo) {}

    /** @return array{status:int, item?:array, error?:string} */
    public function get(int $id, bool $isAdmin): array
    {
        $repo = new PdoArticleRepository($this->pdo);
        $article = $repo->getArticleById($id);
        if (!$article) return ['status' => 404, 'error' => 'Not found'];
        if (($article['status'] ?? '') !== 'published' && !$isAdmin) {
            return ['status' => 403, 'error' => 'Not published'];
        }

        $sections = $repo->listSections($id);
        $tags = $repo->getArticleTags($id);
        $photoMap = $this->loadPhotos($article, $sections);

        $hero = $this->photoFor($photoMap, $article['hero_asset_id'] ?? null);
        $heroInset = $this->photoFor($photoMap, $article['hero_inset_asset_id'] ?? null);
        $heroMobile = $this->photoFor($photoMap, $article['hero_mobile_asset_id'] ?? null);

        // PALETTES owns the single-query assembly of render-ready COLORS swatches.
        // Only palettes referenced by this authorized article are exposed.
        $paletteService = null;
        $paletteCache = [];
        foreach ($sections as &$section) {
            $section['asset'] = $this->photoFor($photoMap, $section['asset_id'] ?? null);
            if (($section['kind'] ?? '') !== 'palette_link') continue;
            $paletteId = (int)($section['palette_id'] ?? 0);
            if ($paletteId <= 0) continue;
            if (!array_key_exists($paletteId, $paletteCache)) {
                $paletteService ??= new PaletteService($this->pdo);
                $paletteCache[$paletteId] = $paletteService->getSwatches($paletteId);
            }
            $section['swatches'] = $paletteCache[$paletteId];
        }
        unset($section);

        return [
            'status' => 200,
            'item' => [
                'article' => $article,
                'tags' => $tags,
                'hero' => $hero,
                'hero_inset' => $heroInset,
                'hero_mobile' => $heroMobile,
                'sections' => $sections,
                'ctas' => $this->resolveCtas($article),
                'metadata' => ArticleHtmlService::metadata(['article' => $article, 'hero' => $hero]),
            ],
        ];
    }

    private function loadPhotos(array $article, array $sections): array
    {
        $ids = [];
        foreach (['hero_asset_id', 'hero_inset_asset_id', 'hero_mobile_asset_id'] as $key) {
            if (!empty($article[$key])) $ids[] = (int)$article[$key];
        }
        foreach ($sections as $section) {
            if (!empty($section['asset_id'])) $ids[] = (int)$section['asset_id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT photo_library_id, rel_path, title, alt_text, updated_at FROM photo_library WHERE photo_library_id IN ($placeholders)");
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $photoId = (int)$row['photo_library_id'];
            // Only PHOTOS may issue delivery URLs. A signed URL is needed for
            // artwork/photos that are usable in an article but not public gallery items.
            // Both painting placements consume the SAME 960px URL (one browser request).
            $optimized = [];
            foreach ([640, 960, 1600, 1920] as $width) {
                $url = PhotoDeliveryService::url($photoId, (string)$row['rel_path'], $width, 'original');
                if ($url !== null) $optimized[(string)$width] = $url;
            }
            $map[$photoId] = [
                'photo_library_id' => $photoId,
                'rel_path' => $row['rel_path'],
                'title' => $row['title'],
                'alt_text' => $row['alt_text'],
                'updated_at' => $row['updated_at'] ?? null,
                'optimized_urls' => $optimized,
            ];
        }
        return $map;
    }

    private function photoFor(array $photoMap, mixed $id): ?array
    {
        return $id ? ($photoMap[(int)$id] ?? null) : null;
    }

    private function resolveCtas(array $article): array
    {
        $overrides = [];
        if (!empty($article['cta_overrides'])) {
            $decoded = json_decode((string)$article['cta_overrides'], true);
            if (is_array($decoded)) $overrides = $decoded;
        }

        $ctaIds = $overrides['_cta_ids'] ?? [];
        $ctaIds = is_array($ctaIds) ? array_values(array_filter(array_map('intval', $ctaIds))) : [];
        $sources = (new PdoPlaylistRepository($this->pdo))->findArticleSources((int)$article['id']);
        $isArtwork = in_array('artwork', array_column($sources, 'editorial_type'), true);
        $ctaRepo = new PdoCtaRepository($this->pdo);
        // A saved CTA page wins; zero explicitly disables CTAs. Older articles
        // retain individual selections until an editor chooses a page.
        if (array_key_exists('_cta_group_id', $overrides)) {
            $groupId = (int)$overrides['_cta_group_id'];
            $ctas = $groupId > 0 ? $ctaRepo->getByGroupId($groupId) : [];
        } elseif (array_key_exists('_cta_ids', $overrides)) {
            $ctas = $ctaRepo->getByIds($ctaIds);
        } else {
            $group = $isArtwork ? $ctaRepo->findGroupByKey('artwork-article') : null;
            $ctas = $group ? $ctaRepo->getByGroupId((int)$group['id']) : [];
        }
        if (!$ctas) return [];
        $rexRepo = new PdoRexReservationRepository($this->pdo);
        $sourceId = count($sources) === 1 ? (int)$sources[0]['playlist_id'] : 0;
        foreach ($ctas as $idx => $cta) {
            $ctaId = $cta['cta_id'] ?? null;
            if ($ctaId === null) continue;
            $key = (string)$ctaId;

            $base = [];
            if (!empty($cta['params'])) {
                $parsed = json_decode((string)$cta['params'], true);
                if (is_array($parsed)) $base = $parsed;
            }
            $merged = array_merge($base, is_array($overrides[$key] ?? null) ? $overrides[$key] : []);
            $isPlaylist = ($cta['action_key'] ?? '') === 'playlist_link';
            $hasUrl = trim((string)($merged['url'] ?? '')) !== '';
            $hasExplicitId = array_key_exists('playlist_id', $merged)
                || !empty($merged['playlist_instance_id']) || !empty($merged['playlistInstanceId']);
            $playlistId = (int)($merged['playlist_id'] ?? 0);
            // Older article CTAs selected a playlist instance rather than a playlist.
            // Recover its existing playlist identity, then use the same public REX lookup.
            if ($isPlaylist && !$hasUrl && !array_key_exists('playlist_id', $merged)) {
                $instanceId = (int)($merged['playlist_instance_id'] ?? $merged['playlistInstanceId'] ?? 0);
                if ($instanceId > 0) {
                    $instance = (new PdoPlaylistInstanceRepository($this->pdo))->getById($instanceId);
                    $playlistId = $instance?->playlistId ?? 0;
                }
            }
            if ($isPlaylist && !$hasExplicitId) $playlistId = $sourceId;
            if (!$hasUrl && $playlistId > 0) {
                $reservations = $rexRepo->findActiveByResourceIdsAndExperience(
                    'playlist_experience', 'playlist', [$playlistId], 'public'
                );
                $reservation = $reservations[$playlistId] ?? null;
                if ($reservation !== null) {
                    if ($isPlaylist && !$hasExplicitId) {
                        $ctas[$idx]['data'] = ['reserved_playlist_url' => '/t/' . $reservation->token];
                    } else {
                        $merged['url'] = '/t/' . $reservation->token;
                    }
                }
            }
            // Do not invoke the legacy player-path fallback without a resolved destination.
            if ($isPlaylist && empty($merged['url']) && empty($ctas[$idx]['data']['reserved_playlist_url'])) {
                $ctas[$idx]['enabled'] = false;
            }
            $ctas[$idx]['params'] = json_encode($merged, JSON_UNESCAPED_SLASHES);
        }
        return (new ArticleLinkService($this->pdo))->hydrate($ctas);
    }
}
