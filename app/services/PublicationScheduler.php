<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPublicationScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class PublicationScheduler
{
    private const ELIGIBLE_PUBLICATION_STATUSES = [
        'ready_to_schedule',
        'ready_to_publish',
        'ready_for_review',
        'draft',
        'scheduled',
        'failed',
    ];

    public function __construct(private PdoPublicationScheduleRepository $repo) {}

    public function listQueue(array $filters = []): array
    {
        $rows = $this->repo->listQueue($filters);
        return [
            'rows' => $rows,
            'counts' => $this->counts($rows),
        ];
    }

    public function schedulePublication(int $publicationId, string $scheduledAt, array $options = []): array
    {
        $publication = $this->requirePublication($publicationId);
        $this->assertSchedulable($publication);

        $existing = $this->repo->findScheduleByPublicationId($publicationId);
        if ($existing && !in_array($existing['status'], ['cancelled', 'failed'], true)) {
            throw new RuntimeException('Publication already has an active schedule row.');
        }

        $timezone = $this->timezone($options['timezone'] ?? null);
        $scheduledAtUtc = $this->toUtcSql($scheduledAt, $timezone);
        if ($existing) {
            $this->repo->reschedule(
                (int)$existing['publication_schedule_id'],
                $scheduledAtUtc,
                $timezone,
                (int)($options['priority'] ?? 100)
            );
            $this->repo->updatePublicationStatus($publicationId, 'scheduled');
            return [
                'schedule' => $this->repo->findScheduleById((int)$existing['publication_schedule_id']),
                'publication' => $this->repo->findPublication($publicationId),
            ];
        }

        $scheduleId = $this->repo->insertSchedule([
            'publishing_job_id' => (int)$publication['publishing_job_id'],
            'publishing_asset_id' => (int)$publication['publishing_asset_id'],
            'channel_id' => $publication['publishing_channel_id'] ?? null,
            'platform' => $publication['platform'] ?? 'unknown',
            'environment' => $publication['environment'] ?? 'production',
            'scheduled_at' => $scheduledAtUtc,
            'timezone' => $timezone,
            'status' => 'scheduled',
            'priority' => (int)($options['priority'] ?? 100),
            'max_attempts' => (int)($options['max_attempts'] ?? $this->channelDefault($publication, 'max_attempts', 3)),
        ]);
        $this->repo->updatePublicationStatus($publicationId, 'scheduled');

        return [
            'schedule' => $this->repo->findScheduleById($scheduleId),
            'publication' => $this->repo->findPublication($publicationId),
        ];
    }

    public function scheduleNextAvailable(int $publicationId, array $options = []): array
    {
        $publication = $this->requirePublication($publicationId);
        $this->assertSchedulable($publication);

        $existing = $this->repo->findScheduleByPublicationId($publicationId);
        if ($existing && !in_array($existing['status'], ['cancelled', 'failed'], true)) {
            throw new RuntimeException('Publication already has an active schedule row.');
        }

        $settings = $this->schedulerSettings($publication, $options);
        if ($existing) {
            $this->repo->reschedule((int)$existing['publication_schedule_id'], '', $settings['timezone'], (int)($options['priority'] ?? 100));
            $this->repo->updatePublicationStatus($publicationId, 'scheduled');
            return [
                'schedule' => $this->repo->findScheduleById((int)$existing['publication_schedule_id']),
                'publication' => $this->repo->findPublication($publicationId),
                'settings' => $settings,
            ];
        }

        $scheduleId = $this->repo->insertSchedule([
            'publishing_job_id' => (int)$publication['publishing_job_id'],
            'publishing_asset_id' => (int)$publication['publishing_asset_id'],
            'channel_id' => $publication['publishing_channel_id'] ?? null,
            'platform' => $publication['platform'] ?? 'unknown',
            'environment' => $publication['environment'] ?? 'production',
            'scheduled_at' => null,
            'timezone' => $settings['timezone'],
            'status' => 'waiting',
            'priority' => (int)($options['priority'] ?? 100),
            'max_attempts' => (int)($options['max_attempts'] ?? $this->channelDefault($publication, 'max_attempts', 3)),
        ]);
        $this->repo->updatePublicationStatus($publicationId, 'scheduled');

        return [
            'schedule' => $this->repo->findScheduleById($scheduleId),
            'publication' => $this->repo->findPublication($publicationId),
            'settings' => $settings,
        ];
    }

    public function enqueueMissingForJob(int $publishingJobId, array $options = []): array
    {
        if ($publishingJobId <= 0) {
            throw new RuntimeException('publishing_job_id required.');
        }

        $publications = $this->repo->listPublicationsForJob($publishingJobId);
        if (!$publications) {
            throw new RuntimeException("No publishing assets found for publishing job #{$publishingJobId}.");
        }

        $result = [
            'publishing_job_id' => $publishingJobId,
            'total_assets' => count($publications),
            'enqueued' => 0,
            'already_waiting' => 0,
            'skipped' => 0,
            'enqueued_asset_ids' => [],
            'schedule_ids' => [],
            'already_waiting_asset_ids' => [],
            'skipped_assets' => [],
        ];

        foreach ($publications as $publication) {
            $publicationId = (int)($publication['publishing_asset_id'] ?? 0);
            if ($publicationId <= 0) {
                $result['skipped'] += 1;
                $result['skipped_assets'][] = [
                    'publishing_asset_id' => $publicationId,
                    'reason' => 'missing_publishing_asset_id',
                ];
                continue;
            }

            $existing = $this->repo->findScheduleByPublicationId($publicationId);
            $existingStatus = (string)($existing['status'] ?? '');
            if ($existing && in_array($existingStatus, ['waiting', 'scheduled', 'processing'], true)) {
                $result['already_waiting'] += 1;
                $result['already_waiting_asset_ids'][] = $publicationId;
                continue;
            }

            try {
                $scheduled = $this->scheduleNextAvailable($publicationId, $options);
                $schedule = $scheduled['schedule'] ?? [];
                $result['enqueued'] += 1;
                $result['enqueued_asset_ids'][] = $publicationId;
                if (!empty($schedule['publication_schedule_id'])) {
                    $result['schedule_ids'][] = (int)$schedule['publication_schedule_id'];
                }
            } catch (RuntimeException $e) {
                $result['skipped'] += 1;
                $result['skipped_assets'][] = [
                    'publishing_asset_id' => $publicationId,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    public function reschedule(int $scheduleId, string $scheduledAt, array $options = []): array
    {
        $schedule = $this->requireSchedule($scheduleId);
        if (in_array($schedule['status'], ['completed'], true)) {
            throw new RuntimeException('Completed schedule rows cannot be rescheduled.');
        }

        $publication = $this->requirePublication((int)$schedule['publishing_asset_id']);
        if (($publication['status'] ?? '') === 'published') {
            throw new RuntimeException('Published publications cannot be rescheduled.');
        }

        $timezone = $this->timezone($options['timezone'] ?? ($schedule['timezone'] ?? null));
        $priority = (int)($options['priority'] ?? ($schedule['priority'] ?? 100));
        $this->repo->reschedule($scheduleId, $this->toUtcSql($scheduledAt, $timezone), $timezone, $priority);
        $this->repo->updatePublicationStatus((int)$schedule['publishing_asset_id'], 'scheduled');

        return [
            'schedule' => $this->repo->findScheduleById($scheduleId),
            'publication' => $this->repo->findPublication((int)$schedule['publishing_asset_id']),
        ];
    }

    public function cancel(int $scheduleId, string $reason = 'Cancelled by admin.'): array
    {
        $schedule = $this->requireSchedule($scheduleId);
        if (($schedule['status'] ?? '') === 'completed') {
            throw new RuntimeException('Completed schedule rows cannot be cancelled.');
        }

        $this->repo->cancelSchedule($scheduleId, $reason);
        $this->repo->updatePublicationStatus((int)$schedule['publishing_asset_id'], 'ready_to_schedule');

        return [
            'schedule' => $this->repo->findScheduleById($scheduleId),
            'publication' => $this->repo->findPublication((int)$schedule['publishing_asset_id']),
        ];
    }

    public function deleteUnscheduled(array $publicationIds): array
    {
        $publicationIds = array_values(array_unique(array_filter(array_map('intval', $publicationIds))));
        if (!$publicationIds) {
            throw new RuntimeException('Select at least one unscheduled publishing asset.');
        }
        return $this->repo->deleteUnscheduledPublicationAssets($publicationIds);
    }

    public function publishNow(int $publicationId = 0, int $scheduleId = 0): array
    {
        if ($scheduleId > 0) {
            $schedule = $this->requireSchedule($scheduleId);
            return $this->reschedule($scheduleId, gmdate('Y-m-d H:i:s'), [
                'timezone' => 'UTC',
                'priority' => min(1, (int)($schedule['priority'] ?? 100)),
            ]);
        }

        if ($publicationId <= 0) {
            throw new RuntimeException('publishing_job_id or schedule_id required.');
        }

        $existing = $this->repo->findScheduleByPublicationId($publicationId);
        if ($existing) {
            return $this->reschedule((int)$existing['publication_schedule_id'], gmdate('Y-m-d H:i:s'), [
                'timezone' => 'UTC',
                'priority' => min(1, (int)($existing['priority'] ?? 100)),
            ]);
        }

        return $this->schedulePublication($publicationId, gmdate('Y-m-d H:i:s'), [
            'timezone' => 'UTC',
            'priority' => 1,
        ]);
    }

    public function executeNow(PublicationExecutor $executor, int $publicationId = 0, int $scheduleId = 0, ?string $workerId = null): array
    {
        $workerId = $workerId ?: ('admin-now-' . gethostname() . '-' . getmypid());

        if ($scheduleId > 0) {
            $schedule = $this->requireSchedule($scheduleId);
            $publicationId = (int)$schedule['publishing_asset_id'];
        } elseif ($publicationId > 0) {
            $publication = $this->requirePublication($publicationId);
            $this->assertSchedulable($publication);
            $existing = $this->repo->findScheduleByPublicationId($publicationId);
            if (!$existing) {
                $created = $this->schedulePublication($publicationId, gmdate('Y-m-d H:i:s'), [
                    'timezone' => 'UTC',
                    'priority' => 1,
                ]);
                $existing = $created['schedule'] ?? null;
            }
            $scheduleId = (int)($existing['publication_schedule_id'] ?? 0);
        }

        if ($publicationId <= 0 || $scheduleId <= 0) {
            throw new RuntimeException('publishing_job_id or schedule_id required.');
        }

        $claimed = $this->repo->claimScheduleById($scheduleId, $workerId);
        if (!$claimed) {
            $current = $this->repo->findScheduleById($scheduleId);
            $status = $current ? (string)($current['status'] ?? 'unknown') : 'missing';
            throw new RuntimeException("Selected schedule row is not runnable (status: {$status}).");
        }

        try {
            $result = $executor->execute($publicationId);
        } catch (\Throwable $e) {
            $failure = [
                'retryable' => false,
                'next_retry_at' => null,
                'error_code' => 'publisher_exception',
                'error_message' => $e->getMessage(),
                'response_summary' => null,
            ];
            $this->fail($scheduleId, $failure);
            return [
                'worker_id' => $workerId,
                'publication_schedule_id' => $scheduleId,
                'publishing_job_id' => $publicationId,
                'publishing_asset_id' => $publicationId,
                'status' => 'failed',
                'result' => $failure,
            ];
        }

        if (!empty($result['success'])) {
            $this->complete($scheduleId, $result);
            return [
                'worker_id' => $workerId,
                'publication_schedule_id' => $scheduleId,
                'publishing_job_id' => $publicationId,
                'publishing_asset_id' => $publicationId,
                'status' => 'completed',
                'result' => $result,
            ];
        }

        $attemptCount = (int)($claimed['attempt_count'] ?? 1);
        $maxAttempts = max(1, (int)($claimed['max_attempts'] ?? 3));
        $retryable = !empty($result['retryable']) && $attemptCount < $maxAttempts;
        $failure = [
            'retryable' => $retryable,
            'next_retry_at' => $retryable ? $this->nextRetryAt($attemptCount) : null,
            'error_code' => $result['error_code'] ?? 'publisher_error',
            'error_message' => $result['error_message'] ?? 'Publisher execution failed.',
            'response_summary' => $result['response_summary'] ?? null,
        ];
        $this->fail($scheduleId, $failure);

        return [
            'worker_id' => $workerId,
            'publication_schedule_id' => $scheduleId,
            'publishing_job_id' => $publicationId,
            'publishing_asset_id' => $publicationId,
            'status' => $retryable ? 'retry_scheduled' : 'failed',
            'result' => $failure,
        ];
    }

    public function claimDueTasks(int $limit, string $workerId): array
    {
        $limit = max(1, $limit);
        $claimed = $this->repo->claimDueTasks($limit, $workerId);
        if (count($claimed) >= $limit) {
            return $claimed;
        }

        foreach ($this->repo->listWaitingTracks() as $track) {
            if (count($claimed) >= $limit) {
                break;
            }
            if (!$this->trackIsDue($track)) {
                continue;
            }
            $row = $this->repo->claimNextWaitingForTrack(
                $track['channel_id'] ?? null,
                (string)($track['environment'] ?? 'production'),
                $workerId
            );
            if ($row) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function runDue(PublicationExecutor $executor, int $limit = 5, ?string $workerId = null): array
    {
        $workerId = $workerId ?: ('admin-' . gethostname() . '-' . getmypid());
        $claimed = $this->claimDueTasks($limit, $workerId);
        $results = [];

        foreach ($claimed as $schedule) {
            $scheduleId = (int)$schedule['publication_schedule_id'];
            $publicationId = (int)$schedule['publishing_asset_id'];
            $result = $executor->execute($publicationId);

            if (!empty($result['success'])) {
                $this->complete($scheduleId, $result);
                $results[] = [
                    'publication_schedule_id' => $scheduleId,
                    'publishing_job_id' => $publicationId,
                    'publishing_asset_id' => $publicationId,
                    'status' => 'completed',
                    'result' => $result,
                ];
                continue;
            }

            $attemptCount = (int)($schedule['attempt_count'] ?? 1);
            $maxAttempts = max(1, (int)($schedule['max_attempts'] ?? 3));
            $retryable = !empty($result['retryable']) && $attemptCount < $maxAttempts;
            $failure = [
                'retryable' => $retryable,
                'next_retry_at' => $retryable ? $this->nextRetryAt($attemptCount) : null,
                'error_code' => $result['error_code'] ?? 'publisher_error',
                'error_message' => $result['error_message'] ?? 'Publisher execution failed.',
                'response_summary' => $result['response_summary'] ?? null,
            ];
            $this->fail($scheduleId, $failure);
            $results[] = [
                'publication_schedule_id' => $scheduleId,
                'publishing_job_id' => $publicationId,
                'publishing_asset_id' => $publicationId,
                'status' => $retryable ? 'retry_scheduled' : 'failed',
                'result' => $failure,
            ];
        }

        return [
            'worker_id' => $workerId,
            'claimed_count' => count($claimed),
            'results' => $results,
        ];
    }

    public function complete(int $scheduleId, array $result): array
    {
        $schedule = $this->requireSchedule($scheduleId);
        $publication = $this->requirePublication((int)$schedule['publishing_asset_id']);
        $this->repo->completeSchedule($scheduleId, $result);
        $this->repo->updatePublicationStatus(
            (int)$schedule['publishing_asset_id'],
            ($publication['environment'] ?? 'production') === 'production' ? 'published' : 'test_published'
        );
        return ['schedule' => $this->repo->findScheduleById($scheduleId)];
    }

    public function fail(int $scheduleId, array $result): array
    {
        $schedule = $this->requireSchedule($scheduleId);
        $this->repo->failSchedule($scheduleId, $result);
        if (empty($result['retryable'])) {
            $this->repo->updatePublicationStatus((int)$schedule['publishing_asset_id'], 'failed');
        }
        return ['schedule' => $this->repo->findScheduleById($scheduleId)];
    }

    private function requirePublication(int $publicationId): array
    {
        $publication = $this->repo->findPublication($publicationId);
        if (!$publication) {
            throw new RuntimeException("Publishing job not found: {$publicationId}");
        }
        return $publication;
    }

    private function requireSchedule(int $scheduleId): array
    {
        $schedule = $this->repo->findScheduleById($scheduleId);
        if (!$schedule) {
            throw new RuntimeException("Schedule row not found: {$scheduleId}");
        }
        return $schedule;
    }

    private function assertSchedulable(array $publication): void
    {
        if (($publication['status'] ?? '') === 'published' || !empty($publication['published_at'])) {
            throw new RuntimeException('Published publications cannot be scheduled again.');
        }

        if (!in_array((string)($publication['status'] ?? ''), self::ELIGIBLE_PUBLICATION_STATUSES, true)) {
            throw new RuntimeException('Publication is not ready to schedule.');
        }
    }

    private function timezone(?string $timezone): string
    {
        $timezone = trim((string)($timezone ?: 'America/Los_Angeles'));
        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return 'America/Los_Angeles';
        }
    }

    private function toUtcSql(string $value, string $timezone): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('scheduled_at required.');
        }
        $dt = new DateTimeImmutable($value, new DateTimeZone($timezone));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function channelDefault(array $publication, string $key, mixed $fallback): mixed
    {
        $metadata = $publication['channel_metadata_json'] ?? [];
        if (!is_array($metadata)) return $fallback;
        return $metadata['scheduler'][$key] ?? $metadata['scheduler']['default_' . $key] ?? $fallback;
    }

    private function schedulerSettings(array $publication, array $options = []): array
    {
        $platform = (string)($publication['platform'] ?? 'unknown');
        $environment = (string)($publication['environment'] ?? 'production');
        $metadata = $publication['channel_metadata_json'] ?? [];
        $scheduler = is_array($metadata) && isset($metadata['scheduler']) && is_array($metadata['scheduler'])
            ? $metadata['scheduler']
            : [];
        $environmentSettings = [];
        if (isset($scheduler['environments'][$environment]) && is_array($scheduler['environments'][$environment])) {
            $environmentSettings = $scheduler['environments'][$environment];
        } elseif (isset($scheduler[$environment]) && is_array($scheduler[$environment])) {
            $environmentSettings = $scheduler[$environment];
        }

        $fallbackMax = $platform === 'youtube' ? 1 : 2;
        $settings = array_merge([
            'timezone' => 'America/Los_Angeles',
            'max_posts_per_day' => $fallbackMax,
            'minimum_spacing_minutes' => $platform === 'youtube' ? 1440 : 240,
            'publishing_window_start' => '08:00',
            'publishing_window_end' => '20:00',
            'lead_minutes' => 15,
            'slot_round_minutes' => 15,
        ], $scheduler, $environmentSettings, $options);

        $settings['timezone'] = $this->timezone($settings['timezone'] ?? null);
        $settings['max_posts_per_day'] = max(1, (int)($settings['max_posts_per_day'] ?? $fallbackMax));
        $settings['minimum_spacing_minutes'] = max(0, (int)($settings['minimum_spacing_minutes'] ?? 0));
        $settings['lead_minutes'] = max(0, (int)($settings['lead_minutes'] ?? 15));
        $settings['slot_round_minutes'] = max(1, (int)($settings['slot_round_minutes'] ?? 15));
        $settings['publishing_window_start'] = $this->timeOfDay((string)($settings['publishing_window_start'] ?? '08:00'), '08:00');
        $settings['publishing_window_end'] = $this->timeOfDay((string)($settings['publishing_window_end'] ?? '20:00'), '20:00');
        return $settings;
    }

    private function schedulerSettingsFromTrack(array $track): array
    {
        $platform = (string)($track['platform'] ?? 'unknown');
        $environment = (string)($track['environment'] ?? 'production');
        $metadata = $track['channel_metadata_json'] ?? [];
        $scheduler = is_array($metadata) && isset($metadata['scheduler']) && is_array($metadata['scheduler'])
            ? $metadata['scheduler']
            : [];
        $environmentSettings = [];
        if (isset($scheduler['environments'][$environment]) && is_array($scheduler['environments'][$environment])) {
            $environmentSettings = $scheduler['environments'][$environment];
        } elseif (isset($scheduler[$environment]) && is_array($scheduler[$environment])) {
            $environmentSettings = $scheduler[$environment];
        }

        $fallbackMax = $platform === 'youtube' ? 1 : 2;
        $settings = array_merge([
            'timezone' => 'America/Los_Angeles',
            'max_posts_per_day' => $fallbackMax,
            'minimum_spacing_minutes' => $platform === 'youtube' ? 1440 : 240,
            'publishing_window_start' => '08:00',
            'publishing_window_end' => '20:00',
        ], $scheduler, $environmentSettings);

        $settings['timezone'] = $this->timezone($settings['timezone'] ?? null);
        $settings['max_posts_per_day'] = max(1, (int)($settings['max_posts_per_day'] ?? $fallbackMax));
        $settings['minimum_spacing_minutes'] = max(0, (int)($settings['minimum_spacing_minutes'] ?? 0));
        $settings['publishing_window_start'] = $this->timeOfDay((string)($settings['publishing_window_start'] ?? '08:00'), '08:00');
        $settings['publishing_window_end'] = $this->timeOfDay((string)($settings['publishing_window_end'] ?? '20:00'), '20:00');
        return $settings;
    }

    private function trackIsDue(array $track): bool
    {
        $settings = $this->schedulerSettingsFromTrack($track);
        $timezone = new DateTimeZone($settings['timezone']);
        $now = new DateTimeImmutable('now', $timezone);
        $date = $now->format('Y-m-d');
        $windowStart = new DateTimeImmutable($date . ' ' . $settings['publishing_window_start'], $timezone);
        $windowEnd = new DateTimeImmutable($date . ' ' . $settings['publishing_window_end'], $timezone);
        if ($windowEnd <= $windowStart) {
            $windowEnd = $windowEnd->modify('+1 day');
        }
        if ($now < $windowStart || $now > $windowEnd) {
            return false;
        }

        $dayStartUtc = (new DateTimeImmutable($date . ' 00:00:00', $timezone))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $dayEndUtc = (new DateTimeImmutable($date . ' 00:00:00', $timezone))
            ->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $channelId = $track['channel_id'] === null ? null : (int)$track['channel_id'];
        $environment = (string)($track['environment'] ?? 'production');
        if ($this->repo->countCompletedForTrackBetween($channelId, $environment, $dayStartUtc, $dayEndUtc) >= (int)$settings['max_posts_per_day']) {
            return false;
        }

        $last = $this->repo->lastCompletedForTrack($channelId, $environment);
        if ($last && !empty($last['completed_at'])) {
            $lastAt = new DateTimeImmutable((string)$last['completed_at'], new DateTimeZone('UTC'));
            $nextAllowed = $lastAt->modify('+' . (int)$settings['minimum_spacing_minutes'] . ' minutes');
            if (new DateTimeImmutable('now', new DateTimeZone('UTC')) < $nextAllowed) {
                return false;
            }
        }

        return true;
    }

    private function compactTrackForPublication(int $publicationId): void
    {
        $publication = $this->repo->findPublication($publicationId);
        if (!$publication) {
            return;
        }

        $channelId = isset($publication['publishing_channel_id']) && $publication['publishing_channel_id'] !== ''
            ? (int)$publication['publishing_channel_id']
            : null;
        $environment = (string)($publication['environment'] ?? 'production');
        $futureSchedules = $this->repo->listFutureScheduledForTrack($channelId, $environment, gmdate('Y-m-d H:i:s'));
        if (!$futureSchedules) {
            return;
        }

        $remainingScheduleIds = array_map(
            static fn(array $schedule): int => (int)$schedule['publication_schedule_id'],
            $futureSchedules
        );

        foreach ($futureSchedules as $schedule) {
            $futurePublication = $this->repo->findPublication((int)$schedule['publishing_asset_id']);
            if (!$futurePublication || in_array((string)($futurePublication['status'] ?? ''), ['published', 'test_published'], true)) {
                array_shift($remainingScheduleIds);
                continue;
            }

            $settings = $this->schedulerSettings($futurePublication, [
                'exclude_schedule_ids' => $remainingScheduleIds,
            ]);
            $scheduledAtUtc = $this->nextAvailableSlot($futurePublication, $settings, [
                'exclude_schedule_ids' => $remainingScheduleIds,
            ]);
            $this->repo->reschedule(
                (int)$schedule['publication_schedule_id'],
                $scheduledAtUtc,
                $settings['timezone'],
                (int)($schedule['priority'] ?? 100)
            );
            array_shift($remainingScheduleIds);
        }
    }

    private function nextAvailableSlot(array $publication, array $settings, array $options = []): string
    {
        $timezone = new DateTimeZone($settings['timezone']);
        $candidate = (new DateTimeImmutable('now', $timezone))
            ->modify('+' . (int)$settings['lead_minutes'] . ' minutes');
        $candidate = $this->roundUpLocal($candidate, (int)$settings['slot_round_minutes']);
        $spacingSeconds = (int)$settings['minimum_spacing_minutes'] * 60;
        $maxPerDay = (int)$settings['max_posts_per_day'];
        $channelId = isset($publication['publishing_channel_id']) && $publication['publishing_channel_id'] !== ''
            ? (int)$publication['publishing_channel_id']
            : null;
        $environment = (string)($publication['environment'] ?? 'production');
        $excludeScheduleIds = array_values(array_unique(array_filter(array_map('intval', $options['exclude_schedule_ids'] ?? $settings['exclude_schedule_ids'] ?? []))));

        for ($dayOffset = 0; $dayOffset < 365; $dayOffset += 1) {
            $day = $candidate->modify("+{$dayOffset} days");
            $date = $day->format('Y-m-d');
            $windowStart = new DateTimeImmutable($date . ' ' . $settings['publishing_window_start'], $timezone);
            $windowEnd = new DateTimeImmutable($date . ' ' . $settings['publishing_window_end'], $timezone);
            if ($windowEnd <= $windowStart) {
                $windowEnd = $windowEnd->modify('+1 day');
            }

            $slot = $dayOffset === 0 && $candidate > $windowStart ? $candidate : $windowStart;
            if ($slot > $windowEnd) {
                continue;
            }

            $existing = $this->repo->listScheduleSlotsForTrack(
                $channelId,
                $environment,
                $windowStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $windowEnd->modify('+1 second')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $excludeScheduleIds
            );
            if (count($existing) >= $maxPerDay) {
                continue;
            }

            $existingLocal = array_map(
                fn(array $row) => (new DateTimeImmutable((string)$row['scheduled_at'], new DateTimeZone('UTC')))->setTimezone($timezone),
                $existing
            );
            usort($existingLocal, fn(DateTimeImmutable $a, DateTimeImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());

            foreach ($existingLocal as $existingSlot) {
                if (abs($slot->getTimestamp() - $existingSlot->getTimestamp()) < $spacingSeconds) {
                    $slot = $existingSlot->modify('+' . (int)$settings['minimum_spacing_minutes'] . ' minutes');
                }
            }

            if ($slot <= $windowEnd) {
                return $slot->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        throw new RuntimeException('No available scheduler slot found in the next 365 days.');
    }

    private function roundUpLocal(DateTimeImmutable $value, int $minutes): DateTimeImmutable
    {
        $seconds = $minutes * 60;
        $timestamp = $value->getTimestamp();
        $rounded = (int)(ceil($timestamp / $seconds) * $seconds);
        return $value->setTimestamp($rounded);
    }

    private function timeOfDay(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : $fallback;
    }

    private function nextRetryAt(int $attemptCount): string
    {
        $minutes = $attemptCount <= 1 ? 15 : 60;
        return gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
    }

    private function counts(array $rows): array
    {
        $counts = [
            'unscheduled' => 0,
            'waiting' => 0,
            'scheduled_today' => 0,
            'scheduled_later' => 0,
            'processing' => 0,
            'completed' => 0,
            'retry_scheduled' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        $today = gmdate('Y-m-d');
        foreach ($rows as $row) {
            $status = (string)($row['schedule_status'] ?? 'unscheduled');
            if ($status === 'scheduled') {
                $day = substr((string)($row['scheduled_at'] ?? ''), 0, 10);
                $counts[$day === $today ? 'scheduled_today' : 'scheduled_later']++;
                continue;
            }
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        return $counts;
    }
}
