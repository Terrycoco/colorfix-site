<?php
declare(strict_types=1);

namespace App\PUB\Repos;

use PDO;
use RuntimeException;

/**
 * PUB RUN REPOSITORY
 *
 * Persistence layer for PUB production runs.
 *
 * A run represents one requested batch of PUB output:
 *
 *   source_type + source_id + output_type
 *
 * The run records how many boxes were expected, how many
 * successfully came back, and how many failed.
 *
 * Business logic belongs in PubRunService.
 */
final class PdoPubRunRepository
{
    private const FINAL_STATUSES = [
        'ready',
        'partial',
        'failed',
    ];


    public function __construct(
        private PDO $pdo
    ) {}


    /**
     * Start a new PUB run.
     */
    public function create(
        string $sourceType,
        int $sourceId,
        string $outputType,
        int $expectedCount = 0
    ): int {
        $sourceType =
            trim($sourceType);

        $outputType =
            trim($outputType);

        if ($sourceType === '') {
            throw new RuntimeException(
                'PUB run source_type is required.'
            );
        }

        if ($sourceId <= 0) {
            throw new RuntimeException(
                'PUB run source_id must be greater than zero.'
            );
        }

        if ($outputType === '') {
            throw new RuntimeException(
                'PUB run output_type is required.'
            );
        }

        if ($expectedCount < 0) {
            throw new RuntimeException(
                'PUB run expected_count cannot be negative.'
            );
        }


        $sql = <<<SQL
            INSERT INTO pub_runs (
                source_type,
                source_id,
                output_type,
                status,
                expected_count,
                actual_count,
                failed_count
            ) VALUES (
                :source_type,
                :source_id,
                :output_type,
                'preparing',
                :expected_count,
                0,
                0
            )
            SQL;


        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute([
            'source_type' =>
                $sourceType,

            'source_id' =>
                $sourceId,

            'output_type' =>
                $outputType,

            'expected_count' =>
                $expectedCount,
        ]);


        return (int)
            $this->pdo->lastInsertId();
    }


