<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPlaylistRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoCtaRepository;
use App\Entities\Playlist;
use App\Entities\PlaylistItem;
use App\Entities\PlaylistInstance;
use PDO;
use RuntimeException;

final class PlayerExperienceService
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function buildPlaybackPlanFromInstance(
        int $playlistInstanceId,
        ?int $start = null,
        ?string $ctaContext = null,
        ?int $addCtaGroupId = null
    ): array {
        // 1. Load playlist instance
        $instanceRepo = new PdoPlaylistInstanceRepository($this->pdo);
        $instance = $instanceRepo->getById($playlistInstanceId);

        if (!$instance instanceof PlaylistInstance) {
            throw new RuntimeException("Playlist instance not found: {$playlistInstanceId}");
        }

        // 2. Load playlist
        $playlistRepo = new PdoPlaylistRepository($this->pdo);
        $playlist = $playlistRepo->getById((string)$instance->playlistId);

        if (!$playlist instanceof Playlist) {
            throw new RuntimeException(
                "Playlist {$instance->playlistId} not found for instance {$playlistInstanceId}"
            );
        }

        // 3. Flatten playlist items
        $items = $this->flattenItems($playlist);
        $startIndex = $this->normalizeStartIndex($start, count($items));

        // 4. Load CTAs for this instance (optionally scoped by context) + optional add-on group.
        $ctas = [];
        $ctaRepo = null;
        if ($instance->ctaGroupId !== null) {
            $context = strtolower(trim((string)($instance->ctaContextKey ?? '')));
            $requestContext = strtolower(trim((string)($ctaContext ?? '')));
            $shouldInclude = true;
            if ($context !== '' && $context !== 'default') {
                $shouldInclude = $requestContext !== '' && $requestContext === $context;
            }
            if ($shouldInclude) {
                $ctaRepo = new PdoCtaRepository($this->pdo);
                $ctas = $ctaRepo->getByGroupId($instance->ctaGroupId);
            }
        }
        if ($addCtaGroupId !== null && $addCtaGroupId > 0) {
            if ($ctaRepo === null) $ctaRepo = new PdoCtaRepository($this->pdo);
            $extra = $ctaRepo->getByGroupId($addCtaGroupId);
            if ($extra) {
                $ctas = $this->mergeCtas($ctas, $extra);
            }
        }
        if (!empty($ctas) && !empty($instance->ctaOverrides)) {
            $decoded = json_decode($instance->ctaOverrides, true);
            if (is_array($decoded)) {
                $ctas = $this->applyCtaOverrides($ctas, $decoded);
            }
        }

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
            'share_enabled'        => $instance->shareEnabled,
            'share_title'          => $instance->shareTitle,
            'share_description'    => $instance->shareDescription,
            'share_image_url'      => $instance->shareImageUrl,
            'hide_stars'           => $instance->hideStars,
        ];
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
}
