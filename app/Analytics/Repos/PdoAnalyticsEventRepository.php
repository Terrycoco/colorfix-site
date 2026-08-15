<?php
declare(strict_types=1);

namespace App\Analytics\Repos;

use App\Analytics\Contracts\AnalyticsEventRepositoryInterface;
use App\Analytics\DTO\AnalyticsEvent;
use PDO;

final class PdoAnalyticsEventRepository implements AnalyticsEventRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function record(AnalyticsEvent $event): int
    {
        $payloadJson = $event->payload !== []
            ? json_encode($event->payload, JSON_UNESCAPED_SLASHES)
            : null;

        if ($payloadJson === false) {
            throw new \RuntimeException('Analytics payload could not be encoded.');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO analytics_events (
                event_key,
                reservation_id,
                reservation_token,
                resolver_key,
                resource_type,
                resource_id,
                experience_key,
                source_key,
                viewer_id,
                path,
                payload_json,
                created_at
            ) VALUES (
                :event_key,
                :reservation_id,
                :reservation_token,
                :resolver_key,
                :resource_type,
                :resource_id,
                :experience_key,
                :source_key,
                :viewer_id,
                :path,
                :payload_json,
                NOW()
            )"
        );

        $stmt->execute([
            ':event_key' => trim($event->eventKey),
            ':reservation_id' => $event->reservationId,
            ':reservation_token' => $event->reservationToken,
            ':resolver_key' => $event->resolverKey,
            ':resource_type' => $event->resourceType,
            ':resource_id' => $event->resourceId,
            ':experience_key' => $event->experienceKey,
            ':source_key' => $event->src,
            ':viewer_id' => $event->viewerId,
            ':path' => $event->path,
            ':payload_json' => $payloadJson,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

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
                COUNT(*) AS event_count
            FROM analytics_events
            WHERE resource_type = :resource_type
            AND event_key = :event_key
            AND resource_id IS NOT NULL
            GROUP BY resource_id, source_key
            ORDER BY event_count DESC, resource_id ASC"
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