<?php
declare(strict_types=1);

namespace App\Analytics\Services;

use App\Analytics\Contracts\AnalyticsEventRepositoryInterface;
use App\Analytics\DTO\AnalyticsEvent;
use App\Repos\PdoPlaylistRepository;

final class AnalyticsService
{
    public function __construct(
        private AnalyticsEventRepositoryInterface $events,
        private ?PdoPlaylistRepository $playlists = null,
    ) {}

    public function record(AnalyticsEvent $event): int
    {
        return $this->events->record($event);
    }

    public function countEventsByResourceType(
        string $resourceType,
        string $eventKey
    ): array {
        $rows = $this->events->countEventsByResourceType(
            $resourceType,
            $eventKey
        );

        if ($resourceType !== 'playlist' || $this->playlists === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $playlistId = (int)($row['resource_id'] ?? 0);

            $playlist = $playlistId > 0
                ? $this->playlists->getAdminRowById($playlistId)
                : null;

            $row['title'] = $playlist['title'] ?? "Playlist #{$playlistId}";
        }

        unset($row);

        return $rows;
    }

    public function listResourceTypes(): array
    {
        return $this->events->listResourceTypes();
    }


    public function countPlaylistEngagement(): array
    {
        $rows = $this->events->countPlaylistEngagement();

        if ($this->playlists === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $playlistId = (int)($row['resource_id'] ?? 0);

            $playlist = $playlistId > 0
                ? $this->playlists->getAdminRowById($playlistId)
                : null;

            $row['title'] = $playlist['title'] ?? "Playlist #{$playlistId}";

            $opens = (int)($row['opens'] ?? 0);
            $watchMore = (int)($row['watch_more'] ?? 0);

            $row['watch_more_rate'] = $opens > 0
                ? round(($watchMore / $opens) * 100, 1)
                : 0;
        }

        unset($row);

        return $rows;
    }

    
}