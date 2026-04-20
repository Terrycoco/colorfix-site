<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoUserEventRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function insert(array $payload): int
    {
        $sql = <<<SQL
            INSERT INTO user_events (
                event_type,
                playlist_id,
                playlist_instance_id,
                cta_id,
                session_id,
                referrer,
                user_agent,
                is_internal
            ) VALUES (
                :event_type,
                :playlist_id,
                :playlist_instance_id,
                :cta_id,
                :session_id,
                :referrer,
                :user_agent,
                :is_internal
            )
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'event_type' => (string)($payload['event_type'] ?? ''),
            'playlist_id' => isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : null,
            'playlist_instance_id' => isset($payload['playlist_instance_id']) ? (int)$payload['playlist_instance_id'] : null,
            'cta_id' => isset($payload['cta_id']) ? (int)$payload['cta_id'] : null,
            'session_id' => $this->normalizeNullableString($payload['session_id'] ?? null, 100),
            'referrer' => $this->normalizeNullableString($payload['referrer'] ?? null, 1000),
            'user_agent' => $this->normalizeNullableString($payload['user_agent'] ?? null, 1000),
            'is_internal' => !empty($payload['is_internal']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function countByEventTypeForPlaylistInstance(int $playlistInstanceId, string $eventType): int
    {
        $sql = <<<SQL
            SELECT COUNT(*) AS total
            FROM user_events
            WHERE playlist_instance_id = :playlist_instance_id
              AND event_type = :event_type
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'playlist_instance_id' => $playlistInstanceId,
            'event_type' => $eventType,
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * @return array<string, int>
     */
    public function getEventCountsForPlaylistInstance(int $playlistInstanceId): array
    {
        $sql = <<<SQL
            SELECT event_type, COUNT(*) AS total
            FROM user_events
            WHERE playlist_instance_id = :playlist_instance_id
            GROUP BY event_type
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'playlist_instance_id' => $playlistInstanceId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['event_type']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getPlaylistInstanceFunnelRows(array $filters = []): array
    {
        $where = [];
        $params = [];

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(CAST(pi.playlist_instance_id AS CHAR) LIKE :q OR pi.instance_name LIKE :q OR pi.display_title LIKE :q OR p.title LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $audience = trim((string)($filters['audience'] ?? ''));
        if ($audience !== '' && $audience !== 'all') {
            $where[] = 'COALESCE(pi.audience, \'any\') = :audience';
            $params['audience'] = $audience;
        }

        $includeInternal = !empty($filters['include_internal']);
        if (!$includeInternal) {
            $where[] = 'COALESCE(ue.is_internal, 0) = 0';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = <<<SQL
            SELECT
                pi.playlist_instance_id,
                pi.playlist_id,
                pi.instance_name,
                pi.display_title,
                COALESCE(pi.audience, 'any') AS audience,
                p.title AS playlist_title,
                SUM(CASE WHEN ue.event_type = 'playlist_open' THEN 1 ELSE 0 END) AS playlist_open_count,
                SUM(CASE WHEN ue.event_type = 'hire_terry_cta_visible' THEN 1 ELSE 0 END) AS hire_terry_cta_visible_count,
                SUM(CASE WHEN ue.event_type = 'hire_terry_cta_click' THEN 1 ELSE 0 END) AS hire_terry_cta_click_count,
                SUM(CASE WHEN ue.event_type = 'watch_next_click' THEN 1 ELSE 0 END) AS watch_next_click_count,
                MAX(ue.created_at) AS last_event_at
            FROM playlist_instances pi
            LEFT JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            LEFT JOIN user_events ue
              ON ue.playlist_instance_id = pi.playlist_instance_id
            {$whereSql}
            GROUP BY
                pi.playlist_instance_id,
                pi.playlist_id,
                pi.instance_name,
                pi.display_title,
                pi.audience,
                p.title
            HAVING
                playlist_open_count > 0
                OR hire_terry_cta_visible_count > 0
                OR hire_terry_cta_click_count > 0
                OR watch_next_click_count > 0
            ORDER BY last_event_at DESC, pi.playlist_instance_id DESC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function getOverallFunnelCounts(array $filters = []): array
    {
        $where = [];
        $params = [];

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(CAST(pi.playlist_instance_id AS CHAR) LIKE :q OR pi.instance_name LIKE :q OR pi.display_title LIKE :q OR p.title LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $audience = trim((string)($filters['audience'] ?? ''));
        if ($audience !== '' && $audience !== 'all') {
            $where[] = 'COALESCE(pi.audience, \'any\') = :audience';
            $params['audience'] = $audience;
        }

        $includeInternal = !empty($filters['include_internal']);
        if (!$includeInternal) {
            $where[] = 'COALESCE(ue.is_internal, 0) = 0';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = <<<SQL
            SELECT
                SUM(CASE WHEN ue.event_type = 'playlist_open' THEN 1 ELSE 0 END) AS playlist_open_count,
                SUM(CASE WHEN ue.event_type = 'hire_terry_cta_visible' THEN 1 ELSE 0 END) AS hire_terry_cta_visible_count,
                SUM(CASE WHEN ue.event_type = 'hire_terry_cta_click' THEN 1 ELSE 0 END) AS hire_terry_cta_click_count,
                SUM(CASE WHEN ue.event_type = 'watch_next_click' THEN 1 ELSE 0 END) AS watch_next_click_count
            FROM user_events ue
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = ue.playlist_instance_id
            LEFT JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            {$whereSql}
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'playlist_open_count' => (int)($row['playlist_open_count'] ?? 0),
            'hire_terry_cta_visible_count' => (int)($row['hire_terry_cta_visible_count'] ?? 0),
            'hire_terry_cta_click_count' => (int)($row['hire_terry_cta_click_count'] ?? 0),
            'watch_next_click_count' => (int)($row['watch_next_click_count'] ?? 0),
        ];
    }

    private function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) return null;
        $normalized = trim((string)$value);
        if ($normalized === '') return null;
        return mb_substr($normalized, 0, $maxLength);
    }
}
