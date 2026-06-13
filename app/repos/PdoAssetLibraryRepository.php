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
            'SELECT *
               FROM asset_library
              WHERE asset_library_id = :asset_library_id
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
                $clause = '(title LIKE :q_title' . $suffix
                    . ' OR tags LIKE :q_tags' . $suffix
                    . ' OR rel_path LIKE :q_path' . $suffix
                    . ' OR source_type LIKE :q_source' . $suffix;
                if (ctype_digit($numericToken)) {
                    $clause .= ' OR asset_library_id = :q_id_exact' . $suffix
                        . ' OR legacy_photo_library_id = :q_legacy_exact' . $suffix
                        . ' OR CAST(asset_library_id AS CHAR) LIKE :q_id' . $suffix;
                    $params[':q_id_exact' . $suffix] = (int)$numericToken;
                    $params[':q_legacy_exact' . $suffix] = (int)$numericToken;
                    $params[':q_id' . $suffix] = $numericToken . '%';
                }
                $clause .= ')';
                $clauses[] = $clause;
                $params[':q_title' . $suffix] = '%' . $token . '%';
                $params[':q_tags' . $suffix] = '%' . $token . '%';
                $params[':q_path' . $suffix] = '%' . $token . '%';
                $params[':q_source' . $suffix] = '%' . $token . '%';
            }
            $where[] = '(' . implode(' AND ', $clauses) . ')';
        }

        $kind = trim((string)($filters['asset_kind'] ?? ''));
        if ($kind !== '') {
            $where[] = 'asset_kind = :asset_kind';
            $params[':asset_kind'] = $kind;
        }

        $sourceType = trim((string)($filters['source_type'] ?? ''));
        if ($sourceType !== '') {
            $where[] = 'source_type = :source_type';
            $params[':source_type'] = $sourceType;
        }

        $includeInactive = !empty($filters['include_inactive']);
        $inactiveOnly = !empty($filters['inactive_only']);
        if ($inactiveOnly) {
            $where[] = '(is_inactive = 1 OR is_retired = 1)';
        } elseif (!$includeInactive) {
            $where[] = 'is_inactive = 0 AND is_retired = 0';
        }

        $sort = (string)($filters['sort'] ?? 'newest');
        $orderBy = match ($sort) {
            'oldest' => 'created_at ASC, asset_library_id ASC',
            'title' => 'title ASC, asset_library_id DESC',
            'kind' => 'asset_kind ASC, asset_library_id DESC',
            'id_asc' => 'asset_library_id ASC',
            'id_desc' => 'asset_library_id DESC',
            default => 'created_at DESC, asset_library_id DESC',
        };

        $limit = max(1, min(300, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        if ($q === '' && $kind === '' && $sourceType === '' && !$includeInactive && !$inactiveOnly) {
            $limit = min($limit, 50);
        }

        $sql = 'SELECT *
                  FROM asset_library';
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
        foreach (['asset_library_id', 'legacy_photo_library_id', 'source_id', 'client_id', 'width', 'height', 'file_size_bytes'] as $key) {
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
}
