<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Services;

use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\Repos\PdoPlayerExperienceRepository;
use App\Repos\PdoCtaRepository;
use App\Repos\PdoArticleRepository;
use App\Repos\PdoProjectRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\PLAYLISTS\Entities\Playlist;
use App\PLAYLISTS\Entities\PlaylistItem;
use App\Services\ProjectReleaseSelectionService;
use App\Services\PaletteViewerTokenService;
use App\Entities\PlayerExperience;
use DomainException;
use PDO;
use RuntimeException;
use App\PALETTES\PV\PVService;

class PlaylistExperienceService
{
    /** @var array<string, float> */
    private array $lastTiming = [];
    private ?ProjectReleaseSelectionService $projectReleaseSelection = null;

    public function __construct(
        protected PDO $pdo
    ) {}

public function buildPlaybackPlanFromPlaylistExperience(
    int $playlistId,
    string $experienceKey,
    ?string $sourceAttribution = null,
    ?int $start = null,
    ?array $startTarget = null,
    ?string $reservationToken = null
): array {
    if ($playlistId <= 0) {
        throw new RuntimeException('Valid playlist ID required.');
    }

    $experienceKey = strtolower(trim($experienceKey));

    if ($experienceKey === 'public') {
        return $this->buildPublicPlaybackPlanFromPlaylistExperience(
            $playlistId,
            $sourceAttribution,
            $start,
            $startTarget,
            $reservationToken
        );
    }

    $projectRepo = new PdoProjectRepository($this->pdo);
    $projectId = (int)($projectRepo->findMostRecentProjectIdByPlaylistId($playlistId) ?? 0);

    if ($projectId <= 0) {
        throw new RuntimeException(
            "Playlist {$playlistId} is not attached to a project"
        );
    }

    return $this->buildPlaybackPlanFromProjectExperience(
        $projectId,
        $experienceKey,
        $sourceAttribution,
        $start,
        $startTarget,
        $reservationToken,
        $playlistId
    );
}

private function buildPublicPlaybackPlanFromPlaylistExperience(
    int $playlistId,
    ?string $sourceAttribution = null,
    ?int $start = null,
    ?array $startTarget = null,
    ?string $reservationToken = null
): array {
    $startedAt = microtime(true);
    $experience = $this->resolveExperienceByKey('public', "playlist reservation experience 'public'");
    $slideFlag = $this->normalizeSlideFlag($experience->slideFlag);

    $playlistStartedAt = microtime(true);
    $playlistRepo = new PdoPlaylistRepository($this->pdo);
    $playlist = $playlistRepo->getById((string)$playlistId, $slideFlag);
    $this->markTiming('load_playlist', $playlistStartedAt);

    if (!$playlist instanceof Playlist) {
        throw new RuntimeException("Playlist {$playlistId} not found for public REX reservation");
    }

    $itemsStartedAt = microtime(true);
    $sourceItems = $this->flattenItems($playlist);
    $this->hydrateItemImages($sourceItems);

    $resolvedShareImageUrl = $this->resolvePlaylistShareImageUrl($sourceItems);
    $resolvedShareTitle = $this->resolvePlaylistCoverTitle($sourceItems);

    $items = $this->filterPlayableItems($sourceItems);

    $paletteViewerKey = $experience->paletteViewerKey;
    $showSlidePalettePrompt = $this->shouldShowSlidePalettePrompt($experience);

    /* $viewerRex = $this->hydratePublicRexViewerUrls($items, $reservationToken); */
    $viewerRex = $this->buildLinkedPVData(
        $playlistId,
        $experience->experienceKey
    );
    $viewerRexCount = $viewerRex['count'];
    if ($viewerRexCount === 0) {
        $showSlidePalettePrompt = false;
    }

    $this->markTiming('hydrate_items', $itemsStartedAt);

    $startIndex = $this->resolveStartIndex($items, $start, $startTarget);

    $colorsUsedUrl = $viewerRexCount === 1
        ? (string)($viewerRex['urls'][0] ?? '')
        : ($viewerRexCount > 1 ? '/playlist-thumbs/' . rawurlencode((string)$playlist->playlist_id) : '');
    $colorsUsedDestination = $viewerRexCount === 1
        ? 'viewer'
        : ($viewerRexCount > 1 ? 'thumbs' : 'none');
    $thumbsEnabled = $viewerRexCount > 1;

    $ctaStartedAt = microtime(true);
    $ctaRepo = new PdoCtaRepository($this->pdo);
    $ctaPageId = $this->resolvePublicRexCtaPageId($ctaRepo, $experience->ctaPageId);
    $ctas = $this->selectPublicRexCtas(
        $ctaRepo->getByGroupId($ctaPageId),
        $colorsUsedDestination
    );
    if (!empty($ctas)) {
        $ctas = $this->hydrateArticleCtas($ctas);
    }
    $this->markTiming('load_ctas', $ctaStartedAt);

    $this->lastTiming['total'] = round((microtime(true) - $startedAt) * 1000, 1);

    $displayTitle = $playlist->title;
    $pageH1 = $this->firstNonEmpty([
        $playlist->meta['headline'] ?? null,
        $displayTitle,
    ]);
    $projectSummary = $this->firstNonEmpty([
        $playlist->meta['dek'] ?? null,
        $playlist->meta['meta_description'] ?? null,
    ]);

    return [
        'playlist_instance_id' => null,
        'playlist_id' => $playlist->playlist_id,
        'project_id' => null,
        'title' => $playlist->title,
        'display_title' => $displayTitle,
        'slug' => null,
        'page_h1' => $pageH1,
        'project_summary' => $projectSummary,
        'type' => $playlist->type,
        'total_items' => count($items),
        'start_index' => $startIndex,
        'start_target' => $this->startTargetSummary($startTarget, $startIndex),
        'items' => $items,
        'ctas' => $ctas,
        'cta_context_key' => null,
        'audience' => 'public',
        'palette_viewer_cta_group_id' => null,
        'show_slide_palette_prompt' => $showSlidePalettePrompt,
        'thumbs_enabled' => $thumbsEnabled,
        'demo_enabled' => false,
        'share_enabled' => false,
        'share_title' => $this->firstNonEmpty([
            $resolvedShareTitle,
            $displayTitle,
        ]),
        'share_description' => $projectSummary,
        'share_image_url' => $resolvedShareImageUrl,
        'skip_intro_on_replay' => false,
        'hide_stars' => false,
        'playlist_instance_set_ids' => [],
        'player_experience_id' => $experience->playerExperienceId,
        'experience_key' => 'public',
        'player_experience_config_key' => $experience->experienceKey,
        'experience_name' => $experience->name,
        'slide_flag' => $slideFlag,
        'palette_viewer_key' => $paletteViewerKey,
        'cta_page_id' => $ctaPageId,
        'experience_source' => 'playlist_rex_public',
        'src' => $sourceAttribution,
        'reservation_token' => $reservationToken,
        'viewer_rex_count' => $viewerRexCount,
        'viewer_rex_urls' => $viewerRex['urls'],
        'viewer_rex_targets' => $viewerRex['targets'],
        'colors_used_destination' => $colorsUsedDestination,
        'colors_used_url' => $colorsUsedUrl,
    ];
}

private function resolvePublicRexCtaPageId(PdoCtaRepository $ctaRepo, int $fallbackCtaPageId): int
{
    foreach (['public', 'default'] as $key) {
        $group = $ctaRepo->findGroupByKey($key);
        $groupId = (int)($group['id'] ?? 0);
        if ($groupId > 0) {
            return $groupId;
        }
    }

    return $fallbackCtaPageId;
}

/**
 * REX playlists send the exact end-screen CTA set the player should render.
 *
 * The public/default CTA page may contain both one-viewer and many-viewer
 * "colors used" actions. PES owns the reservation context, so it chooses the
 * single correct action before the payload reaches the player.
 *
 * @param array<int, array<string, mixed>> $ctas
 * @return array<int, array<string, mixed>>
 */
private function selectPublicRexCtas(array $ctas, string $colorsUsedDestination): array
{
    $destination = strtolower(trim($colorsUsedDestination));
    $colorActions = ['see_colors_used', 'to_palette', 'to_thumbs'];
    $preferredColorAction = match ($destination) {
        'viewer' => 'to_palette',
        'thumbs' => 'to_thumbs',
        default => '',
    };

    $fallbackColorIndex = null;
    $preferredColorIndex = null;

    foreach ($ctas as $index => $cta) {
        $action = strtolower(trim((string)($cta['action_key'] ?? $cta['key'] ?? $cta['action'] ?? '')));
        if (!in_array($action, $colorActions, true)) {
            continue;
        }
        if ($action === $preferredColorAction && $preferredColorIndex === null) {
            $preferredColorIndex = $index;
        }
        if ($action === 'see_colors_used' && $fallbackColorIndex === null) {
            $fallbackColorIndex = $index;
        }
    }

    $keepColorIndex = $preferredColorIndex ?? $fallbackColorIndex;

    $filtered = [];
    foreach ($ctas as $index => $cta) {
        $action = strtolower(trim((string)($cta['action_key'] ?? $cta['key'] ?? $cta['action'] ?? '')));
        if (in_array($action, $colorActions, true)) {
            if ($keepColorIndex === null || $index !== $keepColorIndex) {
                continue;
            }
        }
        $filtered[] = $cta;
    }

    return $filtered;
}


/** new modern PV call */
private function getLinkedPVs(
    int $playlistId,
    string $experienceKey
): array {
    if ($playlistId <= 0) {
        return [];
    }

    $experienceKey = strtolower(trim($experienceKey));

    if ($experienceKey === '') {
        return [];
    }

    $rexRepo = new PdoRexReservationRepository($this->pdo);
    $relationships = new RexReservationRelationships($rexRepo);
    $pvService = new PVService($this->pdo);

    $linkedPVs = [];

    foreach (
        $relationships->childrenForResourceExperience(
            'playlist_experience',
            'playlist',
            $playlistId,
            $experienceKey,
            'viewer'
        ) as $relationship
    ) {
        $reservation = $relationship->reservation;

        if (
            strtolower(trim($reservation->status)) !== 'active'
            || strtolower(trim($reservation->resolverKey)) !== 'viewer'
            || strtolower(trim($reservation->resourceType)) !== 'palette_viewer'
        ) {
            continue;
        }

        $pvId = (int)$reservation->resourceId;

        if ($pvId <= 0) {
            continue;
        }

        try {
    $pv = $pvService->getPV($pvId);
} catch (RuntimeException $e) {
    if ($e->getMessage() === "Palette Viewer {$pvId} is inactive") {
        continue;
    }

    throw $e;
}
        $pv['rex_url'] = $relationships->publicUrl($reservation);
        $pv['rex_reservation_id'] = $reservation->id;

        $linkedPVs[] = $pv;
    }

    return $linkedPVs;
}

private function buildLinkedPVData(
    int $playlistId,
    string $experienceKey
): array {
    $linkedPVs = $this->getLinkedPVs(
        $playlistId,
        $experienceKey
    );

    $urls = [];
    $targets = [];

    foreach ($linkedPVs as $pv) {
        $url = trim((string)($pv['rex_url'] ?? ''));

        if ($url === '') {
            continue;
        }

        $meta = is_array($pv['meta'] ?? null)
            ? $pv['meta']
            : [];

        $urls[] = $url;

        $targets[] = [
            'palette_viewer_id' => (int)($meta['palette_viewer_id'] ?? 0),
            'palette_viewer_url' => $url,
            'title' => (string)($meta['display_title'] ?? $meta['title'] ?? ''),
            'image_url' => (string)($meta['photo_url'] ?? ''),
        ];
    }

    return [
        'count' => count($urls),
        'urls' => $urls,
        'targets' => $targets,
    ];
}


/**
 * Hydrate public playlist items from explicit Playlist REX -> Viewer REX relationships.
 *
 * @param PlaylistItem[] $items
 * @return array{count:int,urls:string[],targets:array<int,array<string,mixed>>,url_by_palette_hash:array<string,string>}
 */
private function hydratePublicRexViewerUrls(array $items, ?string $reservationToken): array
{
    $reservationToken = trim((string)$reservationToken);
    if ($reservationToken === '') {
        return ['count' => 0, 'urls' => [], 'targets' => [], 'url_by_palette_hash' => []];
    }

    $rexRepo = new PdoRexReservationRepository($this->pdo);
    $playlistReservation = $rexRepo->findByToken($reservationToken);
    if (!$playlistReservation || strtolower(trim($playlistReservation->status)) !== 'active') {
        return ['count' => 0, 'urls' => [], 'targets' => [], 'url_by_palette_hash' => []];
    }

    $relationships = new RexReservationRelationships($rexRepo);
    $viewerRepo = new PdoPaletteViewerRepository($this->pdo);
    $viewerPhotoRepo = new PdoPaletteViewerPhotoRepository($this->pdo);
    $savedPaletteRepo = new PdoSavedPaletteRepository($this->pdo);

    $urlByPaletteHash = [];
    $urls = [];
    $targets = [];
    $imageUrlByPaletteHash = [];

    foreach ($items as $item) {
        if (!$item instanceof PlaylistItem) {
            continue;
        }
        if (!$this->isPaletteViewerEligibleItem($item)) {
            continue;
        }

        $paletteHash = trim((string)($item->palette_hash ?? ''));
        if ($paletteHash === '') {
            continue;
        }

        $itemImageUrl = $this->normalizeShareImageUrl((string)($item->image_url ?? '')) ?? '';
        if ($itemImageUrl === '') {
            continue;
        }

        $photoType = strtolower(trim((string)($item->saved_palette_photo_type ?? '')));
        if (!isset($imageUrlByPaletteHash[$paletteHash]) || $photoType === 'full') {
            $imageUrlByPaletteHash[$paletteHash] = $itemImageUrl;
        }
    }

    foreach ($relationships->children($playlistReservation->id, 'viewer') as $viewerReservation) {
        if (strtolower(trim($viewerReservation->status)) !== 'active') {
            continue;
        }
        if (strtolower(trim($viewerReservation->resolverKey)) !== 'viewer') {
            continue;
        }
        if (strtolower(trim($viewerReservation->resourceType)) !== 'palette_viewer') {
            continue;
        }

        $paletteViewer = $viewerRepo->findById($viewerReservation->resourceId);
        if (!$paletteViewer || !$paletteViewer->isActive) {
            continue;
        }

        $viewerUrl = $relationships->publicUrl($viewerReservation);
        $urls[] = $viewerUrl;

        $savedPalette = $savedPaletteRepo->getSavedPaletteById($paletteViewer->savedPaletteId);
        $paletteHash = trim((string)($savedPalette['palette_hash'] ?? ''));
        $title = $this->firstNonEmpty([
            $paletteViewer->title,
            $savedPalette['display_title'] ?? null,
            $savedPalette['nickname'] ?? null,
            $savedPalette['name'] ?? null,
            'ColorFix Palette',
        ]);
        $imageUrl = '';
        foreach ($viewerPhotoRepo->findByViewerId($paletteViewer->paletteViewerId) as $photo) {
            $relPath = trim((string)($photo->relPath ?? ''));
            if ($relPath === '') {
                continue;
            }
            if ($imageUrl === '' || strtolower($photo->photoType) === 'full') {
                $imageUrl = $relPath;
            }
            if (strtolower($photo->photoType) === 'full') {
                break;
            }
        }
        if ($paletteHash !== '' && !empty($imageUrlByPaletteHash[$paletteHash])) {
            $imageUrl = $imageUrlByPaletteHash[$paletteHash];
        }

        if ($paletteHash !== '') {
            $urlByPaletteHash[$paletteHash] = $viewerUrl;
        }
        $targets[] = [
            'palette_viewer_id' => $paletteViewer->paletteViewerId,
            'saved_palette_id' => $paletteViewer->savedPaletteId,
            'palette_hash' => $paletteHash,
            'palette_viewer_url' => $viewerUrl,
            'painter_palette_viewer_url' => null,
            'title' => $title,
            'image_url' => $imageUrl,
        ];
    }

    $urls = array_values(array_unique($urls));

    foreach ($items as $item) {
        if (!$item instanceof PlaylistItem) {
            continue;
        }
        if (!$this->isPaletteViewerEligibleItem($item)) {
            continue;
        }

        $paletteHash = trim((string)($item->palette_hash ?? ''));
        if ($paletteHash === '' || !isset($urlByPaletteHash[$paletteHash])) {
            continue;
        }

        $item->palette_viewer_url = $urlByPaletteHash[$paletteHash];
        $item->painter_palette_viewer_url = null;
    }

    return [
        'count' => count($urls),
        'urls' => $urls,
        'targets' => $targets,
        'url_by_palette_hash' => $urlByPaletteHash,
    ];
}



public function buildPlaybackPlanFromProjectExperience(
    int $projectId,
    string $experienceKey,
    ?string $sourceAttribution = null,
    ?int $start = null,
    ?array $startTarget = null,
    ?string $reservationToken = null,
    ?int $playlistId = null
): array {
        $startedAt = microtime(true);
        $experienceKey = strtolower(trim($experienceKey));
        if (!in_array($experienceKey, ['public', 'concept', 'client', 'painter'], true)) {
            throw new DomainException("Player experience configuration error: unsupported experience_key '{$experienceKey}'.");
        }

        $projectRepo = new PdoProjectRepository($this->pdo);
        $project = $projectRepo->findById($projectId);
        if (!$project) {
            throw new RuntimeException("Project not found: {$projectId}");
        }
        $currentRelease = $this->loadProjectCurrentRelease($projectId);
        $this->assertProjectExperienceAllowedForRelease($experienceKey, $currentRelease);

        $projectPlaylists = $projectRepo->listProjectPlaylists($projectId);

        if ($playlistId === null || $playlistId <= 0) {
            $playlistId = (int)($projectPlaylists[0]['playlist_id'] ?? 0);
        }

        if ($playlistId <= 0) {
            throw new RuntimeException("Project {$projectId} has no attached playlist");
        }

        $attachedPlaylistIds = array_map(
            static fn(array $row): int => (int)($row['playlist_id'] ?? 0),
            $projectPlaylists
        );

        if (!in_array($playlistId, $attachedPlaylistIds, true)) {
            throw new RuntimeException(
                "Playlist {$playlistId} is not attached to project {$projectId}"
            );
        }

        $experience = $this->resolveExperienceByKey($experienceKey, "project reservation experience '{$experienceKey}'");
        $slideFlag = $this->normalizeSlideFlag($experience->slideFlag);

        $playlistStartedAt = microtime(true);
        $playlistRepo = new PdoPlaylistRepository($this->pdo);
        $playlist = $playlistRepo->getById((string)$playlistId, $slideFlag);
        $this->markTiming('load_playlist', $playlistStartedAt);
        if (!$playlist instanceof Playlist) {
            throw new RuntimeException("Playlist {$playlistId} not found for project {$projectId}");
        }

        $itemsStartedAt = microtime(true);
        $sourceItems = $this->flattenItems($playlist);
        $sourceItems = $this->filterProjectItemsForRelease(
            $sourceItems,
            $experienceKey,
            $currentRelease
        );
        $this->hydrateItemImages($sourceItems);

        $resolvedShareImageUrl = $this->resolvePlaylistShareImageUrl($sourceItems);
        $resolvedShareTitle = $this->resolvePlaylistCoverTitle($sourceItems);

        $items = $this->filterPlayableItems($sourceItems);

        $colorPlanIds = $this->collectColorPlanIds($items);
        $colorPlans = $this->loadProjectColorPlans($projectId, $colorPlanIds);
        $paletteViewerKey = $experience->paletteViewerKey;
        $showSlidePalettePrompt = $this->shouldShowSlidePalettePrompt($experience);
        $this->hydrateProjectColorPlanViewerUrls($items, $paletteViewerKey, $reservationToken);
        $this->markTiming('hydrate_items', $itemsStartedAt);
        $startIndex = $this->resolveStartIndex($items, $start, $startTarget);

        $ctaStartedAt = microtime(true);
        $ctaRepo = new PdoCtaRepository($this->pdo);
        $ctas = $ctaRepo->getByGroupId($experience->ctaPageId);
        if (!empty($ctas)) {
            $ctas = $this->hydrateArticleCtas($ctas);
        }
        $this->markTiming('load_ctas', $ctaStartedAt);

        $thumbsEnabled = $experienceKey !== 'painter' && count($colorPlanIds) > 1;
        $this->lastTiming['total'] = round((microtime(true) - $startedAt) * 1000, 1);

        $displayTitle = trim((string)($project['name'] ?? '')) ?: $playlist->title;
        $pageH1 = $this->firstNonEmpty([
            $playlist->meta['headline'] ?? null,
            $displayTitle,
            $playlist->title,
        ]);
        $projectSummary = $this->firstNonEmpty([
            $playlist->meta['dek'] ?? null,
            $playlist->meta['meta_description'] ?? null,
        ]);

        return [
            'playlist_instance_id' => null,
            'playlist_id' => $playlist->playlist_id,
            'project_id' => $projectId,
            'current_release' => $currentRelease,
            'release_is_final' => $currentRelease === 'FINAL',
            'color_plan_ids' => $colorPlanIds,
            'color_plans' => $colorPlans,
            'title' => $playlist->title,
            'display_title' => $displayTitle,
            'slug' => null,
            'page_h1' => $pageH1,
            'project_summary' => $projectSummary,
            'type' => $playlist->type,
            'total_items' => count($items),
            'start_index' => $startIndex,
            'start_target' => $this->startTargetSummary($startTarget, $startIndex),
            'items' => $items,
            'ctas' => $ctas,
            'cta_context_key' => null,
            'audience' => $experienceKey,
            'palette_viewer_cta_group_id' => null,
            'show_slide_palette_prompt' => $showSlidePalettePrompt,
            'thumbs_enabled' => $thumbsEnabled,
            'demo_enabled' => false,
            'share_enabled' => false,
            'share_title' => $this->firstNonEmpty([
                $resolvedShareTitle,
                $displayTitle,
            ]),
            'share_description' => $projectSummary,
            'share_image_url' => $resolvedShareImageUrl,
            'skip_intro_on_replay' => false,
            'hide_stars' => false,
            'playlist_instance_set_ids' => [],
            'player_experience_id' => $experience->playerExperienceId,
            'experience_key' => $experienceKey,
            'player_experience_config_key' => $experience->experienceKey,
            'experience_name' => $experience->name,
            'slide_flag' => $slideFlag,
            'palette_viewer_key' => $paletteViewerKey,
            'cta_page_id' => $experience->ctaPageId,
            'experience_source' => 'project_reservation',
            'src' => $sourceAttribution,
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
    protected function flattenItems(Playlist $playlist): array
    {
        $flat = [];
        foreach ($playlist->steps as $step) {
            foreach ($step->items as $item) {
                $flat[] = $item;
            }
        }
        return $flat;
    }

    private function resolveExperienceByKey(string $experienceKey, string $label): PlayerExperience
    {
        $repo = new PdoPlayerExperienceRepository($this->pdo);
        $experience = $repo->getByExperienceKey($experienceKey);
        $resolved = $this->validateResolvedExperience($experience, $label);
        if (!$resolved instanceof PlayerExperience) {
            throw new DomainException("Player experience configuration error: {$label} was not found.");
        }
        return $resolved;
    }

    private function loadProjectCurrentRelease(int $projectId): string
    {
        return $this->projectReleaseSelection()->currentReleaseForProject($projectId);
    }

    private function assertProjectExperienceAllowedForRelease(string $experienceKey, string $currentRelease): void
    {
        $this->projectReleaseSelection()->assertExperienceAllowed($experienceKey, $currentRelease);
    }

    /**
     * @param PlaylistItem[] $items
     * @return PlaylistItem[]
     */
    private function filterProjectItemsForRelease(
        array $items,
        string $experienceKey,
        string $currentRelease
    ): array {
        return $this->projectReleaseSelection()->filterItemsForRelease(
            $items,
            $experienceKey,
            $currentRelease
        );
    }

    /**
     * @param PlaylistItem[] $items
     * @return int[]
     */
    private function collectColorPlanIds(array $items): array
    {
        return $this->projectReleaseSelection()->collectColorPlanIds($items);
    }

    private function projectReleaseSelection(): ProjectReleaseSelectionService
    {
        if (!$this->projectReleaseSelection instanceof ProjectReleaseSelectionService) {
            $this->projectReleaseSelection = new ProjectReleaseSelectionService(
                new PdoProjectRepository($this->pdo)
            );
        }

        return $this->projectReleaseSelection;
    }

    /**
     * @param int[] $colorPlanIds
     * @return array<int, array{id:int,project_id:int,nickname:string,area_name:string,scheme_title:string}>
     */
    private function loadProjectColorPlans(int $projectId, array $colorPlanIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $colorPlanIds))));
        if ($projectId <= 0 || !$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$projectId], $ids);
        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                project_id,
                nickname,
                area_name,
                scheme_title
             FROM project_color_plans
             WHERE project_id = ?
               AND id IN ({$placeholders})"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int)$row['id'],
                'project_id' => (int)$row['project_id'],
                'nickname' => trim((string)($row['nickname'] ?? '')),
                'area_name' => trim((string)($row['area_name'] ?? '')),
                'scheme_title' => trim((string)($row['scheme_title'] ?? '')),
            ];
        }

        return $rows;
    }

    private function validateResolvedExperience(?PlayerExperience $experience, string $label): ?PlayerExperience
    {
        if (!$experience instanceof PlayerExperience) {
            if ($label === 'default Public experience') {
                return null;
            }
            throw new DomainException("Player experience configuration error: {$label} was not found.");
        }
        if (!$experience->isActive) {
            throw new DomainException("Player experience configuration error: {$label} is inactive.");
        }

        $this->normalizeSlideFlag($experience->slideFlag);

        return $experience;
    }

    private function normalizeSlideFlag(string $slideFlag): string
    {
        $value = strtolower(trim($slideFlag));
        if (in_array($value, ['public', 'full_palette'], true)) {
            return 'site';
        }
        $allowed = ['site', 'yt', 'pin',  'concept', 'client'];
        if (!in_array($value, $allowed, true)) {
            throw new DomainException("Player experience configuration error: unsupported slide_flag '{$slideFlag}'.");
        }
        return $value;
    }

    private function shouldShowSlidePalettePrompt(PlayerExperience $experience): bool
    {
        if (strtolower(trim($experience->paletteViewerKey)) === 'none') {
            return false;
        }
        return strtolower(trim($experience->experienceKey)) !== 'concept'
            && strtolower(trim($experience->slideFlag)) !== 'concept';
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
    private function resolveStartIndex(array $items, ?int $start, ?array $target): int
    {
        $count = count($items);
        if ($count <= 0) {
            return 0;
        }

        $base = null;
        $target = is_array($target) ? $target : [];

        $playlistItemId = (int)($target['playlist_item_id'] ?? 0);
        if ($playlistItemId > 0) {
            foreach ($items as $index => $item) {
                if ($item instanceof PlaylistItem && (int)($item->playlist_item_id ?? 0) === $playlistItemId) {
                    $base = $index;
                    break;
                }
            }
        }

        $photoLibraryId = (int)($target['photo_library_id'] ?? 0);
        if ($base === null && $photoLibraryId > 0) {
            foreach ($items as $index => $item) {
                if ($item instanceof PlaylistItem && (int)($item->photo_library_id ?? 0) === $photoLibraryId) {
                    $base = $index;
                    break;
                }
            }
        }

        if ($base === null && array_key_exists('position', $target) && $target['position'] !== null) {
            // Public "position" is 1-based. Existing "start" remains 0-based.
            $base = max(0, (int)$target['position'] - 1);
        }

        if ($base === null) {
            $base = $this->normalizeStartIndex($start, $count);
        }

        $offset = (int)($target['offset'] ?? 0);
        return max(0, min($count - 1, $base + $offset));
    }

    private function startTargetSummary(?array $target, int $startIndex): array
    {
        $target = is_array($target) ? $target : [];
        return [
            'start_index' => $startIndex,
            'offset' => (int)($target['offset'] ?? 0),
            'position' => isset($target['position']) && $target['position'] !== null ? (int)$target['position'] : null,
            'playlist_item_id' => isset($target['playlist_item_id']) ? (int)$target['playlist_item_id'] : null,
            'photo_library_id' => isset($target['photo_library_id']) ? (int)$target['photo_library_id'] : null,
        ];
    }

    /**
     * Return the playlist's canonical public representation image.
     *
     * Preference order:
     *   1. authored cover-image
     *   2. legacy explicit is_share_image choice
     *   3. first available photo
     *
     * @param PlaylistItem[] $items
     */
    private function resolvePlaylistShareImageUrl(array $items): ?string
    {
        $manual = null;
        $fallback = null;

        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) {
                continue;
            }

            if (!$this->playlistItemHasImage($item)) {
                continue;
            }

            if ($this->playlistItemType($item) === 'cover-image') {
                return $this->normalizeShareImageUrl(
                    (string)($item->image_url ?? '')
                );
            }

            if ($manual === null && !empty($item->is_share_image)) {
                $manual = $item;
            }

            if ($fallback === null) {
                $fallback = $item;
            }
        }

        $chosen = $manual ?? $fallback;

        if (!$chosen instanceof PlaylistItem) {
            return null;
        }

        return $this->normalizeShareImageUrl(
            (string)($chosen->image_url ?? '')
        );
    }


    /**
     * The cover-image title is the authored title for non-playback
     * representations of this playlist.
     *
     * @param PlaylistItem[] $items
     */
    private function resolvePlaylistCoverTitle(array $items): string
    {
        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) {
                continue;
            }

            if ($this->playlistItemType($item) !== 'cover-image') {
                continue;
            }

            $title = trim(
                (string)($item->title ?? '')
            );

            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }


    /**
     * cover-image belongs to the authored playlist source but never to
     * the Player timeline.
     *
     * @param PlaylistItem[] $items
     * @return PlaylistItem[]
     */
    private function filterPlayableItems(array $items): array
    {
        $playable = [];

        foreach ($items as $item) {
            if (
                $item instanceof PlaylistItem
                && $this->playlistItemType($item) === 'cover-image'
            ) {
                continue;
            }

            $playable[] = $item;
        }

        return array_values($playable);
    }


    private function playlistItemType(PlaylistItem $item): string
    {
        return strtolower(
            trim(
                (string)($item->type ?? 'normal')
            )
        );
    }

    private function playlistItemHasImage(PlaylistItem $item): bool
    {
        return (int)($item->photo_library_id ?? 0) > 0 || trim((string)($item->image_url ?? '')) !== '';
    }

    private function normalizeShareImageUrl(string $imageUrl): ?string
    {
        $raw = trim($imageUrl);
        if ($raw === '') return null;
        if (str_starts_with($raw, 'photo:')) {
            $parts = explode('|', $raw, 2);
            $resolved = trim((string)($parts[1] ?? ''));
            return $resolved !== '' ? $resolved : null;
        }
        return $raw;
    }

    /**
     * @param PlaylistItem[] $items
     */
    protected function hydrateItemImages(array $items): void
    {
        $photoIds = [];
        $assetIds = [];
        $savedPaletteHashes = [];

        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) {
                continue;
            }

            $photoId = (int)($item->photo_library_id ?? 0);
            $imageUrl = (string)($item->image_url ?? '');
            $paletteHash = trim((string)($item->palette_hash ?? ''));

            if ($paletteHash !== '') {
                $savedPaletteHashes[$paletteHash] = true;
            }

            if ($photoId > 0) {
                $photoIds[$photoId] = true;
                continue;
            }

            $assetId = $this->extractAssetId($imageUrl);

            if ($assetId !== '') {
                $assetIds[$assetId] = true;
            }
        }

        $photoMetaMap = $this->loadPhotoLibraryMeta(
            array_keys($photoIds)
        );

        $assetUrlMap = $this->loadAssetVariantUrls(
            array_keys($assetIds)
        );

        $savedPaletteTitleMap = $this->loadSavedPaletteTitles(
            array_keys($savedPaletteHashes)
        );

        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) {
                continue;
            }

            $imageUrl = (string)($item->image_url ?? '');
            $photoId = (int)($item->photo_library_id ?? 0);
            $paletteHash = trim((string)($item->palette_hash ?? ''));

            /*
             * PLAYLISTS owns palette identity.
             * Photo Library may hydrate image URL and alt text only.
             */
            if (
                empty($item->palette_title)
                && $paletteHash !== ''
                && !empty($savedPaletteTitleMap[$paletteHash])
            ) {
                $item->palette_title =
                    $savedPaletteTitleMap[$paletteHash];
            }

            if ($photoId > 0) {
                $meta = $photoMetaMap[$photoId] ?? null;

                if (is_array($meta)) {
                    $resolved = trim(
                        (string)($meta['url'] ?? '')
                    );

                    if ($resolved !== '') {
                        $item->image_url =
                            "photo:{$photoId}|{$resolved}";
                    }

                    if (
                        empty($item->alt_tag)
                        && !empty($meta['alt_tag'])
                    ) {
                        $item->alt_tag =
                            (string)$meta['alt_tag'];
                    }
                }

                continue;
            }

            $assetId = $this->extractAssetId($imageUrl);

            if ($assetId === '') {
                continue;
            }

            $resolved = $assetUrlMap[$assetId] ?? '';

            if ($resolved !== '') {
                $item->image_url = $resolved;
            }
        }
    }

    /**
     * @param PlaylistItem[] $items
     */
    private function hydrateProjectColorPlanViewerUrls(
        array $items,
        string $paletteViewerKey,
        ?string $reservationToken = null
    ): void {
        $paletteViewerKey = strtolower(trim($paletteViewerKey));
        if ($paletteViewerKey === 'none') {
            return;
        }

        $tokenService = new PaletteViewerTokenService($this->pdo);
        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) continue;
            $colorPlanId = (int)($item->color_plan_id ?? 0);
            if ($colorPlanId <= 0) continue;

            $item->palette_viewer_url = $tokenService->createProjectColorPlanUrl(
                $colorPlanId,
                $paletteViewerKey,
                $reservationToken
            );

            if (in_array($paletteViewerKey, ['concept', 'client'], true)) {
                $item->painter_palette_viewer_url = $tokenService->createProjectColorPlanUrl(
                    $colorPlanId,
                    'painter',
                    $reservationToken
                );
            }
        }
    }

    private function isPaletteViewerEligibleItem(PlaylistItem $item): bool
    {
        $type = strtolower((string)($item->type ?? 'normal'));
        if (in_array($type, ['intro', 'before', 'text', 'hue-wheel', 'brand-bumper', 'cover-image', 'non-palette'], true)) {
            return false;
        }
        if (!empty($item->exclude_from_thumbs)) return false;
        if (strtolower((string)($item->saved_palette_photo_type ?? '')) === 'before') return false;
        return trim((string)($item->palette_hash ?? '')) !== '';
    }

    /**
     * @param string[] $paletteHashes
     * @return array<string, string>
     */
    private function loadSavedPaletteTitles(array $paletteHashes): array
    {
        $hashes = array_values(array_unique(array_filter(array_map(
            static fn($hash) => trim((string)$hash),
            $paletteHashes
        ))));
        if (!$hashes) return [];
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT
                palette_hash,
                COALESCE(
                    NULLIF(TRIM(display_title), ''),
                    NULLIF(TRIM(nickname), ''),
                    palette_hash
                ) AS palette_title
             FROM saved_palettes
             WHERE palette_hash IN ({$placeholders})"
        );
        $stmt->execute($hashes);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hash = trim((string)($row['palette_hash'] ?? ''));
            $title = trim((string)($row['palette_title'] ?? ''));
            if ($hash !== '' && $title !== '') {
                $map[$hash] = $title;
            }
        }
        return $map;
    }

    /**
     * @param int[] $photoIds
     * @return array<int, array{url:string,palette_hash:?string,palette_title:?string,saved_palette_set_id:?int,saved_palette_photo_type:?string,alt_tag:?string}>
     */
    private function loadPhotoLibraryMeta(array $photoIds): array
    {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $photoIds),
                    static fn(int $id): bool => $id > 0
                )
            )
        );

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($ids), '?')
        );

        $stmt = $this->pdo->prepare(
            "SELECT
                photo_library_id,
                rel_path,
                updated_at,
                ai_alt_text,
                alt_text
             FROM photo_library
             WHERE photo_library_id IN ({$placeholders})"
        );

        $stmt->execute($ids);

        $map = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $photoLibraryId = (int)(
                $row['photo_library_id']
                ?? 0
            );

            if ($photoLibraryId <= 0) {
                continue;
            }

            $map[$photoLibraryId] = [
                'url' => $this->appendCacheBuster(
                    (string)($row['rel_path'] ?? ''),
                    (string)($row['updated_at'] ?? '')
                ),
                'alt_tag' => $this->firstNonEmpty([
                    $row['ai_alt_text'] ?? null,
                    $row['alt_text'] ?? null,
                ]) ?: null,
            ];
        }

        return $map;
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

    protected function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function markTiming(string $key, float $startedAt): void
    {
        $this->lastTiming[$key] = round((microtime(true) - $startedAt) * 1000, 1);
    }
}
