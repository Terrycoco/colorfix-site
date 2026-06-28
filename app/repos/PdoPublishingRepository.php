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
                  FROM publishing_assets pa_filter
                  LEFT JOIN publishing_channels pc_filter
                    ON pc_filter.publishing_channel_id = pa_filter.publishing_channel_id
                 WHERE pa_filter.publishing_job_id = pj.publishing_job_id
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
              pj.publishing_job_id,
              pj.publishing_job_id AS publish_job_id,
              pj.source_type,
              pj.source_id,
              pj.playlist_instance_id,
              pj.title,
              pj.status,
              pj.notes,
              pj.created_at,
              pj.updated_at,
              p.title AS playlist_title,
              pi.instance_name,
              pi.display_title AS instance_display_title,
              GROUP_CONCAT(
                CONCAT_WS('|',
                  pa.publishing_asset_id,
                  COALESCE(pc.channel_key, pa.platform),
                  pa.environment,
                  pa.asset_type,
                  pa.status,
                  COALESCE(pa.title, ''),
                  COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pa.metadata_json, "$.tracking_code")), ''),
                  COALESCE(pa.tracked_destination_url, pa.destination_url, ''),
                  COALESCE(pa.destination_url, ''),
                  COALESCE(pub.external_post_url, ''),
                  COALESCE(pa.published_at, pub.published_at, ''),
                  COALESCE(pa.created_at, ''),
                  COALESCE(pa.asset_library_id, ''),
                  COALESCE(NULLIF(pl_after.photo_permission_status, ''), NULLIF(pl_before.photo_permission_status, ''), NULLIF(pl.photo_permission_status, ''), c.photo_permission_status, 'unknown'),
                  COALESCE(al.legacy_photo_library_id, pl_after.photo_library_id, pl_before.photo_library_id, pl.photo_library_id, ''),
                  COALESCE(c.id, ''),
                  COALESCE(c.name, ''),
                  COALESCE(c.email, ''),
                  COALESCE(pa.metadata_json, '')
                )
                ORDER BY pa.publishing_asset_id
                SEPARATOR '\n'
              ) AS output_rows
            FROM publishing_jobs pj
            LEFT JOIN playlists p
              ON p.playlist_id = pj.source_id
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = pj.playlist_instance_id
            LEFT JOIN publishing_assets pa
              ON pa.publishing_job_id = pj.publishing_job_id
            LEFT JOIN publishing_channels pc
              ON pc.publishing_channel_id = pa.publishing_channel_id
            LEFT JOIN publications pub
              ON pub.publishing_asset_id = pa.publishing_asset_id
             AND pub.publication_id = (
                SELECT pub_latest.publication_id
                  FROM publications pub_latest
                 WHERE pub_latest.publishing_asset_id = pa.publishing_asset_id
                   AND pub_latest.status IN ('published', 'test_published')
                 ORDER BY CASE WHEN pub_latest.status = 'published' THEN 0 ELSE 1 END,
                          pub_latest.publication_id DESC
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
            SQL;

        if ($where) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $sql .= "\nGROUP BY pj.publishing_job_id\nORDER BY pj.updated_at DESC, pj.publishing_job_id DESC\nLIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateJobRow'], $rows);
    }

    public function createJob(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publishing_jobs
                (publishing_channel_id, platform, environment, source_type, source_id, asset_creator_job_id,
                 playlist_instance_id, cta_group_id, landing_page_id, title, description, status, notes, metadata_json)
             VALUES
                (:publishing_channel_id, :platform, :environment, :source_type, :source_id, :asset_creator_job_id,
                 :playlist_instance_id, :cta_group_id, :landing_page_id, :title, :description, :status, :notes, :metadata_json)'
        );
        $stmt->execute([
            'publishing_channel_id' => $data['publishing_channel_id'] ?? null,
            'platform' => $data['platform'] ?? 'pinterest',
            'environment' => $data['environment'] ?? 'test',
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'asset_creator_job_id' => $data['asset_creator_job_id'] ?? null,
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
            'publishing_job_id' => $data['publishing_job_id'] ?? $data['publish_job_id'],
            'publishing_channel_id' => $data['publishing_channel_id'] ?? null,
            'platform' => $data['platform'] ?? 'pinterest',
            'environment' => $data['environment'] ?? 'test',
            'source_type' => $data['source_type'] ?? 'playlist',
            'source_id' => $data['source_id'],
            'asset_creator_output_id' => $data['asset_creator_output_id'] ?? null,
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
            'metadata_json' => $metadata,
            'published_at' => $data['published_at'],
        ];

        $columns = array_keys($values);
        $columnList = implode(', ', $columns);
        $placeholderList = ':' . implode(', :', $columns);
        $values['metadata_json'] = $this->jsonValue($values['metadata_json']);
        $stmt = $this->pdo->prepare("INSERT INTO publishing_assets ({$columnList}) VALUES ({$placeholderList})");
        $stmt->execute($values);
        return (int)$this->pdo->lastInsertId();
    }

    public function findJobById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_jobs
              WHERE publishing_job_id = :publishing_job_id
              LIMIT 1'
        );
        $stmt->execute([':publishing_job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findJobForCreatorSetup(
        int $assetCreatorJobId,
        string $platform,
        string $environment,
        int $playlistInstanceId,
        int $ctaGroupId,
        string $destinationKey
    ): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_jobs
              WHERE asset_creator_job_id = :asset_creator_job_id
                AND platform = :platform
                AND environment = :environment
                AND playlist_instance_id = :playlist_instance_id
                AND cta_group_id = :cta_group_id
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, "$.destination_key")), "") = :destination_key
              ORDER BY publishing_job_id DESC
              LIMIT 1'
        );
        $stmt->execute([
            ':asset_creator_job_id' => $assetCreatorJobId,
            ':platform' => $platform,
            ':environment' => $environment,
            ':playlist_instance_id' => $playlistInstanceId,
            ':cta_group_id' => $ctaGroupId,
            ':destination_key' => $destinationKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findOutputByCreatorOutput(int $publishingJobId, int $assetCreatorOutputId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_assets
              WHERE publishing_job_id = :publishing_job_id
                AND asset_creator_output_id = :asset_creator_output_id
              LIMIT 1'
        );
        $stmt->execute([
            ':publishing_job_id' => $publishingJobId,
            ':asset_creator_output_id' => $assetCreatorOutputId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateOutputStatus(int $outputId, string $status, ?string $externalUrl, ?string $publishedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE publishing_assets
                SET status = :status,
                    published_at = :published_at
              WHERE publishing_asset_id = :publishing_asset_id'
        );
        $stmt->execute([
            'status' => $status,
            'published_at' => $publishedAt,
            'publishing_asset_id' => $outputId,
        ]);

        if ($externalUrl !== null || $publishedAt !== null) {
            $asset = $this->getOutputForPublisher($outputId);
            if ($asset) {
                $publication = $this->pdo->prepare(
                    'INSERT INTO publications
                        (publishing_job_id, publishing_asset_id, publishing_channel_id, platform, environment, status,
                         external_post_url, published_at, metadata_json)
                     VALUES
                        (:publishing_job_id, :publishing_asset_id, :publishing_channel_id, :platform, :environment, :status,
                         :external_post_url, :published_at, :metadata_json)'
                );
                $publication->execute([
                    ':publishing_job_id' => (int)$asset['publishing_job_id'],
                    ':publishing_asset_id' => $outputId,
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
            "UPDATE publishing_jobs pj
                SET pj.status = (
                  SELECT CASE
                    WHEN SUM(pa.status = 'failed') > 0 THEN 'needs_attention'
                    WHEN COUNT(*) > 0 AND SUM(pa.status IN ('published', 'posted', 'test_published')) = COUNT(*) THEN 'published'
                    WHEN COUNT(*) > 0 AND SUM(pa.status IN ('staged', 'generated', 'ready_to_publish', 'scheduled')) > 0 THEN 'in_progress'
                    ELSE pj.status
                  END
                  FROM publishing_assets pa
                  WHERE pa.publishing_job_id = pj.publishing_job_id
                )
              WHERE pj.publishing_job_id = :publishing_job_id"
        );
        $stmt->execute(['publishing_job_id' => $jobId]);
    }

    public function getOutputForPublisher(int $outputId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                pa.*,
                pa.publishing_asset_id AS publish_output_id,
                pa.publishing_job_id AS publish_job_id,
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
               FROM publishing_assets pa
               JOIN publishing_jobs pj
                 ON pj.publishing_job_id = pa.publishing_job_id
               LEFT JOIN publishing_channels pc
                 ON pc.publishing_channel_id = pa.publishing_channel_id
               LEFT JOIN publications pub
                 ON pub.publishing_asset_id = pa.publishing_asset_id
                AND pub.status IN ("published", "test_published")
               LEFT JOIN playlists p
                 ON p.playlist_id = pj.source_id
               LEFT JOIN playlist_instances pi
                 ON pi.playlist_instance_id = pj.playlist_instance_id
               LEFT JOIN asset_library al
                 ON al.asset_library_id = pa.asset_library_id
              WHERE pa.publishing_asset_id = :publish_output_id
              LIMIT 1'
        );
        $stmt->execute([':publish_output_id' => $outputId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
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
        $outputs = [];
        $outputRows = trim((string)($row['output_rows'] ?? ''));
        if ($outputRows !== '') {
            foreach (explode("\n", $outputRows) as $outputLine) {
                $parts = explode('|', $outputLine);
                if (count($parts) < 9) continue;
                $outputs[] = [
                    'publish_output_id' => (int)$parts[0],
                    'channel_key' => $parts[1],
                    'environment' => $parts[2] ?: 'test',
                    'output_type' => $parts[3],
                    'status' => $parts[4],
                    'title' => $parts[5],
                    'tracking_code' => $parts[6],
                    'tracking_url' => $parts[7],
                    'destination_url' => $parts[8],
                    'external_url' => $parts[9],
                    'published_at' => $parts[10],
                    'created_at' => $parts[11] ?? '',
                    'library_asset_id' => isset($parts[12]) && $parts[12] !== '' ? (int)$parts[12] : null,
                    'photo_permission_status' => $parts[13] ?? '',
                    'permission_photo_library_id' => isset($parts[14]) && $parts[14] !== '' ? (int)$parts[14] : null,
                    'client_id' => isset($parts[15]) && $parts[15] !== '' ? (int)$parts[15] : null,
                    'client_name' => $parts[16] ?? '',
                    'client_email' => $parts[17] ?? '',
                    'metadata_json' => $parts[18] ?? '',
                ];
            }
        }
        unset($row['output_rows']);
        $row['publish_job_id'] = (int)($row['publish_job_id'] ?? 0);
        $row['source_id'] = (int)($row['source_id'] ?? 0);
        $row['playlist_instance_id'] = isset($row['playlist_instance_id']) ? (int)$row['playlist_instance_id'] : null;
        $row['outputs'] = $outputs;
        return $row;
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES);
        return (string)$value;
    }

}
