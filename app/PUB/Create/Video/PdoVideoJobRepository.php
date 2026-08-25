<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

use PDO;
use RuntimeException;
use Throwable;

/**
 * PUB VIDEO JOB REPOSITORY
 *
 * Owns ALL database access for pub_video_jobs.
 *
 * This repository is renderer-neutral.
 *
 * It does NOT know whether the current renderer is:
 *   - Remotion on Terry's Mac
 *   - Remotion on the server
 *   - FFmpeg
 *   - some future video engine
 *
 * Responsibilities:
 *   - create video jobs
 *   - link production jobs to pub_assets
 *   - claim the next queued video job
 *   - mark jobs rendering
 *   - mark jobs complete
 *   - mark jobs failed
 *   - retrieve jobs by ID
 *
 * Must NOT:
 *   - render video
 *   - know Remotion composition names
 *   - make CREATE decisions
 *   - know anything about PACKAGE
 *   - schedule
 *   - publish
 */
final class PdoVideoJobRepository
{
    public function __construct(
        private PDO $pdo
    ) {}


    public function createJob(
        ?int $pubAssetId,
        string $creatorKey,
        string $videoRecipeKey,
        array $props,
        string $outputMimeType = 'video/mp4'
    ): array {
        if (
            $pubAssetId !== null
            && $pubAssetId <= 0
        ) {
            throw new RuntimeException(
                'Video job pub_asset_id must be positive when supplied.'
            );
        }

        $creatorKey =
            trim(
                $creatorKey
            );

        if ($creatorKey === '') {
            throw new RuntimeException(
                'Video job requires creator_key.'
            );
        }

        $videoRecipeKey =
            trim(
                $videoRecipeKey
            );

        if ($videoRecipeKey === '') {
            throw new RuntimeException(
                'Video job requires video_recipe_key.'
            );
        }

        $outputMimeType =
            trim(
                $outputMimeType
            );

        if ($outputMimeType === '') {
            throw new RuntimeException(
                'Video job requires output_mime_type.'
            );
        }


        $propsJson =
            json_encode(
                $props,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );


        $stmt =
            $this->pdo
                ->prepare(
                    '
                    INSERT INTO pub_video_jobs
                    (
                        pub_asset_id,
                        creator_key,
                        video_recipe_key,
                        status,
                        props_json,
                        output_mime_type
                    )
                    VALUES
                    (
                        :pub_asset_id,
                        :creator_key,
                        :video_recipe_key,
                        :status,
                        :props_json,
                        :output_mime_type
                    )
                    '
                );


        $stmt->execute([
            ':pub_asset_id' =>
                $pubAssetId,

            ':creator_key' =>
                $creatorKey,

            ':video_recipe_key' =>
                $videoRecipeKey,

            ':status' =>
                'queued',

            ':props_json' =>
                $propsJson,

            ':output_mime_type' =>
                $outputMimeType,
        ]);


        $jobId =
            (int)$this->pdo
                ->lastInsertId();


        if ($jobId <= 0) {
            throw new RuntimeException(
                'Video job was not created.'
            );
        }


        $job =
            $this->findById(
                $jobId
            );


        if (!$job) {
            throw new RuntimeException(
                'Created video job could not be loaded.'
            );
        }


        return $job;
    }


