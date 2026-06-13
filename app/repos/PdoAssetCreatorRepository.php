<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoAssetCreatorRepository
{
    public function __construct(private PDO $pdo) {}

    public function listJobs(array $filters = []): array
    {
        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(acj.title LIKE :q OR acj.creator_key LIKE :q OR CAST(acj.asset_creator_job_id AS CHAR) LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $creatorKey = trim((string)($filters['creator_key'] ?? ''));
        if ($creatorKey !== '') {
            $where[] = 'acj.creator_key = :creator_key';
            $params[':creator_key'] = $creatorKey;
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'acj.status = :status';
            $params[':status'] = $status;
        }

        $sql = 'SELECT acj.*,
                       COUNT(DISTINCT aci.asset_creator_input_id) AS input_count,
                       COUNT(DISTINCT aco.asset_creator_output_id) AS output_count,
                       MAX(aco.asset_creator_output_id) AS latest_output_id
                  FROM asset_creator_jobs acj
             LEFT JOIN asset_creator_inputs aci
                    ON aci.asset_creator_job_id = acj.asset_creator_job_id
             LEFT JOIN asset_creator_outputs aco
                    ON aco.asset_creator_job_id = acj.asset_creator_job_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY acj.asset_creator_job_id
                  ORDER BY acj.updated_at DESC, acj.asset_creator_job_id DESC
                  LIMIT 200';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'normalizeJobRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM asset_creator_jobs WHERE asset_creator_job_id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return null;
        }

        $job = $this->normalizeJobRow($job);
        $job['inputs'] = $this->listInputs($jobId);
        $job['outputs'] = $this->listOutputs($jobId);
        return $job;
    }

    public function findJobsForAsset(int $assetLibraryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT acj.*
               FROM asset_creator_jobs acj
          LEFT JOIN asset_creator_inputs aci
                 ON aci.asset_creator_job_id = acj.asset_creator_job_id
          LEFT JOIN asset_creator_outputs aco
                 ON aco.asset_creator_job_id = acj.asset_creator_job_id
              WHERE aci.asset_library_id = :asset_library_id
                 OR aco.asset_library_id = :asset_library_id
           ORDER BY acj.updated_at DESC, acj.asset_creator_job_id DESC'
        );
        $stmt->execute([':asset_library_id' => $assetLibraryId]);
        return array_map([$this, 'normalizeJobRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function createJob(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO asset_creator_jobs
                (creator_key, source_type, source_id, playlist_instance_id, title, status, instructions_json, notes)
             VALUES
                (:creator_key, :source_type, :source_id, :playlist_instance_id, :title, :status, :instructions_json, :notes)'
        );
        $stmt->execute([
            ':creator_key' => trim((string)$data['creator_key']),
            ':source_type' => $this->nullableString($data['source_type'] ?? null),
            ':source_id' => $this->nullableInt($data['source_id'] ?? null),
            ':playlist_instance_id' => $this->nullableInt($data['playlist_instance_id'] ?? null),
            ':title' => $this->nullableString($data['title'] ?? null),
            ':status' => $this->nullableString($data['status'] ?? null) ?: 'draft',
            ':instructions_json' => $this->jsonValue($data['instructions_json'] ?? null),
            ':notes' => $this->nullableString($data['notes'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateJob(int $jobId, array $data): void
    {
        $allowed = ['creator_key', 'source_type', 'source_id', 'playlist_instance_id', 'title', 'status', 'instructions_json', 'notes', 'last_run_at'];
        $sets = [];
        $params = [':asset_creator_job_id' => $jobId];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
            $params[":{$key}"] = match ($key) {
                'source_id', 'playlist_instance_id' => $this->nullableInt($data[$key]),
                'instructions_json' => $this->jsonValue($data[$key]),
                default => $this->nullableString($data[$key]),
            };
        }
        if (!$sets) {
            return;
        }
        $sql = 'UPDATE asset_creator_jobs SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE asset_creator_job_id = :asset_creator_job_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function replaceInputs(int $jobId, array $inputs): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM asset_creator_inputs WHERE asset_creator_job_id = :job_id');
            $delete->execute([':job_id' => $jobId]);
            foreach ($inputs as $input) {
                $this->addInput($jobId, $input);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function addInput(int $jobId, array $input): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO asset_creator_inputs
                (asset_creator_job_id, asset_library_id, role, sort_order, metadata_json)
             VALUES
                (:asset_creator_job_id, :asset_library_id, :role, :sort_order, :metadata_json)'
        );
        $stmt->execute([
            ':asset_creator_job_id' => $jobId,
            ':asset_library_id' => $this->nullableInt($input['asset_library_id'] ?? null),
            ':role' => $this->nullableString($input['role'] ?? null) ?: 'input',
            ':sort_order' => isset($input['sort_order']) ? (float)$input['sort_order'] : 0,
            ':metadata_json' => $this->jsonValue($input['metadata_json'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addOutput(int $jobId, array $output): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO asset_creator_outputs
                (asset_creator_job_id, asset_library_id, role, status, generated_at, metadata_json)
             VALUES
                (:asset_creator_job_id, :asset_library_id, :role, :status, :generated_at, :metadata_json)'
        );
        $stmt->execute([
            ':asset_creator_job_id' => $jobId,
            ':asset_library_id' => (int)($output['asset_library_id'] ?? 0),
            ':role' => $this->nullableString($output['role'] ?? null) ?: 'output',
            ':status' => $this->nullableString($output['status'] ?? null) ?: 'created',
            ':generated_at' => $this->nullableString($output['generated_at'] ?? null),
            ':metadata_json' => $this->jsonValue($output['metadata_json'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function replaceOutputs(int $jobId, array $outputs): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM asset_creator_outputs WHERE asset_creator_job_id = :job_id');
            $delete->execute([':job_id' => $jobId]);
            foreach ($outputs as $output) {
                $this->addOutput($jobId, $output);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function listInputs(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aci.*, al.rel_path, al.title, al.asset_kind, al.mime_type
               FROM asset_creator_inputs aci
          LEFT JOIN asset_library al
                 ON al.asset_library_id = aci.asset_library_id
              WHERE aci.asset_creator_job_id = :job_id
           ORDER BY aci.sort_order ASC, aci.asset_creator_input_id ASC'
        );
        $stmt->execute([':job_id' => $jobId]);
        return array_map([$this, 'normalizeInputRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function listOutputs(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aco.*, al.rel_path, al.title, al.asset_kind, al.mime_type
               FROM asset_creator_outputs aco
               JOIN asset_library al
                 ON al.asset_library_id = aco.asset_library_id
              WHERE aco.asset_creator_job_id = :job_id
           ORDER BY aco.asset_creator_output_id ASC'
        );
        $stmt->execute([':job_id' => $jobId]);
        return array_map([$this, 'normalizeOutputRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function normalizeJobRow(array $row): array
    {
        foreach (['asset_creator_job_id', 'source_id', 'playlist_instance_id', 'input_count', 'output_count', 'latest_output_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        return $row;
    }

    private function normalizeInputRow(array $row): array
    {
        foreach (['asset_creator_input_id', 'asset_creator_job_id', 'asset_library_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        if (array_key_exists('sort_order', $row)) {
            $row['sort_order'] = (float)$row['sort_order'];
        }
        return $row;
    }

    private function normalizeOutputRow(array $row): array
    {
        foreach (['asset_creator_output_id', 'asset_creator_job_id', 'asset_library_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        if (!empty($row['rel_path'])) {
            $row['public_url'] = $this->publicUrlForRelPath((string)$row['rel_path']);
        }
        return $row;
    }

    private function publicUrlForRelPath(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '' || preg_match('/^https?:\/\//i', $relPath)) {
            return $relPath;
        }
        return '/' . ltrim($relPath, '/');
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string !== '' ? $string : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int)$value : null;
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    }
}
