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
                  FROM publish_outputs po_filter
                 WHERE po_filter.publish_job_id = pj.publish_job_id
                   AND po_filter.channel_key = :channel_key
            )';
            $params['channel_key'] = $channel;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(pj.title LIKE :q OR CAST(pj.source_id AS CHAR) LIKE :q OR p.title LIKE :q OR pi.instance_name LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $sql = <<<SQL
            SELECT
              pj.publish_job_id,
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
                  po.publish_output_id,
                  po.channel_key,
                  po.output_type,
                  po.status,
                  COALESCE(po.title, ''),
                  COALESCE(po.tracking_code, ''),
                  COALESCE(po.tracking_url, ''),
                  COALESCE(po.external_url, ''),
                  COALESCE(po.published_at, ''),
                  COALESCE(po.created_at, ''),
                  COALESCE(po.library_asset_id, '')
                )
                ORDER BY po.publish_output_id
                SEPARATOR '\n'
              ) AS output_rows
            FROM publish_jobs pj
            LEFT JOIN playlists p
              ON p.playlist_id = pj.source_id
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = pj.playlist_instance_id
            LEFT JOIN publish_outputs po
              ON po.publish_job_id = pj.publish_job_id
            SQL;

        if ($where) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $sql .= "\nGROUP BY pj.publish_job_id\nORDER BY pj.updated_at DESC, pj.publish_job_id DESC\nLIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateJobRow'], $rows);
    }

    public function createJob(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publish_jobs
                (source_type, source_id, playlist_instance_id, title, status, notes)
             VALUES
                (:source_type, :source_id, :playlist_instance_id, :title, :status, :notes)'
        );
        $stmt->execute([
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'playlist_instance_id' => $data['playlist_instance_id'],
            'title' => $data['title'],
            'status' => $data['status'],
            'notes' => $data['notes'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createOutput(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publish_outputs
                (publish_job_id, channel_key, output_type, status, title, description, tracking_code,
                 tracking_url, destination_url, external_url, asset_path, library_asset_id, metadata_json, generated_at,
                 staged_at, published_at)
             VALUES
                (:publish_job_id, :channel_key, :output_type, :status, :title, :description, :tracking_code,
                 :tracking_url, :destination_url, :external_url, :asset_path, :library_asset_id, :metadata_json, :generated_at,
                 :staged_at, :published_at)'
        );
        $stmt->execute([
            'publish_job_id' => $data['publish_job_id'],
            'channel_key' => $data['channel_key'],
            'output_type' => $data['output_type'],
            'status' => $data['status'],
            'title' => $data['title'],
            'description' => $data['description'],
            'tracking_code' => $data['tracking_code'],
            'tracking_url' => $data['tracking_url'],
            'destination_url' => $data['destination_url'],
            'external_url' => $data['external_url'],
            'asset_path' => $data['asset_path'],
            'library_asset_id' => $data['library_asset_id'] ?? null,
            'metadata_json' => $data['metadata_json'],
            'generated_at' => $data['generated_at'],
            'staged_at' => $data['staged_at'],
            'published_at' => $data['published_at'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateOutputStatus(int $outputId, string $status, ?string $externalUrl, ?string $publishedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE publish_outputs
                SET status = :status,
                    external_url = :external_url,
                    published_at = :published_at
              WHERE publish_output_id = :publish_output_id'
        );
        $stmt->execute([
            'status' => $status,
            'external_url' => $externalUrl,
            'published_at' => $publishedAt,
            'publish_output_id' => $outputId,
        ]);
    }

    public function updateJobStatusFromOutputs(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE publish_jobs pj
                SET pj.status = (
                  SELECT CASE
                    WHEN SUM(po.status = 'failed') > 0 THEN 'needs_attention'
                    WHEN COUNT(*) > 0 AND SUM(po.status IN ('published', 'posted')) = COUNT(*) THEN 'published'
                    WHEN COUNT(*) > 0 AND SUM(po.status IN ('staged', 'generated')) > 0 THEN 'in_progress'
                    ELSE pj.status
                  END
                  FROM publish_outputs po
                  WHERE po.publish_job_id = pj.publish_job_id
                )
              WHERE pj.publish_job_id = :publish_job_id"
        );
        $stmt->execute(['publish_job_id' => $jobId]);
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
                    'output_type' => $parts[2],
                    'status' => $parts[3],
                    'title' => $parts[4],
                    'tracking_code' => $parts[5],
                    'tracking_url' => $parts[6],
                    'external_url' => $parts[7],
                    'published_at' => $parts[8],
                    'created_at' => $parts[9] ?? '',
                    'library_asset_id' => isset($parts[10]) && $parts[10] !== '' ? (int)$parts[10] : null,
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
}