    /**
     * Fetch one run.
     */
    public function findById(
        int $pubRunId
    ): ?array {
        if ($pubRunId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_run_id,
                    source_type,
                    source_id,
                    output_type,
                    status,
                    expected_count,
                    actual_count,
                    failed_count,
                    created_at,
                    completed_at
                FROM pub_runs
                WHERE pub_run_id = :pub_run_id
                LIMIT 1
                SQL
            );

        $stmt->execute([
            'pub_run_id' =>
                $pubRunId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row ?: null;
    }


    /**
     * Find the newest job for the exact same source + output.
     *
     * ANALYZE uses this before opening another run.
     */
    public function findLatestMatching(
        string $sourceType,
        int $sourceId,
        string $outputType
    ): ?array {
        $sourceType =
            strtolower(
                trim($sourceType)
            );

        $outputType =
            strtolower(
                trim($outputType)
            );

        if (
            $sourceType === ''
            || $sourceId <= 0
            || $outputType === ''
        ) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_run_id,
                    source_type,
                    source_id,
                    output_type,
                    status,
                    expected_count,
                    actual_count,
                    failed_count,
                    created_at,
                    completed_at
                FROM pub_runs
                WHERE source_type = :source_type
                  AND source_id = :source_id
                  AND output_type = :output_type
                ORDER BY pub_run_id DESC
                LIMIT 1
                SQL
            );

        $stmt->execute([
            'source_type' =>
                $sourceType,

            'source_id' =>
                $sourceId,

            'output_type' =>
                $outputType,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row ?: null;
    }


    /**
     * Reset an existing run so the SAME pub_run_id can be
     * analyzed and created again from scratch.
     *
     * Asset/file cleanup must happen before this method.
     */
    public function reset(
        int $pubRunId
    ): void {
        if ($pubRunId <= 0) {
            throw new RuntimeException(
                'Invalid PUB run ID.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_runs
                SET
                    status = 'preparing',
                    expected_count = 0,
                    actual_count = 0,
                    failed_count = 0,
                    completed_at = NULL
                WHERE pub_run_id = :pub_run_id
                SQL
            );

        $stmt->execute([
            'pub_run_id' =>
                $pubRunId,
        ]);

        if ($stmt->rowCount() < 1) {
            $run =
                $this->findById(
                    $pubRunId
                );

            if ($run === null) {
                throw new RuntimeException(
                    "PUB run #{$pubRunId} was not found."
                );
            }
        }
    }


    /**
     * Change the number of boxes expected from this run.
     */
    public function setExpectedCount(
        int $pubRunId,
        int $expectedCount
    ): void {
        if ($pubRunId <= 0) {
            throw new RuntimeException(
                'Invalid PUB run ID.'
            );
        }

        if ($expectedCount < 0) {
            throw new RuntimeException(
                'PUB run expected_count cannot be negative.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_runs
                SET expected_count = :expected_count
                WHERE pub_run_id = :pub_run_id
                SQL
            );

        $stmt->execute([
            'expected_count' =>
                $expectedCount,

            'pub_run_id' =>
                $pubRunId,
        ]);
    }


    /**
     * Save current run counts without completing the run.
     */
    public function setCounts(
        int $pubRunId,
        int $actualCount,
        int $failedCount
    ): void {
        $this->validateCounts(
            $pubRunId,
            $actualCount,
            $failedCount
        );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_runs
                SET
                    actual_count = :actual_count,
                    failed_count = :failed_count
                WHERE pub_run_id = :pub_run_id
                SQL
            );

        $stmt->execute([
            'actual_count' =>
                $actualCount,

            'failed_count' =>
                $failedCount,

            'pub_run_id' =>
                $pubRunId,
        ]);
    }


    /**
     * Complete a run.
     */
    public function complete(
        int $pubRunId,
        string $status,
        int $actualCount,
        int $failedCount
    ): void {
        $status =
            strtolower(
                trim($status)
            );


        if (
            !in_array(
                $status,
                self::FINAL_STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid PUB run final status: {$status}"
            );
        }


        $this->validateCounts(
            $pubRunId,
            $actualCount,
            $failedCount
        );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_runs
                SET
                    status = :status,
                    actual_count = :actual_count,
                    failed_count = :failed_count,
                    completed_at = CURRENT_TIMESTAMP
                WHERE pub_run_id = :pub_run_id
                SQL
            );

        $stmt->execute([
            'status' =>
                $status,

            'actual_count' =>
                $actualCount,

            'failed_count' =>
                $failedCount,

            'pub_run_id' =>
                $pubRunId,
        ]);
    }


    /**
     * Return all physical assets belonging to one run.
     */
    public function findAssets(
        int $pubRunId
    ): array {
        if ($pubRunId <= 0) {
            return [];
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT *
                FROM pub_assets
                WHERE pub_run_id = :pub_run_id
                ORDER BY
                    sort_order ASC,
                    pub_asset_id ASC
                SQL
            );

        $stmt->execute([
            'pub_run_id' =>
                $pubRunId,
        ]);


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * Count persisted asset rows for a run.
     */
    public function countAssets(
        int $pubRunId
    ): int {
        if ($pubRunId <= 0) {
            return 0;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT COUNT(*)
                FROM pub_assets
                WHERE pub_run_id = :pub_run_id
                SQL
            );

        $stmt->execute([
            'pub_run_id' =>
                $pubRunId,
        ]);


        return (int)
            $stmt->fetchColumn();
    }


    private function validateCounts(
        int $pubRunId,
        int $actualCount,
        int $failedCount
    ): void {
        if ($pubRunId <= 0) {
            throw new RuntimeException(
                'Invalid PUB run ID.'
            );
        }

        if ($actualCount < 0) {
            throw new RuntimeException(
                'PUB run actual_count cannot be negative.'
            );
        }

        if ($failedCount < 0) {
            throw new RuntimeException(
                'PUB run failed_count cannot be negative.'
            );
        }
    }
}
