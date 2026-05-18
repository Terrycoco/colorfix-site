<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\AppTime;
use App\Repos\PdoAppConfigRepository;
use App\Repos\PdoUserEventRepository;
use DateTimeImmutable;
use DateTimeZone;

final class UserEventService
{
    private const BASELINE_CONFIG_KEY = 'user_events_baseline';

    public function __construct(
        private PdoUserEventRepository $repo,
        private ?PdoAppConfigRepository $configRepo = null
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
        $baseline = $this->getBaseline();
        if (!empty($baseline['cutoff_at'])) {
            $filters['created_from'] = (string)$baseline['cutoff_at'];
        }

        $totals = $this->repo->getOverallFunnelCounts($filters);
        $rows = $this->repo->getPlaylistInstanceFunnelRows($filters);

        $items = array_map(function (array $row): array {
            $openCount = (int)($row['playlist_open_count'] ?? 0);
            $visibleCount = (int)($row['hire_terry_cta_visible_count'] ?? 0);
            $clickCount = (int)($row['hire_terry_cta_click_count'] ?? 0);
            $watchNextCount = (int)($row['watch_next_click_count'] ?? 0);

            return [
                'playlist_instance_id' => (int)$row['playlist_instance_id'],
                'playlist_id' => (int)$row['playlist_id'],
                'instance_name' => (string)($row['instance_name'] ?? ''),
                'display_title' => $row['display_title'] !== null ? (string)$row['display_title'] : '',
                'audience' => (string)($row['audience'] ?? 'any'),
                'playlist_title' => $row['playlist_title'] !== null ? (string)$row['playlist_title'] : '',
                'source' => (string)($row['source'] ?? 'direct'),
                'playlist_open_count' => $openCount,
                'hire_terry_cta_visible_count' => $visibleCount,
                'hire_terry_cta_click_count' => $clickCount,
                'watch_next_click_count' => $watchNextCount,
                'visible_rate' => $openCount > 0 ? round(($visibleCount / $openCount) * 100, 1) : 0.0,
                'click_through_rate' => $visibleCount > 0 ? round(($clickCount / $visibleCount) * 100, 1) : 0.0,
                'watch_next_rate' => $openCount > 0 ? round(($watchNextCount / $openCount) * 100, 1) : 0.0,
                'last_event_at' => $row['last_event_at'] !== null ? (string)$row['last_event_at'] : '',
                'last_event_at_iso' => $this->normalizeStoredUtcTime($row['last_event_at'] ?? null),
            ];
        }, $rows);

        return [
            'totals' => $totals,
            'items' => $items,
            'baseline' => [
                'cutoff_at' => isset($baseline['cutoff_at']) ? (string)$baseline['cutoff_at'] : '',
                'cutoff_at_iso' => $this->normalizeStoredUtcTime($baseline['cutoff_at'] ?? null),
            ],
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
            'watch_next_click',
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
            'source' => $this->normalizeSource($payload['source'] ?? null),
            'session_id' => $payload['session_id'] ?? null,
            'referrer' => $payload['referrer'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
            'is_internal' => !empty($payload['is_internal']),
        ];

        return $this->repo->insert($normalized);
    }

    /**
     * @return array{cutoff_at:string}
     */
    public function setBaselineNow(): array
    {
        return $this->setBaselineAt(AppTime::now());
    }

    /**
     * @return array{cutoff_at:string}
     */
    public function setBaselineAt(string $cutoffAt): array
    {
        $normalized = $this->normalizeLocalInputToUtcStorage($cutoffAt);
        if ($normalized === null) {
            throw new \InvalidArgumentException('cutoff_at required');
        }

        if ($this->configRepo) {
            $this->configRepo->setJson(self::BASELINE_CONFIG_KEY, [
                'cutoff_at' => $normalized,
            ]);
        }

        return ['cutoff_at' => $normalized];
    }

    public function clearBaseline(): void
    {
        if ($this->configRepo) {
            $this->configRepo->delete(self::BASELINE_CONFIG_KEY);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getBaseline(): array
    {
        if (!$this->configRepo) {
            return [];
        }

        return $this->configRepo->getJson(self::BASELINE_CONFIG_KEY) ?? [];
    }

    public function normalizeEventTimePublic(mixed $value): ?string
    {
        return $this->normalizeStoredUtcTime($value);
    }

    private function normalizeStoredUtcTime(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            if (preg_match('/(?:[zZ]|[+-]\d{2}:\d{2})$/', $raw) === 1) {
                $dt = new DateTimeImmutable($raw);
            } else {
                $dt = new DateTimeImmutable(str_replace(' ', 'T', $raw), new DateTimeZone('UTC'));
            }
            return $dt->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeLocalInputToUtcStorage(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        try {
            if (preg_match('/(?:[zZ]|[+-]\d{2}:\d{2})$/', $normalized) === 1) {
                $parsed = new DateTimeImmutable($normalized);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $normalized) === 1) {
                $canonical = str_replace(' ', 'T', $normalized);
                if (strlen($canonical) === 16) {
                    $canonical .= ':00';
                }
                $parsed = new DateTimeImmutable($canonical, AppTime::timezone());
            } else {
                $parsed = new DateTimeImmutable($normalized, AppTime::timezone());
            }
        } catch (\Throwable) {
            return null;
        }

        return $parsed
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function normalizeSource(mixed $value): ?string
    {
        $source = strtolower(trim((string)$value));
        if ($source === '') {
            return null;
        }

        $source = preg_replace('/[^a-z0-9_-]+/', '', $source) ?? '';
        if ($source === '') {
            return null;
        }

        return substr($source, 0, 100);
    }
}
