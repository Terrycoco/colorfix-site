<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoAssetLibraryRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $assetLibraryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT al.*,
                    acj.asset_creator_job_id AS creator_job_id,
                    acj.title AS creator_job_title,
                    CASE
                        WHEN acj.source_type = \'playlist\' THEN acj.source_id
                        ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(acj.instructions_json, "$.source.playlist_id")) AS UNSIGNED)
                    END AS source_playlist_id,
                    p.title AS source_playlist_title,
                    COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.pin_type")),
                        JSON_UNQUOTE(JSON_EXTRACT(aco.metadata_json, "$.pin_type"))
                    ) AS pin_type,
                    COALESCE(al.client_id, pl.client_id, pl_after.client_id, pl_before.client_id) AS client_id,
                    COALESCE(al.legacy_photo_library_id, pl_after.photo_library_id, pl_before.photo_library_id, pl.photo_library_id) AS permission_photo_library_id,
                    c.name AS client_name,
                    c.email AS client_email,
                    COALESCE(NULLIF(pl_after.photo_permission_status, \'\'), NULLIF(pl_before.photo_permission_status, \'\'), NULLIF(pl.photo_permission_status, \'\'), c.photo_permission_status, \'unknown\') AS photo_permission_status
               FROM asset_library al
          LEFT JOIN photo_library pl
                 ON pl.photo_library_id = al.legacy_photo_library_id
          LEFT JOIN asset_creator_outputs aco
                 ON aco.asset_library_id = al.asset_library_id
          LEFT JOIN asset_creator_jobs acj
                 ON acj.asset_creator_job_id = COALESCE(
                    CASE WHEN al.source_type = \'asset_creator_job\' THEN al.source_id ELSE NULL END,
                    aco.asset_creator_job_id
                 )
          LEFT JOIN playlists p
                 ON p.playlist_id = CASE
                    WHEN acj.source_type = \'playlist\' THEN acj.source_id
                    ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(acj.instructions_json, "$.source.playlist_id")) AS UNSIGNED)
                 END
          LEFT JOIN photo_library pl_before
                 ON pl_before.photo_library_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.before.photo_library_id")) AS UNSIGNED)
          LEFT JOIN photo_library pl_after
                 ON pl_after.photo_library_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.after.photo_library_id")) AS UNSIGNED)
          LEFT JOIN clients c
                 ON c.id = COALESCE(al.client_id, pl.client_id, pl_after.client_id, pl_before.client_id)
              WHERE al.asset_library_id = :asset_library_id
              LIMIT 1'
        );
        $stmt->execute([':asset_library_id' => $assetLibraryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $tokens = array_values(array_filter(array_map(
                static fn(string $part): string => trim($part),
                explode(',', $q)
            ), static fn(string $part): bool => $part !== ''));
            if (!$tokens) {
                $tokens = [$q];
            }

            $clauses = [];
            foreach ($tokens as $idx => $token) {
                $suffix = '_' . $idx;
                $numericToken = preg_replace('/^#/', '', $token);
                $clause = '(al.title LIKE :q_title' . $suffix
                    . ' OR al.tags LIKE :q_tags' . $suffix
                    . ' OR al.rel_path LIKE :q_path' . $suffix
                    . ' OR al.source_type LIKE :q_source' . $suffix
                    . ' OR acj.title LIKE :q_job_title' . $suffix
                    . ' OR p.title LIKE :q_playlist_title' . $suffix;
                if (ctype_digit($numericToken)) {
                    $clause .= ' OR al.asset_library_id = :q_id_exact' . $suffix
                        . ' OR al.legacy_photo_library_id = :q_legacy_exact' . $suffix
                        . ' OR al.source_id = :q_source_id_exact' . $suffix
                        . ' OR acj.asset_creator_job_id = :q_creator_job_exact' . $suffix
                        . ' OR p.playlist_id = :q_playlist_exact' . $suffix
                        . ' OR CAST(al.asset_library_id AS CHAR) LIKE :q_id' . $suffix;
                    $params[':q_id_exact' . $suffix] = (int)$numericToken;
                    $params[':q_legacy_exact' . $suffix] = (int)$numericToken;
                    $params[':q_source_id_exact' . $suffix] = (int)$numericToken;
                    $params[':q_creator_job_exact' . $suffix] = (int)$numericToken;
                    $params[':q_playlist_exact' . $suffix] = (int)$numericToken;
                    $params[':q_id' . $suffix] = $numericToken . '%';
                }
                $clause .= ')';
                $clauses[] = $clause;
                $params[':q_title' . $suffix] = '%' . $token . '%';
                $params[':q_tags' . $suffix] = '%' . $token . '%';
                $params[':q_path' . $suffix] = '%' . $token . '%';
                $params[':q_source' . $suffix] = '%' . $token . '%';
                $params[':q_job_title' . $suffix] = '%' . $token . '%';
                $params[':q_playlist_title' . $suffix] = '%' . $token . '%';
            }
            $where[] = '(' . implode(' AND ', $clauses) . ')';
        }

        $kind = trim((string)($filters['asset_kind'] ?? ''));
        if ($kind !== '') {
            $where[] = 'al.asset_kind = :asset_kind';
            $params[':asset_kind'] = $kind;
        }

        $sourceType = trim((string)($filters['source_type'] ?? ''));
        if ($sourceType !== '') {
            $where[] = 'al.source_type = :source_type';
            $params[':source_type'] = $sourceType;
        }

        $includeInactive = !empty($filters['include_inactive']);
        $inactiveOnly = !empty($filters['inactive_only']);
        if ($inactiveOnly) {
            $where[] = '(al.is_inactive = 1 OR al.is_retired = 1)';
        } elseif (!$includeInactive) {
            $where[] = 'al.is_inactive = 0 AND al.is_retired = 0';
        }

        $sort = (string)($filters['sort'] ?? 'newest');
        $orderBy = match ($sort) {
            'oldest' => 'al.created_at ASC, al.asset_library_id ASC',
            'title' => 'al.title ASC, al.asset_library_id DESC',
            'kind' => 'al.asset_kind ASC, al.asset_library_id DESC',
            'id_asc' => 'al.asset_library_id ASC',
            'id_desc' => 'al.asset_library_id DESC',
            default => 'al.created_at DESC, al.asset_library_id DESC',
        };

        $limit = max(1, min(300, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        if ($q === '' && $kind === '' && $sourceType === '' && !$includeInactive && !$inactiveOnly) {
            $limit = min($limit, 50);
        }

        $sql = 'SELECT al.*,
                       acj.asset_creator_job_id AS creator_job_id,
                       acj.title AS creator_job_title,
                       CASE
                           WHEN acj.source_type = \'playlist\' THEN acj.source_id
                           ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(acj.instructions_json, "$.source.playlist_id")) AS UNSIGNED)
                       END AS source_playlist_id,
                       p.title AS source_playlist_title,
                       COALESCE(
                           JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.pin_type")),
                           JSON_UNQUOTE(JSON_EXTRACT(aco.metadata_json, "$.pin_type"))
                       ) AS pin_type,
                       COALESCE(al.client_id, pl.client_id, pl_after.client_id, pl_before.client_id) AS client_id,
                       COALESCE(al.legacy_photo_library_id, pl_after.photo_library_id, pl_before.photo_library_id, pl.photo_library_id) AS permission_photo_library_id,
                       c.name AS client_name,
                       c.email AS client_email,
                       COALESCE(NULLIF(pl_after.photo_permission_status, \'\'), NULLIF(pl_before.photo_permission_status, \'\'), NULLIF(pl.photo_permission_status, \'\'), c.photo_permission_status, \'unknown\') AS photo_permission_status
                  FROM asset_library al
             LEFT JOIN photo_library pl
                    ON pl.photo_library_id = al.legacy_photo_library_id
             LEFT JOIN asset_creator_outputs aco
                    ON aco.asset_library_id = al.asset_library_id
             LEFT JOIN asset_creator_jobs acj
                    ON acj.asset_creator_job_id = COALESCE(
                       CASE WHEN al.source_type = \'asset_creator_job\' THEN al.source_id ELSE NULL END,
                       aco.asset_creator_job_id
                    )
             LEFT JOIN playlists p
                    ON p.playlist_id = CASE
                       WHEN acj.source_type = \'playlist\' THEN acj.source_id
                       ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(acj.instructions_json, "$.source.playlist_id")) AS UNSIGNED)
                    END
             LEFT JOIN photo_library pl_before
                    ON pl_before.photo_library_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.before.photo_library_id")) AS UNSIGNED)
             LEFT JOIN photo_library pl_after
                    ON pl_after.photo_library_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, "$.after.photo_library_id")) AS UNSIGNED)
             LEFT JOIN clients c
                    ON c.id = COALESCE(al.client_id, pl.client_id, pl_after.client_id, pl_before.client_id)';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findByLegacyPhotoLibraryId(int $photoLibraryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM asset_library
              WHERE legacy_photo_library_id = :legacy_photo_library_id
              LIMIT 1'
        );
        $stmt->execute([':legacy_photo_library_id' => $photoLibraryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function findLatestByRelPath(string $relPath): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM asset_library
              WHERE rel_path = :rel_path
           ORDER BY updated_at DESC, created_at DESC, asset_library_id DESC
              LIMIT 1'
        );
        $stmt->execute([':rel_path' => $relPath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO asset_library (
                legacy_photo_library_id, asset_kind, mime_type, rel_path, title, tags, alt_text, note,
                source_type, source_id, client_id, width, height, duration_seconds, file_size_bytes,
                checksum, metadata_json, is_inactive, is_retired
             ) VALUES (
                :legacy_photo_library_id, :asset_kind, :mime_type, :rel_path, :title, :tags, :alt_text, :note,
                :source_type, :source_id, :client_id, :width, :height, :duration_seconds, :file_size_bytes,
                :checksum, :metadata_json, :is_inactive, :is_retired
             )'
        );
        $stmt->execute($this->bindablePayload($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $assetLibraryId, array $data): void
    {
        $allowed = [
            'legacy_photo_library_id',
            'asset_kind',
            'mime_type',
            'rel_path',
            'title',
            'tags',
            'alt_text',
            'note',
            'source_type',
            'source_id',
            'client_id',
            'width',
            'height',
            'duration_seconds',
            'file_size_bytes',
            'checksum',
            'metadata_json',
            'is_inactive',
            'is_retired',
        ];

        $sets = [];
        $params = [':asset_library_id' => $assetLibraryId];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
            $params[":{$key}"] = $this->normalizeValue($key, $data[$key]);
        }
        if (!$sets) {
            return;
        }

        $sql = 'UPDATE asset_library SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE asset_library_id = :asset_library_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function upsertByRelPath(string $relPath, array $data): int
    {
        $existing = $this->findLatestByRelPath($relPath);
        $payload = array_merge($data, ['rel_path' => $relPath]);
        if ($existing) {
            $this->update((int)$existing['asset_library_id'], $payload);
            return (int)$existing['asset_library_id'];
        }
        return $this->create($payload);
    }

    public function retire(int $assetLibraryId): void
    {
        $this->update($assetLibraryId, ['is_retired' => 1, 'is_inactive' => 1]);
    }

    public function delete(int $assetLibraryId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM asset_library
              WHERE asset_library_id = :asset_library_id'
        );
        $stmt->execute([':asset_library_id' => $assetLibraryId]);
    }

    /**
     * @return string[]
     */
    public function hardDeleteBlockers(int $assetLibraryId): array
    {
        $blockers = [];

        if ($this->tableExists('packages')) {
            $hasPublications = $this->tableExists('published_assets');
            $publicationJoin = $hasPublications
                ? 'LEFT JOIN published_assets pub ON pub.package_id = pa.package_id'
                : '';
            $publishedConditions = [];
            if ($hasPublications) {
                $hasPublicationStatus = $this->columnExists('published_assets', 'status');
                $hasPublicationEnvironment = $this->columnExists('published_assets', 'environment');
                if ($hasPublicationStatus && $hasPublicationEnvironment) {
                    $publishedConditions[] = "(pub.status IN ('published', 'posted') AND pub.environment = 'production')";
                } elseif ($hasPublicationStatus) {
                    $publishedConditions[] = "pub.status IN ('published', 'posted')";
                } else {
                    $publishedConditions[] = 'pub.published_asset_id IS NOT NULL';
                }
            }
            if ($this->columnExists('packages', 'status')) {
                $publishedConditions[] = $this->columnExists('packages', 'environment')
                    ? "(pa.status IN ('published', 'posted') AND pa.environment = 'production')"
                    : "pa.status IN ('published', 'posted')";
            }
            if ($this->columnExists('packages', 'published_at')) {
                $publishedConditions[] = $this->columnExists('packages', 'environment')
                    ? "(pa.published_at IS NOT NULL AND pa.environment = 'production')"
                    : 'pa.published_at IS NOT NULL';
            }
            if ($this->columnExists('packages', 'locked_at')) {
                $publishedConditions[] = 'pa.locked_at IS NOT NULL';
            }
            if ($publishedConditions === []) {
                $publishedConditions[] = '0 = 1';
            }
            $publishedWhere = implode(' OR ', $publishedConditions);
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM packages pa
                   {$publicationJoin}
                  WHERE pa.asset_library_id = :id
                    AND ({$publishedWhere})"
            );
            $stmt->execute([':id' => $assetLibraryId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = "Used by {$count} published publishing asset" . ($count === 1 ? '' : 's');
            }
        }

        if ($this->tableExists('landing_pages') && $this->columnExists('landing_pages', 'featured_pin_asset_id')) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM landing_pages
                  WHERE featured_pin_asset_id = :id
                    AND status = 'public'"
            );
            $stmt->execute([':id' => $assetLibraryId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = "Used by {$count} public landing page" . ($count === 1 ? '' : 's');
            }
        }

        return $blockers;
    }

    public function hardDeleteUnpublished(int $assetLibraryId): array
    {
        $blockers = $this->hardDeleteBlockers($assetLibraryId);
        if ($blockers !== []) {
            return ['deleted' => false, 'blocked' => true, 'blockers' => $blockers];
        }

        $counts = [
            'scheduler_queue_item_attempts' => 0,
            'scheduler_queue_items' => 0,
            'published_assets' => 0,
            'publisher_attempts' => 0,
            'packages' => 0,
            'asset_creator_outputs' => 0,
            'asset_creator_inputs' => 0,
            'photo_library_detached' => 0,
            'landing_pages_detached' => 0,
            'publishing_jobs_deleted' => 0,
            'asset_library' => 0,
        ];
        $publishingJobIds = [];

        $this->pdo->beginTransaction();
        try {
            if ($this->tableExists('packages') && $this->columnExists('packages', 'package_batch_id')) {
                $stmt = $this->pdo->prepare(
                    'SELECT DISTINCT package_batch_id
                       FROM packages
                      WHERE asset_library_id = :id
                        AND package_batch_id IS NOT NULL'
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $publishingJobIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }

            if ($this->tableExists('scheduler_queue_item_attempts') && $this->tableExists('scheduler_queue_items')) {
                $stmt = $this->pdo->prepare(
                    'DELETE psa
                       FROM scheduler_queue_item_attempts psa
                       JOIN scheduler_queue_items ps
                         ON ps.queue_item_id = psa.queue_item_id
                       JOIN packages pa
                         ON pa.package_id = ps.package_id
                      WHERE pa.asset_library_id = :id'
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['scheduler_queue_item_attempts'] = $stmt->rowCount();
            }

            if ($this->tableExists('scheduler_queue_items') && $this->tableExists('packages')) {
                $stmt = $this->pdo->prepare(
                    'DELETE ps
                       FROM scheduler_queue_items ps
                       JOIN packages pa
                         ON pa.package_id = ps.package_id
                      WHERE pa.asset_library_id = :id'
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['scheduler_queue_items'] = $stmt->rowCount();
            }

            if ($this->tableExists('publisher_attempts') && $this->tableExists('packages')) {
                $stmt = $this->pdo->prepare(
                    'DELETE pat
                       FROM publisher_attempts pat
                       JOIN packages pa
                         ON pa.package_id = pat.package_id
                      WHERE pa.asset_library_id = :id'
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['publisher_attempts'] = $stmt->rowCount();
            }

            if ($this->tableExists('published_assets') && $this->tableExists('packages')) {
                $stmt = $this->pdo->prepare(
                    'DELETE pub
                       FROM published_assets pub
                       JOIN packages pa
                         ON pa.package_id = pub.package_id
                      WHERE pa.asset_library_id = :id'
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['published_assets'] = $stmt->rowCount();
            }

            if ($this->tableExists('packages')) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM packages
                      WHERE asset_library_id = :id'
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['packages'] = $stmt->rowCount();

                if ($this->tableExists('package_batches') && $publishingJobIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($publishingJobIds), '?'));
                    $stmt = $this->pdo->prepare(
                        "DELETE pj
                           FROM package_batches pj
                      LEFT JOIN packages pa
                             ON pa.package_batch_id = pj.package_batch_id
                          WHERE pj.package_batch_id IN ({$placeholders})
                            AND pa.package_id IS NULL
                            AND pj.status NOT IN ('published', 'posted')"
                    );
                    $stmt->execute($publishingJobIds);
                    $counts['publishing_jobs_deleted'] = $stmt->rowCount();
                }
            }

            if ($this->tableExists('asset_creator_outputs')) {
                $stmt = $this->pdo->prepare('DELETE FROM asset_creator_outputs WHERE asset_library_id = :id');
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['asset_creator_outputs'] = $stmt->rowCount();
            }

            if ($this->tableExists('asset_creator_inputs')) {
                $stmt = $this->pdo->prepare('DELETE FROM asset_creator_inputs WHERE asset_library_id = :id');
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['asset_creator_inputs'] = $stmt->rowCount();
            }

            if ($this->tableExists('photo_library') && $this->columnExists('photo_library', 'asset_library_id')) {
                $stmt = $this->pdo->prepare('UPDATE photo_library SET asset_library_id = NULL WHERE asset_library_id = :id');
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['photo_library_detached'] = $stmt->rowCount();
            }

            if ($this->tableExists('landing_pages') && $this->columnExists('landing_pages', 'featured_pin_asset_id')) {
                $stmt = $this->pdo->prepare(
                    "UPDATE landing_pages
                        SET featured_pin_asset_id = NULL
                      WHERE featured_pin_asset_id = :id
                        AND status <> 'public'"
                );
                $stmt->execute([':id' => $assetLibraryId]);
                $counts['landing_pages_detached'] = $stmt->rowCount();
            }

            $stmt = $this->pdo->prepare('DELETE FROM asset_library WHERE asset_library_id = :id');
            $stmt->execute([':id' => $assetLibraryId]);
            $counts['asset_library'] = $stmt->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['deleted' => $counts['asset_library'] > 0, 'blocked' => false, 'counts' => $counts];
    }

    private function bindablePayload(array $data): array
    {
        $keys = [
            'legacy_photo_library_id',
            'asset_kind',
            'mime_type',
            'rel_path',
            'title',
            'tags',
            'alt_text',
            'note',
            'source_type',
            'source_id',
            'client_id',
            'width',
            'height',
            'duration_seconds',
            'file_size_bytes',
            'checksum',
            'metadata_json',
            'is_inactive',
            'is_retired',
        ];

        $payload = [];
        foreach ($keys as $key) {
            $payload[":{$key}"] = $this->normalizeValue($key, $data[$key] ?? null);
        }
        $payload[':asset_kind'] = $payload[':asset_kind'] ?: 'image';
        $payload[':rel_path'] = (string)($payload[':rel_path'] ?? '');
        return $payload;
    }

    private function normalizeValue(string $key, mixed $value): mixed
    {
        if (in_array($key, ['legacy_photo_library_id', 'source_id', 'client_id', 'width', 'height'], true)) {
            return $value !== null && $value !== '' ? (int)$value : null;
        }
        if (in_array($key, ['file_size_bytes'], true)) {
            return $value !== null && $value !== '' ? (int)$value : null;
        }
        if ($key === 'duration_seconds') {
            return $value !== null && $value !== '' ? (float)$value : null;
        }
        if (in_array($key, ['is_inactive', 'is_retired'], true)) {
            return !empty($value) ? 1 : 0;
        }
        if ($key === 'metadata_json' && is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if ($value === null) {
            return null;
        }
        $string = trim((string)$value);
        return $string !== '' ? $string : null;
    }

    private function normalizeRow(array $row): array
    {
        foreach (['asset_library_id', 'legacy_photo_library_id', 'permission_photo_library_id', 'source_id', 'creator_job_id', 'source_playlist_id', 'client_id', 'width', 'height', 'file_size_bytes'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        if (array_key_exists('duration_seconds', $row) && $row['duration_seconds'] !== null) {
            $row['duration_seconds'] = (float)$row['duration_seconds'];
        }
        foreach (['is_inactive', 'is_retired'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int)$row[$key] === 1;
            }
        }
        return $row;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
