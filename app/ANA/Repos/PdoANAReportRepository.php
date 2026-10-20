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

        // Reporting uses the registered label, but never rewrites the
        // original analytics_events.source_key.
        $label = 'COALESCE(sp.label, e.source_key)';

        if ($resourceType === 'outbound_link') {
            $stmt = $this->pdo->prepare(
                "SELECT NULL AS resource_id, {$label} AS source_label,
                        JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.target_url')) AS target_url,
                        COUNT(*) AS event_count, MAX(e.created_at) AS last_visit,
                        (SELECT e2.path
                           FROM analytics_events e2
                           LEFT JOIN src_params sp2 ON sp2.src = e2.source_key
                          WHERE e2.resource_type = e.resource_type
                            AND e2.event_key = e.event_key
                            AND JSON_VALID(e2.payload_json)
                            AND JSON_UNQUOTE(JSON_EXTRACT(e2.payload_json, '$.target_url')) = JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.target_url'))
                            AND COALESCE(sp2.label, e2.source_key) <=> {$label}
                          ORDER BY e2.created_at DESC, e2.id DESC
                          LIMIT 1) AS visit_path
                   FROM analytics_events e
                   LEFT JOIN src_params sp ON sp.src = e.source_key
                  WHERE e.resource_type = :resource_type AND e.event_key = :event_key
                    AND JSON_VALID(e.payload_json)
                    AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.target_url')) IS NOT NULL
                  GROUP BY source_label, target_url
                  ORDER BY last_visit DESC"
            );
            $stmt->execute([':resource_type' => $resourceType, ':event_key' => $eventKey]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql = $resourceType === 'page'
            ? "SELECT e.resource_id, {$label} AS source_label, COUNT(*) AS event_count,
                      MAX(e.created_at) AS last_visit, p.title AS title,
                      (SELECT e2.path
                         FROM analytics_events e2
                         LEFT JOIN src_params sp2 ON sp2.src = e2.source_key
                        WHERE e2.resource_type = e.resource_type
                          AND e2.event_key = e.event_key
                          AND e2.resource_id = e.resource_id
                          AND COALESCE(sp2.label, e2.source_key) <=> {$label}
                        ORDER BY e2.created_at DESC, e2.id DESC
                        LIMIT 1) AS visit_path
                 FROM analytics_events e
                 LEFT JOIN src_params sp ON sp.src = e.source_key
                 LEFT JOIN ana_pages p ON p.id = e.resource_id
                WHERE e.resource_type = :resource_type
                  AND e.event_key = :event_key
                  AND e.resource_id IS NOT NULL
                GROUP BY e.resource_id, source_label, p.title
                ORDER BY last_visit DESC, e.resource_id ASC"
            : "SELECT e.resource_id, {$label} AS source_label, COUNT(*) AS event_count,
                      MAX(e.created_at) AS last_visit,
                      (SELECT e2.path
                         FROM analytics_events e2
                         LEFT JOIN src_params sp2 ON sp2.src = e2.source_key
                        WHERE e2.resource_type = e.resource_type
                          AND e2.event_key = e.event_key
                          AND e2.resource_id = e.resource_id
                          AND COALESCE(sp2.label, e2.source_key) <=> {$label}
                        ORDER BY e2.created_at DESC, e2.id DESC
                        LIMIT 1) AS visit_path
                 FROM analytics_events e
                 LEFT JOIN src_params sp ON sp.src = e.source_key
                WHERE e.resource_type = :resource_type
                  AND e.event_key = :event_key
                  AND e.resource_id IS NOT NULL
                GROUP BY e.resource_id, source_label
                ORDER BY last_visit DESC, e.resource_id ASC";
        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':resource_type' => $resourceType,
            ':event_key' => $eventKey,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($resourceType === 'doc' && $rows !== []) {
            $ids = array_values(array_unique(array_map('intval', array_column($rows, 'resource_id'))));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $titles = $this->pdo->prepare(
                "SELECT id, title FROM project_documents WHERE id IN ({$placeholders})"
            );
            $titles->execute($ids);
            $byId = $titles->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($rows as &$row) {
                $id = (int)$row['resource_id'];
                $title = trim((string)($byId[$id] ?? ''));
                $row['title'] = $title !== '' ? $title : "Document #{$id}";
            }
            unset($row);
        }

        if ($resourceType === 'palette_viewer' && $rows !== []) {
            $ids = array_values(array_unique(array_column($rows, 'resource_id')));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $titles = $this->pdo->prepare(
                "SELECT palette_viewer_id, title
                   FROM palette_viewers
                  WHERE palette_viewer_id IN ({$placeholders})"
            );
            $titles->execute($ids);
            $byId = $titles->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($rows as &$row) {
                $id = (int)$row['resource_id'];
                $title = trim((string)($byId[$id] ?? ''));
                $row['title'] = $title !== '' ? $title : "Viewer #{$id}";
            }
            unset($row);
        }

        return $rows;
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
                e.resource_id,
                COALESCE(sp.label, e.source_key) AS source_label,
                SUM(e.event_key = 'playlist_open') AS opens,
                SUM(e.event_key = 'watch_more_click') AS watch_more
            FROM analytics_events e
            LEFT JOIN src_params sp ON sp.src = e.source_key
            WHERE e.resource_type = 'playlist'
            AND e.resource_id IS NOT NULL
            AND e.event_key IN ('playlist_open', 'watch_more_click')
            GROUP BY e.resource_id, source_label
            ORDER BY opens DESC, e.resource_id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listEventsForResource(
        string $resourceType,
        ?int $resourceId,
        string $eventKey,
        ?string $sourceLabel,
        ?string $targetUrl = null
    ): array {
        $resourceType = trim($resourceType);
        $eventKey = trim($eventKey);

        if ($resourceType === '' || $eventKey === '' ||
            ($resourceType === 'outbound_link' ? !$targetUrl : ($resourceId === null || $resourceId <= 0))) {
            return [];
        }

        $outbound = $resourceType === 'outbound_link';
        $resourceFilter = $outbound
            ? "e.resource_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.target_url')) = :target_url"
            : "e.resource_id = :resource_id";
        $stmt = $this->pdo->prepare(
            "SELECT
                e.id,
                e.event_key,
                e.reservation_id,
                e.reservation_token,
                e.resolver_key,
                e.resource_type,
                e.resource_id,
                e.experience_key,
                e.source_key,
                e.session_id,
                e.referrer,
                e.viewer_id,
                e.is_test,
                e.path,
                e.payload_json,
                e.created_at
            FROM analytics_events e
            LEFT JOIN src_params sp ON sp.src = e.source_key
            WHERE e.resource_type = :resource_type
            AND {$resourceFilter}
            AND e.event_key = :event_key
            AND COALESCE(sp.label, e.source_key) <=> :source_label
            ORDER BY e.created_at DESC, e.id DESC"
        );

        $params = [
            ':resource_type' => $resourceType,
            ':event_key' => $eventKey,
            ':source_label' => $sourceLabel,
        ];
        if ($outbound) {
            $params[':target_url'] = $targetUrl;
        } else {
            $params[':resource_id'] = $resourceId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deleteEventById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "DELETE FROM analytics_events
            WHERE id = :id
            LIMIT 1"
        );

        $stmt->execute([
            ':id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
