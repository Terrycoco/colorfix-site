<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\Playlist;
use App\Repos\PdoPlaylistRepository;
use PDO;
use RuntimeException;

final class YoutubeVideoExperienceService extends PlayerExperienceService
{
    private const DEFAULT_SLIDE_DURATION_MS = 4200;
    private const DEFAULT_INTRO_DURATION_MS = 3600;
    private const DEFAULT_TEXT_DURATION_MS = 7600;
    private const DEFAULT_HUE_WHEEL_DURATION_MS = 6200;
    private const DISSOLVE_MS = 2000;
    private const CUT_MS = 200;
    private const FINAL_FADE_MS = 1400;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    public function buildVideoPlanFromPlaylist(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new RuntimeException('playlist_id required');
        }

        $playlistRepo = new PdoPlaylistRepository($this->pdo);
        $playlist = $playlistRepo->getById((string)$playlistId, 'yt');

        if (!$playlist instanceof Playlist) {
            throw new RuntimeException("Playlist not found: {$playlistId}");
        }

        $items = $this->flattenItems($playlist);
        $this->hydrateItemImages($items);
        $items = array_values(array_filter($items, static function ($item): bool {
            return $item?->yt !== false;
        }));

        if (!$items) {
            throw new RuntimeException("Playlist {$playlistId} does not have YouTube-enabled slides.");
        }

        return [
            'playlist_id' => (int)$playlist->playlist_id,
            'title' => $playlist->title,
            'type' => $playlist->type,
            'total_items' => count($items),
            'items' => $items,
            'video' => [
                'width' => 1920,
                'height' => 1080,
                'fps' => 30,
                'default_slide_duration_ms' => self::DEFAULT_SLIDE_DURATION_MS,
                'default_intro_duration_ms' => self::DEFAULT_INTRO_DURATION_MS,
                'default_text_duration_ms' => self::DEFAULT_TEXT_DURATION_MS,
                'default_hue_wheel_duration_ms' => self::DEFAULT_HUE_WHEEL_DURATION_MS,
                'dissolve_ms' => self::DISSOLVE_MS,
                'cut_ms' => self::CUT_MS,
                'final_fade_ms' => self::FINAL_FADE_MS,
                'timeline' => $this->buildTimeline($items),
            ],
        ];
    }

    private function buildTimeline(array $items): array
    {
        $timeline = [];
        $cursor = 0;

        foreach ($items as $index => $item) {
            $type = strtolower(trim((string)($item->type ?? 'normal')));
            $duration = (int)($item->duration_ms ?? 0);
            if ($duration <= 0) {
                $duration = match ($type) {
                    'intro' => self::DEFAULT_INTRO_DURATION_MS,
                    'text' => self::DEFAULT_TEXT_DURATION_MS,
                    'hue-wheel' => self::DEFAULT_HUE_WHEEL_DURATION_MS,
                    default => self::DEFAULT_SLIDE_DURATION_MS,
                };
            }

            $transition = strtolower(trim((string)($item->transition ?? 'animation')));
            $transitionMs = $transition === 'cut' ? self::CUT_MS : self::DISSOLVE_MS;

            $timeline[] = [
                'index' => $index,
                'start_ms' => $cursor,
                'duration_ms' => $duration,
                'end_ms' => $cursor + $duration,
                'transition' => $transition === 'cut' ? 'cut' : 'dissolve',
                'transition_ms' => $transitionMs,
            ];

            $cursor += $duration;
        }

        return $timeline;
    }
}
