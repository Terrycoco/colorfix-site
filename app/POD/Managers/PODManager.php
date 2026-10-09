<?php
declare(strict_types=1);

namespace App\POD\Managers;

use App\PLAYLISTS\Entities\PlaylistItem;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Repos\PdoPlayerExperienceRepository;
use App\Repos\PdoArticleRepository;
use App\Repos\PdoCtaRepository;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use DomainException;
use InvalidArgumentException;
use PDO;

/** Read-only assembly. No playlist records, REX reservations, or raw SQL. */
final class PodManager
{
    private PdoPhotoLibraryRepository $photos;
    private PdoPlaylistRepository $playlists;
    private PdoPlayerExperienceRepository $experiences;
    private PdoCtaRepository $ctas;

    public function __construct(private PDO $pdo)
    {
        $this->photos = new PdoPhotoLibraryRepository($pdo);
        $this->playlists = new PdoPlaylistRepository($pdo);
        $this->experiences = new PdoPlayerExperienceRepository($pdo);
        $this->ctas = new PdoCtaRepository($pdo);
    }

    /**
     * IDs are photo_library.photo_library_id, in the sender's chosen order.
     * The endpoint parses URL parameters into these typed arguments.
     * OG HTML and request/visit logging belong to the route integration.
     *
     * @param array<int, int|string> $photoIds
     * @return array<string, mixed>
     */
    public function buildPlaybackPlan(
        array $photoIds,
        bool $includeBefore = true,
        bool $includeCaptions = true,
        ?string $sourceAttribution = null
    ): array {
        $ids = $this->normalizeIds($photoIds);
        $experience = $this->experiences->getByExperienceKey('pod');
        if ($experience === null || !$experience->isActive) {
            throw new DomainException('An active POD player experience is required.');
        }
        if ($experience->paletteViewerKey !== 'none') {
            throw new DomainException('The POD experience must use palette viewer key "none".');
        }
        if ($experience->ctaPageId <= 0) {
            throw new DomainException('The POD experience requires a CTA page.');
        }

        // All caches are request-local, even if a manager instance is reused.
        $photoCache = [];
        $selected = [];
        foreach ($ids as $id) {
            $photo = $this->loadPhoto($id, $photoCache);
            if ($photo === null) {
                throw new DomainException("Photo {$id} is unavailable for POD.");
            }
            $selected[$id] = $photo;
        }

        $sources = ($includeBefore || $includeCaptions)
            ? $this->findSources($selected, $includeBefore, $photoCache)
            : [];
        $items = [];
        foreach ($ids as $id) {
            $source = $sources[$id] ?? null;
            if ($includeBefore && isset($source['before_photo'])) {
                $items[] = $this->photoSlide(
                    $source['before_photo'], $source['before_row'], $includeCaptions
                );
            }
            $items[] = $this->photoSlide(
                $selected[$id], $source['row'] ?? null, $includeCaptions
            );
        }

        // The existing player renders this slide type. CTA is a separate payload.
        $items[] = new PlaylistItem(
            ap_id: '', palette_hash: null, image_url: null,
            type: 'brand-bumper', transition: 'dissolve',
            exclude_from_thumbs: true, analyzer_role: 'ignore'
        );

        $ctaRows = $this->ctas->getByGroupId($experience->ctaPageId);
        if ($ctaRows === []) {
            throw new DomainException('The POD CTA page has no actions.');
        }
        $ctaRows = $this->hydrateArticleCtas($ctaRows);
        $src = trim((string)$sourceAttribution);
        $title = 'ColorFix by Terry';
        $description = 'See what thoughtful color design can do for a home.';

        return [
            'playlist_instance_id' => null,
            'playlist_id' => null,
            'project_id' => null,
            'title' => $title,
            'display_title' => $title,
            'slug' => null,
            'page_h1' => $title,
            'project_summary' => $description,
            'type' => 'pod',
            'total_items' => count($items),
            'start_index' => 0,
            'start_target' => null,
            'items' => $items,
            'ctas' => $ctaRows,
            'cta_context_key' => null,
            'audience' => 'public',
            'palette_viewer_cta_group_id' => null,
            'show_slide_palette_prompt' => false,
            'thumbs_enabled' => false,
            'demo_enabled' => false,
            'share_enabled' => true,
            'share_title' => $title,
            'share_description' => $description,
            // Always the first SELECTED photo, even when a Before plays first.
            'share_image_url' => trim((string)$selected[$ids[0]]['rel_path']),
            'skip_intro_on_replay' => false,
            'hide_stars' => true,
            'playlist_instance_set_ids' => [],
            'player_experience_id' => $experience->playerExperienceId,
            'experience_key' => 'pod',
            'player_experience_config_key' => 'pod',
            'experience_name' => $experience->name,
            // Existing config requires a flag; POD never filters selected IDs by it.
            'slide_flag' => $experience->slideFlag,
            'palette_viewer_key' => 'none',
            'cta_page_id' => $experience->ctaPageId,
            'experience_source' => 'pod',
            'src' => $src !== '' ? $src : null,
            'reservation_token' => null,
            'viewer_rex_count' => 0,
            'viewer_rex_urls' => [],
            'viewer_rex_targets' => [],
            'colors_used_destination' => 'none',
            'colors_used_url' => '',
            'pod_photo_ids' => $ids,
            'pod_include_before' => $includeBefore,
            'pod_include_captions' => $includeCaptions,
        ];
    }

