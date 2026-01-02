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
        ?int $start = null
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

        // 4. Load CTAs for this instance
        $ctas = [];
        if ($instance->ctaGroupId !== null) {
            $ctaRepo = new PdoCtaRepository($this->pdo);
            $ctas = $ctaRepo->getByGroupId($instance->ctaGroupId);
        }

        // 5. Return full playback plan
        return [
            'playlist_instance_id' => $instance->id,
            'playlist_id'          => $playlist->playlist_id,
            'title'                => $playlist->title,
            'type'                 => $playlist->type,
            'total_items'          => count($items),
            'start_index'          => $startIndex,
            'items'                => $items,
            'ctas'                 => $ctas,
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
}