    public function claimNextQueuedJob(): ?array
    {
        $this->pdo
            ->beginTransaction();


        try {
            $stmt =
                $this->pdo
                    ->query(
                        "
                        SELECT
                            pub_video_job_id,
                            pub_asset_id,
                            creator_key,
                            video_recipe_key,
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
                        FROM pub_video_jobs
                        WHERE status = 'queued'
                        ORDER BY
                            created_at ASC,
                            pub_video_job_id ASC
                        LIMIT 1
                        FOR UPDATE
                        "
                    );


            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$row) {
                $this->pdo
                    ->commit();

                return null;
            }


            $jobId =
                (int)$row[
                    'pub_video_job_id'
                ];


            $update =
                $this->pdo
                    ->prepare(
                        "
                        UPDATE pub_video_jobs
                        SET
                            status = 'claimed',
                            claimed_at = NOW()
                        WHERE pub_video_job_id = :job_id
                          AND status = 'queued'
                        "
                    );


            $update->execute([
                ':job_id' =>
                    $jobId,
            ]);


            if (
                $update->rowCount()
                !== 1
            ) {
                throw new RuntimeException(
                    'Video job could not be claimed.'
                );
            }


            $this->pdo
                ->commit();


            return $this->findById(
                $jobId
            );

        } catch (Throwable $e) {
            if (
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }


            throw $e;
        }
    }


    /**
     * Fail any unfinished video jobs already attached to one PUB asset.
     *
     * This is used before REDO queues a replacement job so an older
     * worker result can never arrive later and overwrite the newer work.
     */
    public function failActiveJobsForAsset(
        int $pubAssetId,
        string $errorMessage
    ): int {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $errorMessage =
            trim(
                $errorMessage
            );

        if ($errorMessage === '') {
            $errorMessage =
                'Video job superseded.';
        }


        $stmt =
            $this->pdo
                ->prepare(
                    "
                    UPDATE pub_video_jobs
                    SET
                        status = 'failed',
                        error_message = :error_message,
                        completed_at = NOW()
                    WHERE pub_asset_id = :pub_asset_id
                      AND status IN ('queued', 'claimed', 'rendering')
                    "
                );


        $stmt->execute([
            ':error_message' =>
                $errorMessage,

            ':pub_asset_id' =>
                $pubAssetId,
        ]);


        return $stmt->rowCount();
    }


    public function markRendering(
        int $jobId
    ): void {
        $stmt =
            $this->pdo
                ->prepare(
                    "
                    UPDATE pub_video_jobs
                    SET
                        status = 'rendering'
                    WHERE pub_video_job_id = :job_id
                      AND status = 'claimed'
                    "
                );


        $stmt->execute([
            ':job_id' =>
                $jobId,
        ]);


        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'Video job could not be marked rendering.'
            );
        }
    }


    public function completeJob(
        int $jobId,
        string $outputRelPath,
        ?int $outputFileSizeBytes = null
    ): void {
        $outputRelPath =
            trim(
                $outputRelPath
            );

        if ($outputRelPath === '') {
            throw new RuntimeException(
                'Completed video job requires output_rel_path.'
            );
        }


        $stmt =
            $this->pdo
                ->prepare(
                    "
                    UPDATE pub_video_jobs
                    SET
                        status = 'complete',
                        output_rel_path = :output_rel_path,
                        output_file_size_bytes = :output_file_size_bytes,
                        error_message = NULL,
                        completed_at = NOW()
                    WHERE pub_video_job_id = :job_id
                      AND status IN ('claimed', 'rendering')
                    "
                );


        $stmt->execute([
            ':output_rel_path' =>
                $outputRelPath,

            ':output_file_size_bytes' =>
                $outputFileSizeBytes,

            ':job_id' =>
                $jobId,
        ]);


        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'Video job could not be completed.'
            );
        }
    }


    public function failJob(
        int $jobId,
        string $errorMessage
    ): void {
        $errorMessage =
            trim(
                $errorMessage
            );

        if ($errorMessage === '') {
            $errorMessage =
                'Video rendering failed.';
        }


        $stmt =
            $this->pdo
                ->prepare(
                    "
                    UPDATE pub_video_jobs
                    SET
                        status = 'failed',
                        error_message = :error_message,
                        completed_at = NOW()
                    WHERE pub_video_job_id = :job_id
                      AND status IN ('claimed', 'rendering')
                    "
                );


        $stmt->execute([
            ':error_message' =>
                $errorMessage,

            ':job_id' =>
                $jobId,
        ]);


        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'Video job could not be failed.'
            );
        }
    }


    public function findById(
        int $jobId
    ): ?array {
        $stmt =
            $this->pdo
                ->prepare(
                    "
                    SELECT
                        pub_video_job_id,
                        pub_asset_id,
                        creator_key,
                        video_recipe_key,
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
                    FROM pub_video_jobs
                    WHERE pub_video_job_id = :job_id
                    LIMIT 1
                    "
                );


        $stmt->execute([
            ':job_id' =>
                $jobId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$row) {
            return null;
        }


        return $this->hydrate(
            $row
        );
    }


    private function hydrate(
        array $row
    ): array {
        $props =
            json_decode(
                (string)(
                    $row[
                        'props_json'
                    ]
                    ?? '{}'
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );


        return [
            'pub_video_job_id' =>
                (int)$row[
                    'pub_video_job_id'
                ],

            'pub_asset_id' =>
                $row[
                    'pub_asset_id'
                ] !== null
                    ? (int)$row[
                        'pub_asset_id'
                    ]
                    : null,

            'creator_key' =>
                (string)$row[
                    'creator_key'
                ],

            'video_recipe_key' =>
                (string)$row[
                    'video_recipe_key'
                ],

            'status' =>
                (string)$row[
                    'status'
                ],

            'props' =>
                is_array(
                    $props
                )
                    ? $props
                    : [],

            'output_mime_type' =>
                (string)$row[
                    'output_mime_type'
                ],

            'output_rel_path' =>
                $row[
                    'output_rel_path'
                ] !== null
                    ? (string)$row[
                        'output_rel_path'
                    ]
                    : null,

            'output_file_size_bytes' =>
                $row[
                    'output_file_size_bytes'
                ] !== null
                    ? (int)$row[
                        'output_file_size_bytes'
                    ]
                    : null,

            'error_message' =>
                $row[
                    'error_message'
                ] !== null
                    ? (string)$row[
                        'error_message'
                    ]
                    : null,

            'claimed_at' =>
                $row[
                    'claimed_at'
                ]
                ?? null,

            'completed_at' =>
                $row[
                    'completed_at'
                ]
                ?? null,

            'created_at' =>
                $row[
                    'created_at'
                ]
                ?? null,

            'updated_at' =>
                $row[
                    'updated_at'
                ]
                ?? null,
        ];
    }
}
