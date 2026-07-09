<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPublishingRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function listJobs(array $filters = []): array
    {
        $where = [];
        $params = [];

        $channel = trim((string)($filters['channel_key'] ?? ''));
        if ($channel !== '') {
            $where[] = 'EXISTS (
                SELECT 1
                  FROM packages pa_filter
                  LEFT JOIN publishing_channels pc_filter
                    ON pc_filter.publishing_channel_id = pa_filter.publishing_channel_id
                 WHERE pa_filter.package_batch_id = pj.package_batch_id
                   AND (pc_filter.channel_key = :channel_key OR pa_filter.platform = :channel_platform)
            )';
            $params['channel_key'] = $channel;
            $params['channel_platform'] = $channel === 'pinterest_pin' ? 'pinterest' : $channel;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(pj.title LIKE :q_job OR CAST(pj.source_id AS CHAR) LIKE :q_source OR p.title LIKE :q_playlist OR pi.instance_name LIKE :q_instance)';
            $like = '%' . $q . '%';
            $params['q_job'] = $like;
            $params['q_source'] = $like;
            $params['q_playlist'] = $like;
            $params['q_instance'] = $like;
        }

        $sql = <<<SQL
            SELECT
              pj.package_batch_id,
              pj.package_batch_id AS publish_job_id,
              pj.source_type,
              pj.source_id,
              pj.creator_job_id,
              pj.playlist_instance_id,
              pj.cta_group_id,
              pj.environment,
              pj.title,
              pj.status,
              pj.notes,
              pj.metadata_json,
              pj.created_at,
              pj.updated_at,
              p.title AS playlist_title,
              pi.slug AS instance_slug,
              pi.instance_name,
              pi.display_title AS instance_display_title
            FROM package_batches pj
            LEFT JOIN playlists p
              ON p.playlist_id = pj.source_id
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = pj.playlist_instance_id
            SQL;

        if ($where) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $sql .= "\nORDER BY pj.updated_at DESC, pj.package_batch_id DESC\nLIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->attachOutputsToJobs(array_map([$this, 'hydrateJobRow'], $rows));
    }

    public function createJob(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO package_batches
                (publishing_channel_id, platform, environment, source_type, source_id, creator_job_id,
                 playlist_instance_id, cta_group_id, landing_page_id, title, description, status, notes, metadata_json)
             VALUES
                (:publishing_channel_id, :platform, :environment, :source_type, :source_id, :creator_job_id,
                 :playlist_instance_id, :cta_group_id, :landing_page_id, :title, :description, :status, :notes, :metadata_json)'
        );
        $stmt->execute([
            'publishing_channel_id' => $data['publishing_channel_id'] ?? null,
            'platform' => $data['platform'] ?? 'pinterest',
            'environment' => $data['environment'] ?? 'test',
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'creator_job_id' => $data['creator_job_id'] ?? $data['asset_creator_job_id'] ?? null,
            'playlist_instance_id' => $data['playlist_instance_id'],
            'cta_group_id' => $data['cta_group_id'] ?? null,
            'landing_page_id' => $data['landing_page_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'],
            'metadata_json' => $this->jsonValue($data['metadata_json'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createOutput(array $data): int
    {
        $metadata = $data['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (isset($data['tracking_code'])) $metadata['tracking_code'] = $data['tracking_code'];

        $values = [
            'package_batch_id' => $data['package_batch_id'] ?? $data['publishing_job_id'] ?? $data['publish_job_id'],
            'publishing_channel_id' => $data['publishing_channel_id'] ?? null,
            'platform' => $data['platform'] ?? 'pinterest',
            'environment' => $data['environment'] ?? 'test',
            'source_type' => $data['source_type'] ?? 'playlist',
            'source_id' => $data['source_id'],
            'source_asset_id' => $data['source_asset_id'] ?? $data['asset_creator_output_id'] ?? null,
            'asset_library_id' => $data['asset_library_id'] ?? $data['library_asset_id'] ?? null,
            'playlist_instance_id' => $data['playlist_instance_id'] ?? null,
            'cta_group_id' => $data['cta_group_id'] ?? null,
            'landing_page_id' => $data['landing_page_id'] ?? null,
            'asset_type' => $data['asset_type'] ?? $data['output_type'],
            'status' => $data['status'],
            'title' => $data['title'],
            'description' => $data['description'],
            'alt_text' => $data['alt_text'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'media_path' => $data['media_path'] ?? $data['asset_path'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'destination_url' => $data['destination_url'],
            'canonical_destination_url' => $data['canonical_destination_url'] ?? $data['destination_url'],
            'tracked_destination_url' => $data['tracked_destination_url'] ?? $data['tracking_url'] ?? $data['destination_url'],
            'board_id' => $data['board_id'] ?? ($metadata['board_id'] ?? null),
            'board_name' => $data['board_name'] ?? ($metadata['board_name'] ?? null),
            'board_url' => $data['board_url'] ?? ($metadata['board_url'] ?? null),
            'board_slug' => $data['board_slug'] ?? ($metadata['board_slug'] ?? null),
            'duplicate_fingerprint' => $data['duplicate_fingerprint'] ?? null,
            'metadata_json' => $metadata,
            'published_at' => $data['published_at'],
        ];

        $columns = array_keys($values);
        $columnList = implode(', ', $columns);
        $placeholderList = ':' . implode(', :', $columns);
        $values['metadata_json'] = $this->jsonValue($values['metadata_json']);
        $stmt = $this->pdo->prepare("INSERT INTO packages ({$columnList}) VALUES ({$placeholderList})");
        $stmt->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    public function findJobById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM package_batches
              WHERE package_batch_id = :package_batch_id
              LIMIT 1'
        );
        $stmt->execute([':package_batch_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findJobForCreatorSetup(
        int $assetCreatorJobId,
        string $platform,
        string $environment,
        ?int $playlistInstanceId,
        int $ctaGroupId,
        string $destinationKey
    ): ?array {
        $instanceClause = $playlistInstanceId !== null && $playlistInstanceId > 0
            ? "\n                AND playlist_instance_id = :playlist_instance_id"
            : '';
        $sql = 'SELECT *
               FROM package_batches
              WHERE creator_job_id = :creator_job_id
                AND platform = :platform
                AND environment = :environment
                ' . $instanceClause . '
                AND cta_group_id = :cta_group_id
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.destination_key")), "") = :destination_key
              ORDER BY package_batch_id DESC
              LIMIT 1';
        $params = [
            ':creator_job_id' => $assetCreatorJobId,
            ':platform' => $platform,
            ':environment' => $environment,
            ':cta_group_id' => $ctaGroupId,
            ':destination_key' => $destinationKey,
        ];
        if ($playlistInstanceId !== null && $playlistInstanceId > 0) {
            $params[':playlist_instance_id'] = $playlistInstanceId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findJobForCreatorDestination(
        int $assetCreatorJobId,
        string $platform,
        string $environment,
        int $ctaGroupId,
        string $destinationKey
    ): ?array {
        return $this->findJobForCreatorSetup(
            $assetCreatorJobId,
            $platform,
            $environment,
            null,
            $ctaGroupId,
            $destinationKey
        );
    }

    public function findOutputByCreatorOutput(int $publishingJobId, int $assetCreatorOutputId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM packages
              WHERE package_batch_id = :package_batch_id
                AND source_asset_id = :source_asset_id
              LIMIT 1'
        );
        $stmt->execute([
            ':package_batch_id' => $publishingJobId,
            ':source_asset_id' => $assetCreatorOutputId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateOutputStatus(int $outputId, string $status, ?string $externalUrl, ?string $publishedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE packages
                SET status = :status,
                    published_at = :published_at
              WHERE package_id = :package_id'
        );
        $stmt->execute([
            'status' => $status,
            'published_at' => $publishedAt,
            'package_id' => $outputId,
        ]);

        if ($externalUrl !== null || $publishedAt !== null) {
            $asset = $this->getOutputForPublisher($outputId);
            if ($asset) {
                $publication = $this->pdo->prepare(
                    'INSERT INTO published_assets
                        (package_batch_id, package_id, publishing_channel_id, platform, environment, status,
                         external_post_url, published_at, metadata_json)
                     VALUES
                        (:package_batch_id, :package_id, :publishing_channel_id, :platform, :environment, :status,
                         :external_post_url, :published_at, :metadata_json)'
                );
                $publication->execute([
                    ':package_batch_id' => (int)$asset['package_batch_id'],
                    ':package_id' => $outputId,
                    ':publishing_channel_id' => $asset['publishing_channel_id'] ?? null,
                    ':platform' => $asset['platform'] ?? 'pinterest',
                    ':environment' => $asset['environment'] ?? 'test',
                    ':status' => $status,
                    ':external_post_url' => $externalUrl,
                    ':published_at' => $publishedAt,
                    ':metadata_json' => $this->jsonValue(['manual_mark_published' => true]),
                ]);
            }
        }
    }

    public function updateJobStatusFromOutputs(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE package_batches pj
                SET pj.status = (
                  SELECT CASE
                    WHEN SUM(pa.status = 'failed') > 0 THEN 'needs_attention'
                    WHEN COUNT(*) > 0 AND SUM(pa.status IN ('published', 'posted', 'test_published')) = COUNT(*) THEN 'published'
                    WHEN COUNT(*) > 0 AND SUM(pa.status IN ('staged', 'generated', 'ready_to_publish', 'scheduled')) > 0 THEN 'in_progress'
                    ELSE pj.status
                  END
                  FROM packages pa
                  WHERE pa.package_batch_id = pj.package_batch_id
                )
              WHERE pj.package_batch_id = :package_batch_id"
        );
        $stmt->execute(['package_batch_id' => $jobId]);
    }

    public function getOutputForPublisher(int $outputId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                pa.*,
                pa.package_id AS publish_output_id,
                pa.package_batch_id AS publish_job_id,
                pa.asset_library_id AS library_asset_id,
                COALESCE(pc.channel_key, pa.platform) AS channel_key,
                pa.asset_type AS output_type,
                pa.tracked_destination_url AS tracking_url,
                pub.external_post_id AS external_id,
                pub.external_post_url AS external_url,
                pj.source_type,
                pj.source_id,
                pj.playlist_instance_id,
                pj.title AS job_title,
                p.title AS playlist_title,
                pi.slug AS instance_slug,
                pi.display_title AS instance_display_title,
                al.rel_path AS asset_rel_path
               FROM packages pa
               JOIN package_batches pj
                 ON pj.package_batch_id = pa.package_batch_id
               LEFT JOIN publishing_channels pc
                 ON pc.publishing_channel_id = pa.publishing_channel_id
               LEFT JOIN published_assets pub
                 ON pub.package_id = pa.package_id
                AND pub.status IN ("published", "test_published")
               LEFT JOIN playlists p
                 ON p.playlist_id = pj.source_id
               LEFT JOIN playlist_instances pi
                 ON pi.playlist_instance_id = pj.playlist_instance_id
               LEFT JOIN asset_library al
                 ON al.asset_library_id = pa.asset_library_id
              WHERE pa.package_id = :publish_output_id
              LIMIT 1'
        );
        $stmt->execute([':publish_output_id' => $outputId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['package_id'] = (int)$row['publish_output_id'];
        $row['package_batch_id'] = (int)$row['publish_job_id'];
        $row['publishing_asset_id'] = $row['package_id'];
        $row['publishing_job_id'] = $row['package_batch_id'];
        $row['publish_output_id'] = (int)$row['publish_output_id'];
        $row['publish_job_id'] = (int)$row['publish_job_id'];
        $row['source_id'] = (int)$row['source_id'];
        $row['playlist_instance_id'] = $row['playlist_instance_id'] !== null ? (int)$row['playlist_instance_id'] : null;
        $row['library_asset_id'] = $row['library_asset_id'] !== null ? (int)$row['library_asset_id'] : null;
        return $row;
    }

    public function getPlaylistSummary(int $playlistId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT playlist_id, title, slug, headline, meta_description
               FROM playlists
              WHERE playlist_id = :playlist_id
              LIMIT 1'
        );
        $stmt->execute(['playlist_id' => $playlistId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getDefaultPlaylistInstance(int $playlistId, ?int $preferredInstanceId = null): ?array
    {
        $params = ['playlist_id' => $playlistId];
        $preferredOrder = '';
        if ($preferredInstanceId !== null && $preferredInstanceId > 0) {
            $preferredOrder = 'pi.playlist_instance_id = :preferred_instance_id DESC,';
            $params['preferred_instance_id'] = $preferredInstanceId;
        }

        $sql = <<<SQL
            SELECT
              pi.playlist_instance_id,
              pi.playlist_id,
              pi.slug,
              pi.instance_name,
              pi.display_title,
              pi.display_subtitle,
              pi.share_title,
              pi.share_description
            FROM playlist_instances pi
            WHERE pi.playlist_id = :playlist_id
              AND pi.is_active = 1
            ORDER BY {$preferredOrder} pi.share_enabled DESC, pi.playlist_instance_id ASC
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLandingPageForPublishing(int $landingPageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, title, status, page_type, primary_playlist_instance_id
               FROM landing_pages
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $landingPageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        $row['primary_playlist_instance_id'] = $row['primary_playlist_instance_id'] !== null
            ? (int)$row['primary_playlist_instance_id']
            : null;
        return $row;
    }

    private function hydrateJobRow(array $row): array
    {
        $row['publish_job_id'] = (int)($row['publish_job_id'] ?? 0);
        $row['package_batch_id'] = $row['publish_job_id'];
        $row['object_type'] = 'package_batch';
        $row['source_id'] = (int)($row['source_id'] ?? 0);
        $row['creator_job_id'] = $row['creator_job_id'] !== null && $row['creator_job_id'] !== '' ? (int)$row['creator_job_id'] : null;
        $row['playlist_instance_id'] = isset($row['playlist_instance_id']) ? (int)$row['playlist_instance_id'] : null;
        $row['cta_group_id'] = $row['cta_group_id'] !== null && $row['cta_group_id'] !== '' ? (int)$row['cta_group_id'] : null;
        $row['outputs'] = [];
        $row['packages'] = [];
        return $row;
    }

    private function attachOutputsToJobs(array $jobs): array
    {
        $batchIds = array_values(array_unique(array_filter(array_map(
            fn(array $job): int => (int)($job['package_batch_id'] ?? 0),
            $jobs
        ))));
        if (!$batchIds) return $jobs;

        $placeholders = [];
        $params = [];
        foreach ($batchIds as $index => $batchId) {
            $placeholder = ':batch_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $batchId;
        }

        $sql = <<<SQL
            SELECT
              pa.package_id,
              pa.package_batch_id,
              COALESCE(pc.channel_key, pa.platform) AS channel_key,
              pa.environment,
              pa.asset_type AS output_type,
              pa.status,
              pa.title,
              pa.board_id,
              pa.board_name,
              pa.board_url,
              pa.board_slug,
              COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pa.metadata_json, "$.tracking_code")), '') AS tracking_code,
              COALESCE(pa.tracked_destination_url, pa.destination_url, '') AS tracking_url,
              COALESCE(pa.destination_url, '') AS destination_url,
              COALESCE(pub.external_post_url, '') AS external_url,
              COALESCE(pub.external_post_id, '') AS external_id,
              COALESCE(pa.published_at, pub.published_at, '') AS published_at,
              pat.attempt_id AS publisher_attempt_id,
              pat.status AS publisher_attempt_status,
              pat.publisher_service,
              pat.request_payload_json AS publisher_request_payload_json,
              pat.response_payload_json AS publisher_response_payload_json,
              pat.error_code AS publisher_error_code,
              pat.error_message AS publisher_error_message,
              pat.started_at AS publisher_started_at,
              pat.finished_at AS publisher_finished_at,
              ps.queue_item_id,
              ps.status AS schedule_status,
              ps.attempt_count,
              ps.max_attempts,
              ps.next_retry_at,
              ps.last_error_code AS schedule_error_code,
              ps.last_error_message AS schedule_error,
              ps.scheduled_at AS schedule_scheduled_at,
              ps.completed_at AS schedule_completed_at,
              pa.last_error_code,
              pa.last_error_message,
              COALESCE(pa.created_at, '') AS created_at,
              pa.asset_library_id AS library_asset_id,
              COALESCE(NULLIF(pl_after.photo_permission_status, ''), NULLIF(pl_before.photo_permission_status, ''), NULLIF(pl.photo_permission_status, ''), c.photo_permission_status, 'unknown') AS photo_permission_status,
              COALESCE(al.legacy_photo_library_id, pl_after.photo_library_id, pl_before.photo_library_id, pl.photo_library_id) AS permission_photo_library_id,
              c.id AS client_id,
              COALESCE(c.name, '') AS client_name,
              COALESCE(c.email, '') AS client_email,
              COALESCE(pa.metadata_json, '') AS metadata_json
            FROM packages pa
            LEFT JOIN publishing_channels pc
              ON pc.publishing_channel_id = pa.publishing_channel_id
            LEFT JOIN published_assets pub
              ON pub.package_id = pa.package_id
             AND pub.published_asset_id = (
                SELECT pub_latest.published_asset_id
                  FROM published_assets pub_latest
                 WHERE pub_latest.package_id = pa.package_id
                   AND pub_latest.status IN ('published', 'test_published')
                 ORDER BY CASE WHEN pub_latest.status = 'published' THEN 0 ELSE 1 END,
                          pub_latest.published_asset_id DESC
                 LIMIT 1
             )
            LEFT JOIN publisher_attempts pat
              ON pat.attempt_id = (
                SELECT pat_latest.attempt_id
                  FROM publisher_attempts pat_latest
                 WHERE pat_latest.package_id = pa.package_id
                 ORDER BY pat_latest.attempt_id DESC
                 LIMIT 1
             )
            LEFT JOIN scheduler_queue_items ps
              ON ps.queue_item_id = (
                SELECT ps_latest.queue_item_id
                  FROM scheduler_queue_items ps_latest
                 WHERE ps_latest.package_id = pa.package_id
                   AND ps_latest.status <> 'cancelled'
                 ORDER BY ps_latest.queue_item_id DESC
                 LIMIT 1
             )
            LEFT JOIN asset_library al
              ON al.asset_library_id = pa.asset_library_id
            LEFT JOIN photo_library pl
              ON pl.photo_library_id = al.legacy_photo_library_id
            LEFT JOIN photo_library pl_before
              ON pl_before.photo_library_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.before.photo_library_id")) AS UNSIGNED)
            LEFT JOIN photo_library pl_after
              ON pl_after.photo_library_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.after.photo_library_id")) AS UNSIGNED)
            LEFT JOIN clients c
              ON c.id = COALESCE(al.client_id, pl.client_id, pl_after.client_id, pl_before.client_id)
            WHERE pa.package_batch_id IN (
        SQL;
        $sql .= implode(', ', $placeholders) . ")\nORDER BY pa.package_batch_id ASC, pa.package_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $outputsByBatch = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $output = $this->hydrateOutputRow($row);
            $outputsByBatch[(int)$output['package_batch_id']][] = $output;
        }

        foreach ($jobs as &$job) {
            $outputs = $outputsByBatch[(int)$job['package_batch_id']] ?? [];
            $job['outputs'] = $outputs;
            $job['packages'] = $outputs;
        }
        unset($job);

        return $jobs;
    }

    private function hydrateOutputRow(array $row): array
    {
        $packageId = (int)($row['package_id'] ?? 0);
        $batchId = (int)($row['package_batch_id'] ?? 0);
        return [
            'object_type' => 'package',
            'package_id' => $packageId,
            'publish_output_id' => $packageId,
            'publishing_asset_id' => $packageId,
            'package_batch_id' => $batchId,
            'publish_job_id' => $batchId,
            'publishing_job_id' => $batchId,
            'channel_key' => (string)($row['channel_key'] ?? ''),
            'environment' => (string)($row['environment'] ?: 'test'),
            'output_type' => (string)($row['output_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'board_id' => (string)($row['board_id'] ?? ''),
            'board_name' => (string)($row['board_name'] ?? ''),
            'board_url' => (string)($row['board_url'] ?? ''),
            'board_slug' => (string)($row['board_slug'] ?? ''),
            'tracking_code' => (string)($row['tracking_code'] ?? ''),
            'tracking_url' => (string)($row['tracking_url'] ?? ''),
            'destination_url' => (string)($row['destination_url'] ?? ''),
            'external_url' => (string)($row['external_url'] ?? ''),
            'external_id' => (string)($row['external_id'] ?? ''),
            'published_at' => (string)($row['published_at'] ?? ''),
            'publisher_attempt_id' => $row['publisher_attempt_id'] !== null && $row['publisher_attempt_id'] !== '' ? (int)$row['publisher_attempt_id'] : null,
            'publisher_attempt_status' => (string)($row['publisher_attempt_status'] ?? ''),
            'publisher_service' => (string)($row['publisher_service'] ?? ''),
            'publisher_request_payload_json' => (string)($row['publisher_request_payload_json'] ?? ''),
            'publisher_response_payload_json' => (string)($row['publisher_response_payload_json'] ?? ''),
            'publisher_error_code' => (string)($row['publisher_error_code'] ?? ''),
            'publisher_error_message' => (string)($row['publisher_error_message'] ?? ''),
            'publisher_started_at' => (string)($row['publisher_started_at'] ?? ''),
            'publisher_finished_at' => (string)($row['publisher_finished_at'] ?? ''),
            'queue_item_id' => $row['queue_item_id'] !== null && $row['queue_item_id'] !== '' ? (int)$row['queue_item_id'] : null,
            'schedule_status' => (string)($row['schedule_status'] ?? ''),
            'attempt_count' => $row['attempt_count'] !== null && $row['attempt_count'] !== '' ? (int)$row['attempt_count'] : null,
            'max_attempts' => $row['max_attempts'] !== null && $row['max_attempts'] !== '' ? (int)$row['max_attempts'] : null,
            'next_retry_at' => (string)($row['next_retry_at'] ?? ''),
            'schedule_error_code' => (string)($row['schedule_error_code'] ?? ''),
            'schedule_error' => (string)($row['schedule_error'] ?? ''),
            'schedule_scheduled_at' => (string)($row['schedule_scheduled_at'] ?? ''),
            'schedule_completed_at' => (string)($row['schedule_completed_at'] ?? ''),
            'last_error_code' => (string)($row['last_error_code'] ?? ''),
            'last_error_message' => (string)($row['last_error_message'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'library_asset_id' => $row['library_asset_id'] !== null && $row['library_asset_id'] !== '' ? (int)$row['library_asset_id'] : null,
            'photo_permission_status' => (string)($row['photo_permission_status'] ?? ''),
            'permission_photo_library_id' => $row['permission_photo_library_id'] !== null && $row['permission_photo_library_id'] !== '' ? (int)$row['permission_photo_library_id'] : null,
            'client_id' => $row['client_id'] !== null && $row['client_id'] !== '' ? (int)$row['client_id'] : null,
            'client_name' => (string)($row['client_name'] ?? ''),
            'client_email' => (string)($row['client_email'] ?? ''),
            'metadata_json' => (string)($row['metadata_json'] ?? ''),
        ];
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES);
        return (string)$value;
    }

}
