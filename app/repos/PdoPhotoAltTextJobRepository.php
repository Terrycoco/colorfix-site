<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoPhotoAltTextJobRepository
{
    public function __construct(private PDO $pdo) {}

    public function enqueue(int $photoLibraryId, bool $force = false): void
    {
        if ($photoLibraryId <= 0) {
            return;
        }

        $sql = $force
            ? "INSERT INTO photo_alt_text_jobs
                    (photo_library_id, status, attempts, next_attempt_at, locked_at, last_error, completed_at, created_at)
               VALUES
                    (:photo_library_id, 'pending', 0, NOW(), NULL, NULL, NULL, NOW())
               ON DUPLICATE KEY UPDATE
                    status = 'pending',
                    attempts = 0,
                    provider = 'gemini',
                    provider_attempts = 0,
                    first_attempt_at = NULL,
                    next_attempt_at = NOW(),
                    locked_at = NULL,
                    last_error = NULL,
                    completed_at = NULL"
            : "INSERT INTO photo_alt_text_jobs
                    (photo_library_id, status, attempts, next_attempt_at, created_at)
               VALUES
                    (:photo_library_id, 'pending', 0, NOW(), NOW())
               ON DUPLICATE KEY UPDATE
                    status = IF(status = 'complete', status, 'pending'),
                    next_attempt_at = IF(status = 'complete', next_attempt_at, NOW()),
                    locked_at = IF(status = 'complete', locked_at, NULL)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':photo_library_id' => $photoLibraryId]);
    }

    public function enqueueMissing(int $limit = 200): int
    {
        $limit = max(1, min(1000, $limit));
        $sql = "SELECT pl.photo_library_id
                  FROM photo_library pl
             LEFT JOIN photo_alt_text_jobs j
                    ON j.photo_library_id = pl.photo_library_id
                 WHERE pl.is_inactive = 0
                   AND pl.rel_path IS NOT NULL
                   AND pl.rel_path <> ''
                   AND (pl.alt_text IS NULL OR TRIM(pl.alt_text) = '')
                   AND (j.job_id IS NULL OR j.status IN ('failed', 'pending'))
              ORDER BY pl.updated_at DESC, pl.created_at DESC, pl.photo_library_id DESC
                 LIMIT {$limit}";
        $ids = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            $this->enqueue((int)$id);
        }
        return count($ids);
    }

    public function claimReadyJobs(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $stmt = $this->pdo->query(
            "SELECT j.job_id
               FROM photo_alt_text_jobs j
               JOIN photo_library pl
                 ON pl.photo_library_id = j.photo_library_id
              WHERE j.status IN ('pending', 'retry')
                AND j.attempts < j.max_attempts
                AND j.next_attempt_at <= NOW()
                AND (j.locked_at IS NULL OR j.locked_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                AND pl.is_inactive = 0
              ORDER BY CASE WHEN j.provider = 'openai' THEN 0 ELSE 1 END ASC,
                       j.next_attempt_at ASC,
                       j.job_id ASC
              LIMIT {$limit}"
        );
        $jobIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $jobs = [];
        foreach ($jobIds as $jobId) {
            $updated = $this->markProcessing($jobId);
            if (!$updated) {
                continue;
            }
            $job = $this->findJobWithPhoto($jobId);
            if ($job) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    public function complete(int $jobId, int $photoLibraryId, string $altText, array $metadata, string $model, string $filenameSlug = ''): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE photo_library
                    SET ai_alt_text = :ai_alt_text,
                        ai_filename_slug = :ai_filename_slug,
                        ai_alt_metadata_json = :metadata,
                        ai_alt_model = :model,
                        ai_alt_generated_at = NOW(),
                        alt_text = CASE
                            WHEN alt_text IS NULL OR TRIM(alt_text) = '' THEN :alt_text
                            ELSE alt_text
                        END,
                        updated_at = NOW()
                  WHERE photo_library_id = :photo_library_id"
            );
            $stmt->execute([
                ':ai_alt_text' => $altText,
                ':ai_filename_slug' => $filenameSlug !== '' ? $filenameSlug : null,
                ':metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':model' => $model,
                ':alt_text' => $altText,
                ':photo_library_id' => $photoLibraryId,
            ]);

            $stmt = $this->pdo->prepare(
                "UPDATE photo_alt_text_jobs
                    SET status = 'complete',
                        locked_at = NULL,
                        last_error = NULL,
                        completed_at = NOW(),
                        updated_at = NOW()
                  WHERE job_id = :job_id"
            );
            $stmt->execute([':job_id' => $jobId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function fail(int $jobId, string $error, string $provider): void
    {
        $error = mb_substr($error, 0, 2000);
        $delaySql = $provider === 'openai'
            ? "CASE
                    WHEN provider_attempts + 1 <= 1 THEN 30
                    WHEN provider_attempts + 1 <= 3 THEN 120
                    ELSE 360
               END"
            : "CASE
                    WHEN attempts + 1 <= 1 THEN 15
                    WHEN attempts + 1 <= 3 THEN 60
                    WHEN attempts + 1 <= 6 THEN 240
                    ELSE 720
               END";
        $stmt = $this->pdo->prepare(
            "UPDATE photo_alt_text_jobs
                SET attempts = attempts + 1,
                    provider_attempts = CASE WHEN provider = :provider THEN provider_attempts + 1 ELSE provider_attempts END,
                    status = CASE WHEN attempts + 1 >= max_attempts THEN 'failed' ELSE 'retry' END,
                    next_attempt_at = DATE_ADD(NOW(), INTERVAL {$delaySql} MINUTE),
                    locked_at = NULL,
                    last_error = :last_error,
                    updated_at = NOW()
              WHERE job_id = :job_id"
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':last_error' => $error,
            ':provider' => $provider,
        ]);
    }

    public function failAndFallbackToOpenAi(int $jobId, string $error): void
    {
        $error = mb_substr($error, 0, 2000);
        $stmt = $this->pdo->prepare(
            "UPDATE photo_alt_text_jobs
                SET attempts = attempts + 1,
                    provider = 'openai',
                    provider_attempts = 0,
                    status = 'retry',
                    next_attempt_at = NOW(),
                    locked_at = NULL,
                    last_error = :last_error,
                    updated_at = NOW()
              WHERE job_id = :job_id"
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':last_error' => $error,
        ]);
    }

    public function stats(): array
    {
        $rows = $this->pdo->query(
            "SELECT status, COUNT(*) AS count
               FROM photo_alt_text_jobs
              GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stats = ['pending' => 0, 'retry' => 0, 'processing' => 0, 'complete' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats[(string)$row['status']] = (int)$row['count'];
        }
        return $stats;
    }

    private function markProcessing(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE photo_alt_text_jobs
                SET status = 'processing',
                    locked_at = NOW(),
                    first_attempt_at = COALESCE(first_attempt_at, NOW()),
                    updated_at = NOW()
              WHERE job_id = :job_id
                AND status IN ('pending', 'retry')
                AND attempts < max_attempts
                AND next_attempt_at <= NOW()
                AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))"
        );
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    private function findJobWithPhoto(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.job_id,
                    j.photo_library_id,
                    j.attempts,
                    j.provider,
                    j.provider_attempts,
                    j.first_attempt_at,
                    j.created_at,
                    pl.rel_path,
                    pl.title,
                    pl.tags,
                    pl.alt_text
               FROM photo_alt_text_jobs j
               JOIN photo_library pl
                 ON pl.photo_library_id = j.photo_library_id
              WHERE j.job_id = :job_id
              LIMIT 1"
        );
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
