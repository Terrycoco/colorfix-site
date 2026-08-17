<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

use PDO;
use RuntimeException;

/**
 * PUB VIDEO RENDER JOB REPOSITORY
 *
 * Owns ALL database access for pub_render_jobs.
 *
 * This repository exists only to support CREATE-stage
 * video rendering.
 *
 * Responsibilities:
 *   - create render jobs
 *   - claim the next queued render job
 *   - mark jobs rendering
 *   - mark jobs complete
 *   - mark jobs failed
 *   - retrieve jobs by ID
 *
 * Must NOT:
 *   - render video
 *   - know Remotion
 *   - make CREATE decisions
 *   - know anything about PACKAGE
 *   - know anything about the publication Queue
 *   - schedule or publish
 */
final class PdoRenderJobRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function createJob(
        string $creatorKey,
        string $compositionKey,
        array $props,
        string $outputMimeType = 'video/mp4'
    ): array {
        $propsJson = json_encode(
            $props,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        $stmt = $this->pdo->prepare(
            '
            INSERT INTO pub_render_jobs
            (
                creator_key,
                composition_key,
                status,
                props_json,
                output_mime_type
            )
            VALUES
            (
                :creator_key,
                :composition_key,
                :status,
                :props_json,
                :output_mime_type
            )
            '
        );

        $stmt->execute([
            ':creator_key' => $creatorKey,
            ':composition_key' => $compositionKey,
            ':status' => 'queued',
            ':props_json' => $propsJson,
            ':output_mime_type' => $outputMimeType,
        ]);

        $jobId = (int)$this->pdo->lastInsertId();

        if ($jobId <= 0) {
            throw new RuntimeException(
                'Render job was not created.'
            );
        }

        $job = $this->findById($jobId);

        if (!$job) {
            throw new RuntimeException(
                'Created render job could not be loaded.'
            );
        }

        return $job;
    }

    public function claimNextQueuedJob(): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->query(
                "
                SELECT
                    pub_render_job_id,
                    creator_key,
                    composition_key,
                    status,
                    props_json,
                    output_mime_type,
                    output_rel_path,
                    output_file_size_bytes,
                    error_message,
                    claimed_at,
                    completed_at,
                    created_at,
                    updated_at
                FROM pub_render_jobs
                WHERE status = 'queued'
                ORDER BY created_at ASC, pub_render_job_id ASC
                LIMIT 1
                FOR UPDATE
                "
            );

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->pdo->commit();
                return null;
            }

            $jobId = (int)$row['pub_render_job_id'];

            $update = $this->pdo->prepare(
                "
                UPDATE pub_render_jobs
                SET
                    status = 'claimed',
                    claimed_at = NOW()
                WHERE pub_render_job_id = :job_id
                  AND status = 'queued'
                "
            );

            $update->execute([
                ':job_id' => $jobId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Render job could not be claimed.'
                );
            }

            $this->pdo->commit();

            return $this->findById($jobId);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function markRendering(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "
            UPDATE pub_render_jobs
            SET status = 'rendering'
            WHERE pub_render_job_id = :job_id
              AND status = 'claimed'
            "
        );

        $stmt->execute([
            ':job_id' => $jobId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Render job could not be marked rendering.'
            );
        }
    }

    public function completeJob(
        int $jobId,
        string $outputRelPath,
        ?int $outputFileSizeBytes = null
    ): void {
        $stmt = $this->pdo->prepare(
            "
            UPDATE pub_render_jobs
            SET
                status = 'complete',
                output_rel_path = :output_rel_path,
                output_file_size_bytes = :output_file_size_bytes,
                error_message = NULL,
                completed_at = NOW()
            WHERE pub_render_job_id = :job_id
              AND status IN ('claimed', 'rendering')
            "
        );

        $stmt->execute([
            ':output_rel_path' => $outputRelPath,
            ':output_file_size_bytes' => $outputFileSizeBytes,
            ':job_id' => $jobId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Render job could not be completed.'
            );
        }
    }

    public function failJob(
        int $jobId,
        string $errorMessage
    ): void {
        $stmt = $this->pdo->prepare(
            "
            UPDATE pub_render_jobs
            SET
                status = 'failed',
                error_message = :error_message,
                completed_at = NOW()
            WHERE pub_render_job_id = :job_id
              AND status IN ('claimed', 'rendering')
            "
        );

        $stmt->execute([
            ':error_message' => $errorMessage,
            ':job_id' => $jobId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Render job could not be failed.'
            );
        }
    }

    public function findById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "
            SELECT
                pub_render_job_id,
                creator_key,
                composition_key,
                status,
                props_json,
                output_mime_type,
                output_rel_path,
                output_file_size_bytes,
                error_message,
                claimed_at,
                completed_at,
                created_at,
                updated_at
            FROM pub_render_jobs
            WHERE pub_render_job_id = :job_id
            LIMIT 1
            "
        );

        $stmt->execute([
            ':job_id' => $jobId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    private function hydrate(array $row): array
    {
        $props = json_decode(
            (string)($row['props_json'] ?? '{}'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return [
            'pub_render_job_id' =>
                (int)$row['pub_render_job_id'],

            'creator_key' =>
                (string)$row['creator_key'],

            'composition_key' =>
                (string)$row['composition_key'],

            'status' =>
                (string)$row['status'],

            'props' =>
                is_array($props) ? $props : [],

            'output_mime_type' =>
                (string)$row['output_mime_type'],

            'output_rel_path' =>
                $row['output_rel_path'] !== null
                    ? (string)$row['output_rel_path']
                    : null,

            'output_file_size_bytes' =>
                $row['output_file_size_bytes'] !== null
                    ? (int)$row['output_file_size_bytes']
                    : null,

            'error_message' =>
                $row['error_message'] !== null
                    ? (string)$row['error_message']
                    : null,

            'claimed_at' =>
                $row['claimed_at'] ?? null,

            'completed_at' =>
                $row['completed_at'] ?? null,

            'created_at' =>
                $row['created_at'] ?? null,

            'updated_at' =>
                $row['updated_at'] ?? null,
        ];
    }
}