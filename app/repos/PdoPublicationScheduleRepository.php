<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPublicationScheduleRepository
{
    public function __construct(private PDO $pdo) {}

    public function listQueue(array $filters = []): array
    {
        $where = [];
        $params = [];

        $environment = trim((string)($filters['environment'] ?? ''));
        if ($environment !== '' && $environment !== 'all') {
            $where[] = 'COALESCE(ps.environment, pa.environment) = :environment';
            $params[':environment'] = $environment;
        }

        $platform = trim((string)($filters['platform'] ?? ''));
        if ($platform !== '' && $platform !== 'all') {
            $where[] = 'pa.platform = :platform';
            $params[':platform'] = $platform;
        }

        $status = trim((string)($filters['schedule_status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            if ($status === 'unscheduled') {
                $where[] = 'ps.publication_schedule_id IS NULL';
            } else {
                $where[] = 'ps.status = :schedule_status';
                $params[':schedule_status'] = $status;
            }
        }

        $publicationStatus = trim((string)($filters['publication_status'] ?? ''));
        if ($publicationStatus !== '' && $publicationStatus !== 'all') {
            $where[] = 'pa.status = :publication_status';
            $params[':publication_status'] = $publicationStatus;
        }

        $creatorJob = trim((string)($filters['creator_job'] ?? ''));
        if ($creatorJob !== '') {
            $where[] = '(CAST(pj.asset_creator_job_id AS CHAR) = :creator_job_exact OR acj.title LIKE :creator_job_like)';
            $params[':creator_job_exact'] = $creatorJob;
            $params[':creator_job_like'] = '%' . $creatorJob . '%';
        }

        $sql = <<<SQL
            SELECT
              pa.publishing_asset_id,
              pa.publishing_asset_id AS publish_output_id,
              pa.publishing_job_id,
              pa.publishing_channel_id,
              pa.asset_library_id,
              pj.asset_creator_job_id,
              pa.asset_creator_output_id,
              pa.platform,
              pa.environment,
              pa.source_type,
              pa.source_id,
              pa.playlist_instance_id,
              pa.cta_group_id,
              pa.landing_page_id,
              pa.asset_type,
              pa.title,
              pa.description,
              pa.alt_text,
              pa.image_url,
              pa.media_url,
              pa.media_path,
              pa.destination_url,
              pa.canonical_destination_url,
              pa.tracked_destination_url,
              pa.status AS publication_status,
              pub.external_post_id AS external_id,
              pub.external_post_url AS external_url,
              pa.published_at,
              pa.locked_at,
              pa.last_error_code,
              pa.last_error_message,
              pa.metadata_json AS publication_metadata_json,
              pc.channel_key,
              pc.label AS channel_label,
              pc.metadata_json AS channel_metadata_json,
              acj.title AS creator_job_title,
              p.title AS playlist_title,
              pi.instance_name,
              ps.publication_schedule_id,
              ps.scheduled_at,
              ps.timezone,
              ps.status AS schedule_status,
              ps.priority,
              ps.attempt_count,
              ps.max_attempts,
              ps.claimed_at,
              ps.claimed_by,
              ps.last_attempt_at,
              ps.next_retry_at,
              ps.completed_at,
              ps.last_error_code AS schedule_error_code,
              ps.last_error_message AS schedule_error
            FROM publishing_assets pa
            JOIN publishing_jobs pj
              ON pj.publishing_job_id = pa.publishing_job_id
            LEFT JOIN publishing_channels pc
              ON pc.publishing_channel_id = pa.publishing_channel_id
            LEFT JOIN publications pub
              ON pub.publishing_asset_id = pa.publishing_asset_id
             AND pub.status IN ('published', 'test_published')
            LEFT JOIN asset_creator_jobs acj
              ON acj.asset_creator_job_id = pj.asset_creator_job_id
            LEFT JOIN playlists p
              ON p.playlist_id = pa.source_id
             AND pa.source_type = 'playlist'
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = pa.playlist_instance_id
            LEFT JOIN publication_schedule ps
              ON ps.publishing_asset_id = pa.publishing_asset_id
             AND ps.status <> 'cancelled'
            SQL;

        if ($where) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $sql .= "\nORDER BY CASE WHEN ps.status = 'waiting' THEN 0 WHEN ps.scheduled_at IS NOT NULL THEN 1 ELSE 2 END ASC, COALESCE(ps.scheduled_at, ps.created_at, pa.updated_at) ASC, pa.updated_at DESC, pa.publishing_asset_id DESC\nLIMIT 300";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'normalizeQueueRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findPublication(int $publicationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.*, pc.channel_key, pc.label AS channel_label, pc.metadata_json AS channel_metadata_json
               FROM publishing_assets pa
               LEFT JOIN publishing_channels pc
                 ON pc.publishing_channel_id = pa.publishing_channel_id
              WHERE pa.publishing_asset_id = :publishing_asset_id
              LIMIT 1'
        );
        $stmt->execute([':publishing_asset_id' => $publicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizePublication($row) : null;
    }

    public function listPublicationsForJob(int $publishingJobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.*, pc.channel_key, pc.label AS channel_label, pc.metadata_json AS channel_metadata_json
               FROM publishing_assets pa
               LEFT JOIN publishing_channels pc
                 ON pc.publishing_channel_id = pa.publishing_channel_id
              WHERE pa.publishing_job_id = :publishing_job_id
              ORDER BY pa.publishing_asset_id ASC'
        );
        $stmt->execute([':publishing_job_id' => $publishingJobId]);
        return array_map([$this, 'normalizePublication'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findScheduleById(int $scheduleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM publication_schedule WHERE publication_schedule_id = :id LIMIT 1');
        $stmt->execute([':id' => $scheduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeSchedule($row) : null;
    }

    public function findScheduleByPublicationId(int $publicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM publication_schedule WHERE publishing_asset_id = :publishing_asset_id AND status NOT IN ("completed", "cancelled") ORDER BY publication_schedule_id DESC LIMIT 1');
        $stmt->execute([':publishing_asset_id' => $publicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeSchedule($row) : null;
    }

    public function claimScheduleById(int $scheduleId, string $workerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE publication_schedule
                SET status = 'processing',
                    claimed_at = UTC_TIMESTAMP(),
                    claimed_by = :worker_id,
                    last_attempt_at = UTC_TIMESTAMP(),
                    attempt_count = attempt_count + 1,
                    next_retry_at = NULL,
                    last_error_code = NULL,
                    last_error_message = NULL
              WHERE publication_schedule_id = :schedule_id
                AND status NOT IN ('completed', 'cancelled')
                AND (
                    status <> 'processing'
                    OR claimed_at IS NULL
                    OR claimed_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)
                    OR claimed_by LIKE 'admin-now-%'
                )"
        );
        $stmt->execute([
            ':worker_id' => $workerId,
            ':schedule_id' => $scheduleId,
        ]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->findScheduleById($scheduleId);
    }

    public function listScheduleSlotsForTrack(?int $channelId, string $environment, string $fromUtc, string $toUtc, array $excludeScheduleIds = []): array
    {
        $excludeScheduleIds = array_values(array_unique(array_filter(array_map('intval', $excludeScheduleIds))));
        $params = [
            ':environment' => $environment,
            ':from_utc' => $fromUtc,
            ':to_utc' => $toUtc,
        ];
        $channelSql = $channelId === null ? 'channel_id IS NULL' : 'channel_id = :channel_id';
        if ($channelId !== null) {
            $params[':channel_id'] = $channelId;
        }
        $excludeSql = '';
        if ($excludeScheduleIds) {
            $excludePlaceholders = [];
            foreach ($excludeScheduleIds as $index => $scheduleId) {
                $key = ':exclude_' . $index;
                $excludePlaceholders[] = $key;
                $params[$key] = $scheduleId;
            }
            $excludeSql = ' AND publication_schedule_id NOT IN (' . implode(',', $excludePlaceholders) . ')';
        }

        $stmt = $this->pdo->prepare(
            "SELECT publication_schedule_id, scheduled_at, status
               FROM publication_schedule
              WHERE environment = :environment
                AND scheduled_at >= :from_utc
                AND scheduled_at < :to_utc
                AND status NOT IN ('cancelled', 'failed')
                AND {$channelSql}
                {$excludeSql}
           ORDER BY scheduled_at ASC, publication_schedule_id ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listFutureScheduledForTrack(?int $channelId, string $environment, string $afterUtc): array
    {
        $params = [
            ':environment' => $environment,
            ':after_utc' => $afterUtc,
        ];
        $channelSql = $channelId === null ? 'channel_id IS NULL' : 'channel_id = :channel_id';
        if ($channelId !== null) {
            $params[':channel_id'] = $channelId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT *
               FROM publication_schedule
              WHERE environment = :environment
                AND scheduled_at > :after_utc
                AND status = 'scheduled'
                AND {$channelSql}
           ORDER BY scheduled_at ASC, priority ASC, publication_schedule_id ASC"
        );
        $stmt->execute($params);
        return array_map([$this, 'normalizeSchedule'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function insertSchedule(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publication_schedule
                (publishing_job_id, publishing_asset_id, channel_id, platform, environment, scheduled_at, timezone, status, priority, max_attempts)
             VALUES
                (:publishing_job_id, :publishing_asset_id, :channel_id, :platform, :environment, :scheduled_at, :timezone, :status, :priority, :max_attempts)'
        );
        $stmt->execute([
            ':publishing_job_id' => (int)$data['publishing_job_id'],
            ':publishing_asset_id' => (int)$data['publishing_asset_id'],
            ':channel_id' => $this->nullableInt($data['channel_id'] ?? null),
            ':platform' => (string)$data['platform'],
            ':environment' => (string)$data['environment'],
            ':scheduled_at' => $this->nullableString($data['scheduled_at'] ?? null),
            ':timezone' => (string)$data['timezone'],
            ':status' => (string)($data['status'] ?? 'waiting'),
            ':priority' => (int)($data['priority'] ?? 100),
            ':max_attempts' => (int)($data['max_attempts'] ?? 3),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updatePublicationStatus(int $publicationId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE publishing_assets
                SET status = :status,
                    last_error_code = NULL,
                    last_error_message = NULL
              WHERE publishing_asset_id = :publishing_asset_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':publishing_asset_id' => $publicationId,
        ]);
    }

    public function updatePublicationPublished(int $publicationId, array $data): void
    {
        $asset = $this->findPublication($publicationId);
        $stmt = $this->pdo->prepare(
            'UPDATE publishing_assets
                SET status = :status,
                    published_at = CASE WHEN :record_publication_result = 1 THEN COALESCE(:published_at, UTC_TIMESTAMP()) ELSE published_at END,
                    locked_at = CASE WHEN :lock_publication = 1 THEN COALESCE(locked_at, UTC_TIMESTAMP()) ELSE locked_at END,
                    metadata_json = :metadata_json,
                    last_error_code = NULL,
                    last_error_message = NULL
              WHERE publishing_asset_id = :publishing_asset_id'
        );
        $stmt->execute([
            ':status' => (string)($data['status'] ?? 'published'),
            ':published_at' => $this->nullableString($data['published_at'] ?? null),
            ':record_publication_result' => !empty($data['record_publication_result']) ? 1 : 0,
            ':lock_publication' => !empty($data['lock_publication']) ? 1 : 0,
            ':metadata_json' => $this->jsonValue($data['metadata_json'] ?? null),
            ':publishing_asset_id' => $publicationId,
        ]);

        if ($asset && ($this->nullableString($data['external_id'] ?? null) || $this->nullableString($data['external_url'] ?? null))) {
            $insert = $this->pdo->prepare(
                'INSERT INTO publications
                    (publishing_job_id, publishing_asset_id, publishing_channel_id, platform, environment, status,
                     external_post_id, external_post_url, response_payload_json, metadata_json, published_at)
                 VALUES
                    (:publishing_job_id, :publishing_asset_id, :publishing_channel_id, :platform, :environment, :status,
                     :external_post_id, :external_post_url, :response_payload_json, :metadata_json, :published_at)'
            );
            $insert->execute([
                ':publishing_job_id' => (int)$asset['publishing_job_id'],
                ':publishing_asset_id' => $publicationId,
                ':publishing_channel_id' => $asset['publishing_channel_id'] ?? null,
                ':platform' => $asset['platform'] ?? 'unknown',
                ':environment' => $asset['environment'] ?? 'production',
                ':status' => (string)($data['status'] ?? 'published'),
                ':external_post_id' => $this->nullableString($data['external_id'] ?? null),
                ':external_post_url' => $this->nullableString($data['external_url'] ?? null),
                ':response_payload_json' => $this->jsonValue($data['response_payload_json'] ?? null),
                ':metadata_json' => $this->jsonValue($data['metadata_json'] ?? null),
                ':published_at' => $this->nullableString($data['published_at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function updatePublicationError(int $publicationId, string $status, ?string $errorCode, ?string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE publishing_assets
                SET status = :status,
                    last_error_code = :last_error_code,
                    last_error_message = :last_error_message
              WHERE publishing_asset_id = :publishing_asset_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':last_error_code' => $errorCode,
            ':last_error_message' => $errorMessage,
            ':publishing_asset_id' => $publicationId,
        ]);
    }

    public function cancelSchedule(int $scheduleId, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE publication_schedule
                SET status = 'cancelled',
                    last_error_code = NULL,
                    last_error_message = :reason,
                    claimed_at = NULL,
                    claimed_by = NULL,
                    next_retry_at = NULL
              WHERE publication_schedule_id = :schedule_id
                AND status NOT IN ('completed', 'cancelled')"
        );
        $stmt->execute([
            ':reason' => $reason,
            ':schedule_id' => $scheduleId,
        ]);
    }

    public function deleteUnscheduledPublicationAssets(array $publicationIds): array
    {
        $publicationIds = array_values(array_unique(array_filter(array_map('intval', $publicationIds))));
        if (!$publicationIds) {
            return ['deleted_count' => 0, 'deleted_ids' => [], 'blocked' => []];
        }

        $deleted = [];
        $blocked = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($publicationIds as $publicationId) {
                $stmt = $this->pdo->prepare(
                    "SELECT
                        pa.publishing_asset_id,
                        pa.publishing_job_id,
                        pa.environment,
                        pa.status,
                        pa.published_at,
                        pa.locked_at,
                        SUM(CASE WHEN ps.status = 'processing' THEN 1 ELSE 0 END) AS processing_schedule_count,
                        SUM(CASE WHEN ps.status NOT IN ('cancelled', 'failed', 'completed') THEN 1 ELSE 0 END) AS active_schedule_count,
                        SUM(CASE WHEN pub.status IN ('published', 'test_published') OR pub.external_post_id IS NOT NULL OR pub.external_post_url IS NOT NULL THEN 1 ELSE 0 END) AS external_post_count,
                        SUM(CASE WHEN pub.environment = 'production' OR pub.status = 'published' THEN 1 ELSE 0 END) AS production_publication_count
                       FROM publishing_assets pa
                       LEFT JOIN publication_schedule ps
                         ON ps.publishing_asset_id = pa.publishing_asset_id
                       LEFT JOIN publications pub
                         ON pub.publishing_asset_id = pa.publishing_asset_id
                      WHERE pa.publishing_asset_id = :publishing_asset_id
                      GROUP BY pa.publishing_asset_id
                      LIMIT 1"
                );
                $stmt->execute([':publishing_asset_id' => $publicationId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $blocked[] = "Asset #{$publicationId} was not found.";
                    continue;
                }

                $isTestDisposable = (string)($row['environment'] ?? '') === 'test';
                $reasons = [];
                if ((int)($row['processing_schedule_count'] ?? 0) > 0) {
                    $reasons[] = 'is currently processing';
                }
                if (!$isTestDisposable && (int)($row['active_schedule_count'] ?? 0) > 0) {
                    $reasons[] = 'has an active schedule row';
                }
                if ($isTestDisposable && (int)($row['production_publication_count'] ?? 0) > 0) {
                    $reasons[] = 'has production publication history';
                }
                if (!$isTestDisposable && (int)($row['external_post_count'] ?? 0) > 0) {
                    $reasons[] = 'has an external publication';
                }
                if (!$isTestDisposable && $this->nullableString($row['published_at'] ?? null) !== null) {
                    $reasons[] = 'has published_at';
                }
                if ($this->nullableString($row['locked_at'] ?? null) !== null) {
                    $reasons[] = 'is locked';
                }
                if (!$isTestDisposable && in_array((string)($row['status'] ?? ''), ['published', 'posted', 'test_published'], true)) {
                    $reasons[] = 'is already published/test published';
                }

                if ($reasons) {
                    $blocked[] = "Asset #{$publicationId}: " . implode(', ', $reasons) . '.';
                    continue;
                }

                $scheduleIdsStmt = $this->pdo->prepare(
                    'SELECT publication_schedule_id FROM publication_schedule WHERE publishing_asset_id = :publishing_asset_id'
                );
                $scheduleIdsStmt->execute([':publishing_asset_id' => $publicationId]);
                $scheduleIds = array_map('intval', $scheduleIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($scheduleIds) {
                    $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
                    $deleteAttempts = $this->pdo->prepare("DELETE FROM publication_schedule_attempts WHERE publication_schedule_id IN ({$placeholders})");
                    $deleteAttempts->execute($scheduleIds);
                }

                $deleteSchedules = $this->pdo->prepare(
                    $isTestDisposable
                        ? 'DELETE FROM publication_schedule
                            WHERE publishing_asset_id = :publishing_asset_id'
                        : "DELETE FROM publication_schedule
                            WHERE publishing_asset_id = :publishing_asset_id
                              AND status IN ('cancelled', 'failed')"
                );
                $deleteSchedules->execute([':publishing_asset_id' => $publicationId]);

                $deleteAttemptsByAsset = $this->pdo->prepare(
                    'DELETE FROM publisher_attempts WHERE publishing_asset_id = :publishing_asset_id'
                );
                $deleteAttemptsByAsset->execute([':publishing_asset_id' => $publicationId]);

                $deletePublications = $this->pdo->prepare(
                    'DELETE FROM publications WHERE publishing_asset_id = :publishing_asset_id'
                );
                $deletePublications->execute([':publishing_asset_id' => $publicationId]);

                $jobId = (int)($row['publishing_job_id'] ?? 0);
                $deleteAsset = $this->pdo->prepare(
                    'DELETE FROM publishing_assets WHERE publishing_asset_id = :publishing_asset_id'
                );
                $deleteAsset->execute([':publishing_asset_id' => $publicationId]);

                if ($deleteAsset->rowCount() > 0) {
                    $deleted[] = $publicationId;
                    if ($jobId > 0) {
                        $deleteOrphanJob = $this->pdo->prepare(
                            'DELETE pj
                               FROM publishing_jobs pj
                               LEFT JOIN publishing_assets pa
                                 ON pa.publishing_job_id = pj.publishing_job_id
                              WHERE pj.publishing_job_id = :publishing_job_id
                                AND pa.publishing_asset_id IS NULL'
                        );
                        $deleteOrphanJob->execute([':publishing_job_id' => $jobId]);
                    }
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'deleted_count' => count($deleted),
            'deleted_ids' => $deleted,
            'blocked' => $blocked,
        ];
    }

    public function reschedule(int $scheduleId, string $scheduledAt, string $timezone, int $priority): void
    {
        $scheduledAtValue = $this->nullableString($scheduledAt);
        $status = $scheduledAtValue === null ? 'waiting' : 'scheduled';
        $stmt = $this->pdo->prepare(
            "UPDATE publication_schedule
                SET scheduled_at = :scheduled_at,
                    timezone = :timezone,
                    priority = :priority,
                    status = :status,
                    claimed_at = NULL,
                    claimed_by = NULL,
                    next_retry_at = NULL,
                    completed_at = NULL,
                    last_error_code = NULL,
                    last_error_message = NULL
              WHERE publication_schedule_id = :schedule_id
                AND status NOT IN ('completed')"
        );
        $stmt->execute([
            ':scheduled_at' => $scheduledAtValue,
            ':timezone' => $timezone,
            ':priority' => $priority,
            ':status' => $status,
            ':schedule_id' => $scheduleId,
        ]);
    }

    public function claimDueTasks(int $limit, string $workerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT publication_schedule_id
               FROM publication_schedule
              WHERE (
                    status = 'scheduled'
                AND scheduled_at <= UTC_TIMESTAMP()
              )
                 OR (
                    status = 'retry_scheduled'
                AND next_retry_at IS NOT NULL
                AND next_retry_at <= UTC_TIMESTAMP()
              )
           ORDER BY priority ASC, scheduled_at ASC, publication_schedule_id ASC
              LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $claimed = [];
        foreach ($ids as $id) {
            $claim = $this->pdo->prepare(
                "UPDATE publication_schedule
                    SET status = 'processing',
                        claimed_at = UTC_TIMESTAMP(),
                        claimed_by = :worker_id,
                        last_attempt_at = UTC_TIMESTAMP(),
                        attempt_count = attempt_count + 1
                  WHERE publication_schedule_id = :schedule_id
                    AND status IN ('scheduled', 'retry_scheduled')"
            );
            $claim->execute([
                ':worker_id' => $workerId,
                ':schedule_id' => $id,
            ]);
            if ($claim->rowCount() === 1) {
                $row = $this->findScheduleById($id);
                if ($row) $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function claimDueRetryTasks(int $limit, string $workerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT publication_schedule_id
               FROM publication_schedule
              WHERE status = 'retry_scheduled'
                AND next_retry_at IS NOT NULL
                AND next_retry_at <= UTC_TIMESTAMP()
           ORDER BY priority ASC, next_retry_at ASC, publication_schedule_id ASC
              LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $claimed = [];
        foreach ($ids as $id) {
            $claim = $this->pdo->prepare(
                "UPDATE publication_schedule
                    SET status = 'processing',
                        claimed_at = UTC_TIMESTAMP(),
                        claimed_by = :worker_id,
                        last_attempt_at = UTC_TIMESTAMP(),
                        attempt_count = attempt_count + 1
                  WHERE publication_schedule_id = :schedule_id
                    AND status = 'retry_scheduled'"
            );
            $claim->execute([
                ':worker_id' => $workerId,
                ':schedule_id' => $id,
            ]);
            if ($claim->rowCount() === 1) {
                $row = $this->findScheduleById($id);
                if ($row) $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function listWaitingTracks(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                ps.channel_id,
                ps.platform,
                ps.environment,
                pc.metadata_json AS channel_metadata_json,
                COUNT(*) AS waiting_count
               FROM publication_schedule ps
               LEFT JOIN publishing_channels pc
                 ON pc.publishing_channel_id = ps.channel_id
              WHERE ps.status = 'waiting'
           GROUP BY ps.channel_id, ps.platform, ps.environment, pc.metadata_json
           ORDER BY MIN(ps.priority) ASC, MIN(ps.created_at) ASC"
        );
        return array_map([$this, 'normalizeTrack'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function countCompletedForTrackBetween(?int $channelId, string $environment, string $fromUtc, string $toUtc): int
    {
        $params = [
            ':environment' => $environment,
            ':from_utc' => $fromUtc,
            ':to_utc' => $toUtc,
        ];
        $channelSql = $channelId === null ? 'channel_id IS NULL' : 'channel_id = :channel_id';
        if ($channelId !== null) {
            $params[':channel_id'] = $channelId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM publication_schedule
              WHERE environment = :environment
                AND {$channelSql}
                AND status = 'completed'
                AND completed_at >= :from_utc
                AND completed_at < :to_utc"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function lastCompletedForTrack(?int $channelId, string $environment): ?array
    {
        $params = [':environment' => $environment];
        $channelSql = $channelId === null ? 'ps.channel_id IS NULL' : 'ps.channel_id = :channel_id';
        if ($channelId !== null) {
            $params[':channel_id'] = $channelId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT ps.*, pa.source_id
               FROM publication_schedule ps
               LEFT JOIN publishing_assets pa
                 ON pa.publishing_asset_id = ps.publishing_asset_id
              WHERE ps.environment = :environment
                AND {$channelSql}
                AND ps.status = 'completed'
                AND ps.completed_at IS NOT NULL
           ORDER BY ps.completed_at DESC, ps.publication_schedule_id DESC
              LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeSchedule($row) : null;
    }

    public function claimNextWaitingForTrack(?int $channelId, string $environment, string $workerId, int $recentLimit = 8): ?array
    {
        $params = [':environment' => $environment];
        $channelSql = $channelId === null ? 'ps.channel_id IS NULL' : 'ps.channel_id = :channel_id';
        if ($channelId !== null) {
            $params[':channel_id'] = $channelId;
        }

        $recentStmt = $this->pdo->prepare(
            "SELECT ps.publishing_job_id, pa.source_id, pa.asset_type
               FROM publication_schedule ps
               LEFT JOIN publishing_assets pa
                 ON pa.publishing_asset_id = ps.publishing_asset_id
              WHERE ps.environment = :environment
                AND {$channelSql}
                AND ps.status = 'completed'
                AND ps.completed_at IS NOT NULL
           ORDER BY ps.completed_at DESC, ps.publication_schedule_id DESC
              LIMIT " . max(1, $recentLimit)
        );
        $recentStmt->execute($params);
        $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $recentJobIds = array_values(array_unique(array_filter(array_map('intval', array_column($recent, 'publishing_job_id')))));
        $recentSourceIds = array_values(array_unique(array_filter(array_map('intval', array_column($recent, 'source_id')))));
        $recentAssetTypes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            array_column($recent, 'asset_type')
        ))));

        $selectParams = $params;
        $selectParams[':duplicate_cutoff'] = gmdate('Y-m-d H:i:s', time() - (90 * 24 * 60 * 60));
        $jobCase = '(0 + 0)';
        if ($recentJobIds) {
            $keys = [];
            foreach ($recentJobIds as $index => $jobId) {
                $key = ':recent_job_' . $index;
                $keys[] = $key;
                $selectParams[$key] = $jobId;
            }
            $jobCase = 'CASE WHEN ps.publishing_job_id IN (' . implode(',', $keys) . ') THEN 1 ELSE 0 END';
        }

        $sourceCase = '(0 + 0)';
        if ($recentSourceIds) {
            $keys = [];
            foreach ($recentSourceIds as $index => $sourceId) {
                $key = ':recent_source_' . $index;
                $keys[] = $key;
                $selectParams[$key] = $sourceId;
            }
            $sourceCase = 'CASE WHEN pa.source_id IN (' . implode(',', $keys) . ') THEN 1 ELSE 0 END';
        }

        $assetTypeCase = '(0 + 0)';
        if ($recentAssetTypes) {
            $keys = [];
            foreach ($recentAssetTypes as $index => $assetType) {
                $key = ':recent_asset_type_' . $index;
                $keys[] = $key;
                $selectParams[$key] = $assetType;
            }
            $assetTypeCase = 'CASE WHEN pa.asset_type IN (' . implode(',', $keys) . ') THEN 1 ELSE 0 END';
        }

        $stmt = $this->pdo->prepare(
            "SELECT ps.publication_schedule_id
               FROM publication_schedule ps
               JOIN publishing_assets pa
                 ON pa.publishing_asset_id = ps.publishing_asset_id
              WHERE ps.environment = :environment
                AND {$channelSql}
                AND ps.status = 'waiting'
                AND NOT EXISTS (
                    SELECT 1
                      FROM publications prior_pub
                      JOIN publishing_assets prior_pa
                        ON prior_pa.publishing_asset_id = prior_pub.publishing_asset_id
                     WHERE prior_pub.published_at IS NOT NULL
                       AND prior_pub.published_at >= :duplicate_cutoff
                       AND prior_pub.status IN ('published', 'test_published', 'posted')
                       AND prior_pub.platform = pa.platform
                       AND prior_pub.environment = ps.environment
                       AND (
                            (ps.channel_id IS NULL AND prior_pub.publishing_channel_id IS NULL)
                         OR (ps.channel_id IS NOT NULL AND prior_pub.publishing_channel_id = ps.channel_id)
                       )
                       AND (
                            (pa.asset_library_id IS NOT NULL AND prior_pa.asset_library_id = pa.asset_library_id)
                         OR (pa.asset_library_id IS NULL AND pa.asset_creator_output_id IS NOT NULL AND prior_pa.asset_creator_output_id = pa.asset_creator_output_id)
                         OR (
                                pa.asset_library_id IS NULL
                            AND pa.asset_creator_output_id IS NULL
                            AND COALESCE(NULLIF(pa.media_url, ''), NULLIF(pa.image_url, ''), NULLIF(pa.media_path, ''), '') <> ''
                            AND COALESCE(NULLIF(prior_pa.media_url, ''), NULLIF(prior_pa.image_url, ''), NULLIF(prior_pa.media_path, ''), '') =
                                COALESCE(NULLIF(pa.media_url, ''), NULLIF(pa.image_url, ''), NULLIF(pa.media_path, ''), '')
                            )
                       )
                )
           ORDER BY {$jobCase} ASC, {$assetTypeCase} ASC, {$sourceCase} ASC, ps.priority ASC, ps.created_at ASC, ps.publication_schedule_id ASC
              LIMIT 1"
        );
        $stmt->execute($selectParams);
        $scheduleId = (int)($stmt->fetchColumn() ?: 0);
        if ($scheduleId <= 0) {
            return null;
        }

        $claim = $this->pdo->prepare(
            "UPDATE publication_schedule
                SET status = 'processing',
                    claimed_at = UTC_TIMESTAMP(),
                    claimed_by = :worker_id,
                    last_attempt_at = UTC_TIMESTAMP(),
                    attempt_count = attempt_count + 1
              WHERE publication_schedule_id = :schedule_id
                AND status = 'waiting'"
        );
        $claim->execute([
            ':worker_id' => $workerId,
            ':schedule_id' => $scheduleId,
        ]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        return $this->findScheduleById($scheduleId);
    }

    public function completeSchedule(int $scheduleId, array $result): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE publication_schedule
                SET status = 'completed',
                    completed_at = UTC_TIMESTAMP(),
                    claimed_at = NULL,
                    claimed_by = NULL,
                    next_retry_at = NULL,
                    last_error_code = NULL,
                    last_error_message = NULL
              WHERE publication_schedule_id = :schedule_id"
        );
        $stmt->execute([':schedule_id' => $scheduleId]);

        $schedule = $this->findScheduleById($scheduleId);
        if ($schedule) {
            $this->createAttempt([
                'publication_schedule_id' => $scheduleId,
                'publishing_job_id' => $schedule['publishing_job_id'],
                'publishing_asset_id' => $schedule['publishing_asset_id'],
                'attempt_number' => max(1, (int)$schedule['attempt_count']),
                'success' => true,
                'retryable' => false,
                'platform_post_id' => $result['platform_post_id'] ?? null,
                'published_url' => $result['published_url'] ?? null,
                'response_summary' => $result['response_summary'] ?? null,
            ]);
        }
    }

    public function failSchedule(int $scheduleId, array $result): void
    {
        $retryable = !empty($result['retryable']);
        $status = $retryable ? 'retry_scheduled' : 'failed';
        $stmt = $this->pdo->prepare(
            "UPDATE publication_schedule
                SET status = :status,
                    claimed_at = NULL,
                    claimed_by = NULL,
                    next_retry_at = :next_retry_at,
                    last_error_code = :last_error_code,
                    last_error_message = :last_error
              WHERE publication_schedule_id = :schedule_id"
        );
        $stmt->execute([
            ':status' => $status,
            ':next_retry_at' => $result['next_retry_at'] ?? null,
            ':last_error_code' => $result['error_code'] ?? null,
            ':last_error' => $result['error_message'] ?? null,
            ':schedule_id' => $scheduleId,
        ]);

        $schedule = $this->findScheduleById($scheduleId);
        if ($schedule) {
            $this->createAttempt([
                'publication_schedule_id' => $scheduleId,
                'publishing_job_id' => $schedule['publishing_job_id'],
                'publishing_asset_id' => $schedule['publishing_asset_id'],
                'attempt_number' => max(1, (int)$schedule['attempt_count']),
                'success' => false,
                'retryable' => $retryable,
                'error_code' => $result['error_code'] ?? null,
                'error_message' => $result['error_message'] ?? null,
                'response_summary' => $result['response_summary'] ?? null,
            ]);
        }
    }

    public function createAttempt(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publication_schedule_attempts
                (publication_schedule_id, publishing_job_id, publishing_asset_id, attempt_number, started_at, finished_at, success, retryable,
                 error_code, error_message, platform_post_id, published_url, response_summary)
             VALUES
                (:publication_schedule_id, :publishing_job_id, :publishing_asset_id, :attempt_number, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :success, :retryable,
                 :error_code, :error_message, :platform_post_id, :published_url, :response_summary)'
        );
        $stmt->execute([
            ':publication_schedule_id' => (int)$data['publication_schedule_id'],
            ':publishing_job_id' => (int)$data['publishing_job_id'],
            ':publishing_asset_id' => (int)$data['publishing_asset_id'],
            ':attempt_number' => (int)$data['attempt_number'],
            ':success' => !empty($data['success']) ? 1 : 0,
            ':retryable' => !empty($data['retryable']) ? 1 : 0,
            ':error_code' => $this->nullableString($data['error_code'] ?? null),
            ':error_message' => $this->nullableString($data['error_message'] ?? null),
            ':platform_post_id' => $this->nullableString($data['platform_post_id'] ?? null),
            ':published_url' => $this->nullableString($data['published_url'] ?? null),
            ':response_summary' => $this->jsonValue($data['response_summary'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function normalizeQueueRow(array $row): array
    {
        foreach ([
            'publishing_job_id', 'publishing_asset_id', 'publishing_channel_id', 'publish_output_id', 'asset_library_id',
            'asset_creator_job_id', 'asset_creator_output_id', 'source_id', 'playlist_instance_id',
            'cta_group_id', 'landing_page_id', 'publication_schedule_id', 'priority',
            'attempt_count', 'max_attempts',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $row[$key] = (int)$row[$key];
            }
        }
        $row['schedule_status'] = $row['schedule_status'] ?? 'unscheduled';
        $row['channel_metadata_json'] = $this->decodeJson($row['channel_metadata_json'] ?? null);
        $row['publication_metadata_json'] = $this->decodeJson($row['publication_metadata_json'] ?? null);
        return $row;
    }

    private function normalizePublication(array $row): array
    {
        foreach (['publishing_job_id', 'publishing_asset_id', 'publishing_channel_id', 'publish_output_id', 'asset_library_id'] as $key) {
            if (isset($row[$key]) && $row[$key] !== '') $row[$key] = (int)$row[$key];
        }
        $row['metadata_json'] = $this->decodeJson($row['metadata_json'] ?? null);
        $row['channel_metadata_json'] = $this->decodeJson($row['channel_metadata_json'] ?? null);
        return $row;
    }

    private function normalizeSchedule(array $row): array
    {
        foreach (['publication_schedule_id', 'publishing_job_id', 'publishing_asset_id', 'channel_id', 'priority', 'attempt_count', 'max_attempts'] as $key) {
            if (isset($row[$key]) && $row[$key] !== '') $row[$key] = (int)$row[$key];
        }
        return $row;
    }

    private function normalizeTrack(array $row): array
    {
        if (array_key_exists('channel_id', $row) && $row['channel_id'] !== null && $row['channel_id'] !== '') {
            $row['channel_id'] = (int)$row['channel_id'];
        } else {
            $row['channel_id'] = null;
        }
        $row['waiting_count'] = (int)($row['waiting_count'] ?? 0);
        $row['channel_metadata_json'] = $this->decodeJson($row['channel_metadata_json'] ?? null);
        return $row;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int)$value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES);
        return (string)$value;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}
