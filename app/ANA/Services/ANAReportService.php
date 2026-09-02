<?php
declare(strict_types=1);

namespace App\ANA\Services;

use App\ANA\Contracts\ANAReportRepositoryInterface;
use App\Repos\PdoPlaylistRepository;
use App\Repos\PdoArticleRepository;
use App\REX\Resources\RexRouteCatalog;

final class ANAReportService
{
    public function __construct(
        private ANAReportRepositoryInterface $reports,
        private ?PdoPlaylistRepository $playlists = null,
        private ?PdoArticleRepository $articles = null,
    ) {}

    public function countEventsByResourceType(
        string $resourceType,
        string $eventKey
    ): array {
        $rows = $this->reports->countEventsByResourceType(
            $resourceType,
            $eventKey
        );

        if ($resourceType === 'page') {
            foreach ($rows as &$row) {
                $pageId = (int)($row['resource_id'] ?? 0);
                $route = $pageId > 0
                    ? RexRouteCatalog::get($pageId)
                    : null;

                $row['title'] = $route['title'] ?? "Page #{$pageId}";
            }

            unset($row);

            return $rows;
        }

        if ($resourceType === 'article' && $this->articles !== null) {
            foreach ($rows as &$row) {
                $articleId = (int)($row['resource_id'] ?? 0);

                $article = $articleId > 0
                    ? $this->articles->getArticleById($articleId)
                    : null;

                $row['title'] = $article['title'] ?? "Article #{$articleId}";
            }

            unset($row);

            return $rows;
        }

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
        return $this->reports->listResourceTypes();
    }

    public function countPlaylistEngagement(): array
    {
        $rows = $this->reports->countPlaylistEngagement();

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