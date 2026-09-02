<?php
declare(strict_types=1);

namespace App\ANA\Repos;

use App\ANA\Contracts\ANAReportRepositoryInterface;
use PDO;

final class PdoANAReportRepository implements ANAReportRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function countEventsByResourceType(
        string $resourceType,
        string $eventKey
    ): array {
        $resourceType = trim($resourceType);
        $eventKey = trim($eventKey);

        if ($resourceType === '' || $eventKey === '') {
            return [];
        }

$stmt = $this->pdo->prepare(
    "SELECT
        resource_id,
        source_key,
        COUNT(*) AS event_count,
        MAX(created_at) AS last_visit
    FROM analytics_events
    WHERE resource_type = :resource_type
    AND event_key = :event_key
    AND resource_id IS NOT NULL
    GROUP BY resource_id, source_key
    ORDER BY last_visit DESC, resource_id ASC"
);

        $stmt->execute([
            ':resource_type' => $resourceType,
            ':event_key' => $eventKey,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listResourceTypes(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT resource_type
            FROM analytics_events
            WHERE resource_type IS NOT NULL
            AND TRIM(resource_type) <> ''
            ORDER BY resource_type ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function countPlaylistEngagement(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                resource_id,
                source_key,
                SUM(event_key = 'playlist_open') AS opens,
                SUM(event_key = 'watch_more_click') AS watch_more
            FROM analytics_events
            WHERE resource_type = 'playlist'
            AND resource_id IS NOT NULL
            AND event_key IN ('playlist_open', 'watch_more_click')
            GROUP BY resource_id, source_key
            ORDER BY opens DESC, resource_id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}