<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPlaylistRepository;
use App\Entities\Playlist;
use App\Entities\PlaylistItem;
use RuntimeException;

class PlayerExperienceService
{
    public function buildPlaybackPlan(string $playlistId, ?int $start = null): array
    {
        $repo = new PdoPlaylistRepository();
        $playlist = $repo->getById($playlistId);
        if (!$playlist instanceof Playlist) {
            throw new RuntimeException("Playlist not found: {$playlistId}");
        }

        $items = $this->flattenItems($playlist);
        $startIndex = $this->normalizeStartIndex($start, count($items));

        return [
            'playlist_id' => $playlist->playlist_id,
            'type' => $playlist->type,
            'title' => $playlist->title,
            'total_items' => count($items),
            'start_index' => $startIndex,
            'items' => $items,
        ];
    }

    /**
     * Convert grouped steps into a linear list of PlaylistItem objects.
     *
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
