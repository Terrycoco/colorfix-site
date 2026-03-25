<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoUserEventRepository;
use DateTimeImmutable;
use DateTimeZone;

final class UserEventService
{
    private const EVENT_SOURCE_TIMEZONE = 'America/Denver';

    public function __construct(
        private PdoUserEventRepository $repo
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   totals: array<string, int>,
     *   items: array<int, array<string, mixed>>
     * }
     */
    public function getPlaylistFunnelDashboard(array $filters = []): array
    {
        $totals = $this->repo->getOverallFunnelCounts($filters);
        $rows = $this->repo->getPlaylistInstanceFunnelRows($filters);

        $items = array_map(function (array $row): array {
            $openCount = (int)($row['playlist_open_count'] ?? 0);
            $visibleCount = (int)($row['hire_terry_cta_visible_count'] ?? 0);
            $clickCount = (int)($row['hire_terry_cta_click_count'] ?? 0);

            return [
                'playlist_instance_id' => (int)$row['playlist_instance_id'],
                'playlist_id' => (int)$row['playlist_id'],
                'instance_name' => (string)($row['instance_name'] ?? ''),
                'display_title' => $row['display_title'] !== null ? (string)$row['display_title'] : '',
                'audience' => (string)($row['audience'] ?? 'any'),
                'playlist_title' => $row['playlist_title'] !== null ? (string)$row['playlist_title'] : '',
                'playlist_open_count' => $openCount,
                'hire_terry_cta_visible_count' => $visibleCount,
                'hire_terry_cta_click_count' => $clickCount,
                'visible_rate' => $openCount > 0 ? round(($visibleCount / $openCount) * 100, 1) : 0.0,
                'click_through_rate' => $visibleCount > 0 ? round(($clickCount / $visibleCount) * 100, 1) : 0.0,
                'last_event_at' => $row['last_event_at'] !== null ? (string)$row['last_event_at'] : '',
                'last_event_at_iso' => $this->normalizeEventTime($row['last_event_at'] ?? null),
            ];
        }, $rows);

        return [
            'totals' => $totals,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(array $payload): int
    {
        $eventType = trim((string)($payload['event_type'] ?? ''));
        $allowed = [
            'playlist_open',
            'hire_terry_cta_visible',
            'hire_terry_cta_click',
        ];
        if ($eventType === '' || !in_array($eventType, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid event_type');
        }

        $playlistInstanceId = isset($payload['playlist_instance_id']) ? (int)$payload['playlist_instance_id'] : 0;
        if ($playlistInstanceId <= 0) {
            throw new \InvalidArgumentException('playlist_instance_id required');
        }

        $normalized = [
            'event_type' => $eventType,
            'playlist_id' => isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : null,
            'playlist_instance_id' => $playlistInstanceId,
            'cta_id' => isset($payload['cta_id']) && $payload['cta_id'] !== '' ? (int)$payload['cta_id'] : null,
            'session_id' => $payload['session_id'] ?? null,
            'referrer' => $payload['referrer'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
            'is_internal' => !empty($payload['is_internal']),
        ];

        return $this->repo->insert($normalized);
    }

    private function normalizeEventTime(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($raw, new DateTimeZone(self::EVENT_SOURCE_TIMEZONE));
            return $dt->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