    private function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_string($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('POD photo IDs must be positive integers.');
            }
            $id = (int)$value;
            $ids[$id] = $id;
        }
        if ($ids === []) {
            throw new InvalidArgumentException('At least one photo ID is required.');
        }
        return array_values($ids);
    }

    private function loadPhoto(int $id, array &$cache): ?array
    {
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $photo = $this->photos->findById($id);
        if ($photo === null || !empty($photo['is_inactive'])
            || trim((string)($photo['rel_path'] ?? '')) === '') {
            return $cache[$id] = null;
        }
        // Respect a recorded refusal, including permission inherited from a client.
        $permission = $this->photos->getPermissionStatus($id);
        if (($permission['photo_permission_status'] ?? '') === 'declined') {
            return $cache[$id] = null;
        }
        return $cache[$id] = $photo;
    }

    /** Resolve whole occurrences, so captions and Before come from the same source. */
    private function findSources(array $selected, bool $includeBefore, array &$photoCache): array
    {
        $playlistIds = [];
        // Existing repo has no targeted photo-ID lookup. One compact scan finds
        // candidate playlists; full slide rows are loaded only for those playlists.
        foreach ($this->playlists->listItemRows() as $row) {
            if (empty($row['is_active'])) {
                continue;
            }
            foreach ($selected as $photo) {
                if ($this->matchesPhoto($row, $photo)) {
                    $playlistIds[(int)$row['playlist_id']] = true;
                    break;
                }
            }
        }
        ksort($playlistIds, SORT_NUMERIC);
        $chosen = [];
        foreach (array_keys($playlistIds) as $playlistId) {
            $playlist = $this->playlists->getAdminRowById($playlistId);
            if ($playlist === null || empty($playlist['is_active'])) {
                continue;
            }
            $rows = $this->playlists->getAdminItemRows($playlistId);
            usort($rows, static fn(array $a, array $b): int =>
                [(int)$a['order_index'], (int)$a['playlist_item_id']]
                <=> [(int)$b['order_index'], (int)$b['playlist_item_id']]);
            foreach ($rows as $index => $row) {
                if (!in_array(strtolower((string)($row['item_type'] ?? 'normal')),
                    ['normal', 'before', 'non-palette', ''], true)) {
                    continue;
                }
                foreach ($selected as $id => $photo) {
                    if (!$this->matchesPhoto($row, $photo)) {
                        continue;
                    }
                    $candidate = ['row' => $row];
                    $previous = $index > 0 ? $rows[$index - 1] : null;
                    if ($includeBefore && $this->role($row) === 'after'
                        && $previous !== null && $this->role($previous) === 'before') {
                        $beforeId = $this->rowPhotoId($previous);
                        if ($beforeId > 0 && $beforeId !== $id) {
                            $before = $this->loadPhoto($beforeId, $photoCache);
                            if ($before !== null) {
                                $candidate['before_row'] = $previous;
                                $candidate['before_photo'] = $before;
                            }
                        }
                    }
                    // Prefer a usable pair when requested, then lowest playlist/item ID.
                    $candidate['rank'] = [
                        isset($candidate['before_photo']) ? 0 : 1,
                        $playlistId, (int)$row['playlist_item_id'],
                    ];
                    if (!isset($chosen[$id]) || $candidate['rank'] < $chosen[$id]['rank']) {
                        $chosen[$id] = $candidate;
                    }
                }
            }
        }
        return $chosen;
    }

    private function role(array $row): string
    {
        return strtolower(trim((string)($row['analyzer_role'] ?? 'ignore')));
    }

    private function matchesPhoto(array $row, array $photo): bool
    {
        $id = $this->rowPhotoId($row);
        if ($id > 0) {
            return $id === (int)$photo['photo_library_id'];
        }
        $rowPath = $this->imagePath((string)($row['image_url'] ?? ''));
        return $rowPath !== '' && $rowPath === $this->imagePath((string)$photo['rel_path']);
    }

    private function rowPhotoId(array $row): int
    {
        $id = (int)($row['photo_library_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $url = trim((string)($row['image_url'] ?? ''));
        if (preg_match('/^photo:([1-9][0-9]*)(?:\||$)/', $url, $match)) {
            return (int)$match[1];
        }
        // Legacy paths are resolved through the existing Photo Library repo.
        return $url !== '' ? (int)($this->photos->findIdByRelPath($url) ?? 0) : 0;
    }

    private function imagePath(string $value): string
    {
        if (str_starts_with($value, 'photo:')) {
            $value = explode('|', $value, 2)[1] ?? '';
        }
        return ltrim((string)(parse_url(trim($value), PHP_URL_PATH) ?: ''), '/');
    }

    private function photoSlide(array $photo, ?array $source, bool $captions): PlaylistItem
    {
        return new PlaylistItem(
            ap_id: '', palette_hash: null,
            image_url: 'photo:' . (int)$photo['photo_library_id'] . '|' . trim((string)$photo['rel_path']),
            photo_library_id: (int)$photo['photo_library_id'],
            title: $captions ? ($source['title'] ?? null) : null,
            subtitle: $captions ? ($source['subtitle'] ?? null) : null,
            body: $captions ? ($source['body'] ?? null) : null,
            type: $source !== null && $this->role($source) === 'before' ? 'before' : 'normal',
            star: false,
            layout: $source['layout'] ?? null,
            transition: 'dissolve',
            duration_ms: isset($source['duration_ms']) && (int)$source['duration_ms'] > 0
                ? (int)$source['duration_ms'] : null,
            title_mode: $captions ? ($source['title_mode'] ?? null) : null,
            exclude_from_thumbs: true,
            analyzer_role: $source !== null ? $this->role($source) : 'single',
            alt_tag: $photo['alt_text'] ?? null
        );
    }

    /** Same article-link expansion used by the existing playback service. */
    private function hydrateArticleCtas(array $ctas): array
    {
        $articles = null;
        foreach ($ctas as &$cta) {
            $action = (string)($cta['action_key'] ?? $cta['action'] ?? $cta['key'] ?? '');
            if ($action !== 'article_link') {
                continue;
            }
            $raw = $cta['params'] ?? null;
            $params = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : []);
            $params = is_array($params) ? $params : [];
            $id = (int)($params['article_id'] ?? $params['articleId'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $articles ??= new PdoArticleRepository($this->pdo);
            $article = $articles->getArticleById($id);
            if (!$article) {
                continue;
            }
            foreach (['title', 'dek'] as $field) {
                if (empty($params[$field]) && !empty($article[$field])) {
                    $params[$field] = $article[$field];
                }
            }
            if (empty($params['url'])) {
                $params['url'] = '/articles/' . $id;
            }
            $cta['params'] = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        unset($cta);
        return $ctas;
    }
}
