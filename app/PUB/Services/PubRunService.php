<?php
declare(strict_types=1);

namespace App\PUB\Services;

use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubRunRepository;
use RuntimeException;

/**
 * PUB RUN SERVICE
 *
 * Owns PUB run lifecycle rules.
 *
 * Repository = persistence.
 * Service    = meaning.
 */
final class PubRunService
{
    public function __construct(
        private PdoPubRunRepository $runRepository,
        private ?PdoPubAssetRepository $assetRepository = null
    ) {}


    /**
     * Open a new PUB run and return its permanent ID.
     */
    public function start(
        string $sourceType,
        int $sourceId,
        string $outputType,
        int $expectedCount = 0
    ): int {
        $sourceType =
            strtolower(
                trim($sourceType)
            );

        $outputType =
            strtolower(
                trim($outputType)
            );

        if ($sourceType === '') {
            throw new RuntimeException(
                'PUB run requires source_type.'
            );
        }

        if ($sourceId <= 0) {
            throw new RuntimeException(
                'PUB run requires a valid source_id.'
            );
        }

        if ($outputType === '') {
            throw new RuntimeException(
                'PUB run requires output_type.'
            );
        }

        if ($expectedCount < 0) {
            throw new RuntimeException(
                'PUB run expected_count cannot be negative.'
            );
        }

        return $this->runRepository->create(
            $sourceType,
            $sourceId,
            $outputType,
            $expectedCount
        );
    }


    /**
     * Find the newest job matching the exact source + output.
     */
    public function findLatestMatching(
        string $sourceType,
        int $sourceId,
        string $outputType
    ): ?array {
        return $this->runRepository
            ->findLatestMatching(
                strtolower(
                    trim($sourceType)
                ),
                $sourceId,
                strtolower(
                    trim($outputType)
                )
            );
    }


    /**
     * Determine whether the whole job is still in-house.
     *
     * If ANY asset has been dispatched/published, the job is
     * historical and cannot be overwritten as a whole.
     *
     * @return array{
     *   can_overwrite: bool,
     *   blocking_assets: array<int, array<string, mixed>>,
     *   asset_count: int
     * }
     */
    public function overwriteStatus(
        int $pubRunId
    ): array {
        $this->assertRunId(
            $pubRunId
        );

        $run =
            $this->runRepository
                ->findById(
                    $pubRunId
                );

        if ($run === null) {
            throw new RuntimeException(
                "PUB run #{$pubRunId} was not found."
            );
        }

        $assets =
            $this->runRepository
                ->findAssets(
                    $pubRunId
                );

        $blocking = [];

        foreach (
            $assets
            as $asset
        ) {
            $stage =
                strtolower(
                    trim(
                        (string)(
                            $asset[
                                'pipeline_stage'
                            ]
                            ?? ''
                        )
                    )
                );

            if (
                in_array(
                    $stage,
                    [
                        'creating',
                        'dispatched',
                        'published',
                    ],
                    true
                )
            ) {
                $blocking[] = [
                    'pub_asset_id' =>
                        (int)(
                            $asset[
                                'pub_asset_id'
                            ]
                            ?? 0
                        ),

                    'pipeline_stage' =>
                        $stage,
                ];
            }
        }

        return [
            'can_overwrite' =>
                $blocking === [],

            'blocking_assets' =>
                $blocking,

            'asset_count' =>
                count(
                    $assets
                ),
        ];
    }


    /**
     * OVERWRITE / RERUN AN EXISTING JOB.
     *
     * Meaning:
     *
     *   - keep the SAME pub_run_id
     *   - require exact same source + output identity
     *   - refuse if ANY asset has left the building
     *   - delete every generated physical file
     *   - delete every pub_asset_order + pub_asset row
     *   - reset run counters/status
     *
     * ANALYZE can then immediately refill this same job.
     */
    public function overwrite(
        int $pubRunId,
        string $sourceType,
        int $sourceId,
        string $outputType
    ): array {
        $this->assertRunId(
            $pubRunId
        );

        if (
            $this->assetRepository ===
            null
        ) {
            throw new RuntimeException(
                'PUB run overwrite requires asset repository.'
            );
        }

        $sourceType =
            strtolower(
                trim($sourceType)
            );

        $outputType =
            strtolower(
                trim($outputType)
            );

        $run =
            $this->runRepository
                ->findById(
                    $pubRunId
                );

        if ($run === null) {
            throw new RuntimeException(
                "PUB run #{$pubRunId} was not found."
            );
        }


        /*
         * Never let a caller use overwrite to turn one job
         * identity into another.
         */
        if (
            strtolower(
                trim(
                    (string)(
                        $run[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            ) !== $sourceType
            ||
            (int)(
                $run[
                    'source_id'
                ]
                ?? 0
            ) !== $sourceId
            ||
            strtolower(
                trim(
                    (string)(
                        $run[
                            'output_type'
                        ]
                        ?? ''
                    )
                )
            ) !== $outputType
        ) {
            throw new RuntimeException(
                "PUB run #{$pubRunId} does not match this source/output request."
            );
        }


        /*
         * PREFLIGHT THE WHOLE JOB BEFORE DELETING ANYTHING.
         */
        $status =
            $this->overwriteStatus(
                $pubRunId
            );

        if (
            !$status[
                'can_overwrite'
            ]
        ) {
            $ids =
                array_values(
                    array_filter(
                        array_map(
                            static fn (
                                array $asset
                            ): int =>
                                (int)(
                                    $asset[
                                        'pub_asset_id'
                                    ]
                                    ?? 0
                                ),
                            $status[
                                'blocking_assets'
                            ]
                        )
                    )
                );

            $suffix =
                $ids
                    ? ' Blocking asset(s): #'
                        . implode(
                            ', #',
                            $ids
                        )
                    : '';

            throw new RuntimeException(
                "PUB job #{$pubRunId} cannot be overwritten because asset(s) are still being created or have already been dispatched/published.{$suffix}"
            );
        }


        $assets =
            $this->runRepository
                ->findAssets(
                    $pubRunId
                );


        /*
         * PHASE 1 — DELETE PHYSICAL FILES.
         *
         * Do this before deleting DB rows. If a file cannot be
         * removed, the durable asset/order records remain intact
         * so the failure can still be investigated and retried.
         */
        foreach (
            $assets
            as $asset
        ) {
            $filePath =
                trim(
                    (string)(
                        $asset[
                            'file_path'
                        ]
                        ?? ''
                    )
                );

            if (
                $filePath !== ''
                && is_file(
                    $filePath
                )
            ) {
                if (
                    !unlink(
                        $filePath
                    )
                ) {
                    $assetId =
                        (int)(
                            $asset[
                                'pub_asset_id'
                            ]
                            ?? 0
                        );

                    throw new RuntimeException(
                        "Could not delete physical file for PUB asset #{$assetId}: {$filePath}"
                    );
                }
            }
        }


        /*
         * PHASE 2 — DELETE DURABLE ASSET + ORDER ROWS.
         *
         * deleteUnsent() enforces the same lifecycle rule again.
         */
        foreach (
            $assets
            as $asset
        ) {
            $pubAssetId =
                (int)(
                    $asset[
                        'pub_asset_id'
                    ]
                    ?? 0
                );

            if ($pubAssetId <= 0) {
                continue;
            }

            $this->assetRepository
                ->deleteUnsent(
                    $pubAssetId
                );
        }


        /*
         * PHASE 3 — RESET THE SAME JOB.
         */
        $this->runRepository
            ->reset(
                $pubRunId
            );

        $reset =
            $this->runRepository
                ->findById(
                    $pubRunId
                );

        if ($reset === null) {
            throw new RuntimeException(
                "PUB run #{$pubRunId} could not be reloaded after overwrite."
            );
        }

        return [
            'run' =>
                $reset,

            'deleted_asset_count' =>
                count(
                    $assets
                ),
        ];
    }


    /**
     * Set or revise the number of boxes this run expects.
     */
    public function setExpectedCount(
        int $pubRunId,
        int $expectedCount
    ): void {
        $this->assertRunId(
            $pubRunId
        );

        if ($expectedCount < 0) {
            throw new RuntimeException(
                'PUB run expected_count cannot be negative.'
            );
        }

        $this->runRepository->setExpectedCount(
            $pubRunId,
            $expectedCount
        );
    }


    /**
     * Stamp one box with its factory run identity.
     */
    public function stampBox(
        int $pubRunId,
        array $box
    ): array {
        $this->assertRunId(
            $pubRunId
        );

        return [
            ...$box,

            'pub_run_id' =>
                $pubRunId,
        ];
    }


    /**
     * Stamp a batch of boxes with the same run ID.
     */
    public function stampBoxes(
        int $pubRunId,
        array $boxes
    ): array {
        $this->assertRunId(
            $pubRunId
        );

        $stamped = [];

        foreach ($boxes as $box) {
            if (!is_array($box)) {
                throw new RuntimeException(
                    'PUB run can only stamp array boxes.'
                );
            }

            $stamped[] = [
                ...$box,

                'pub_run_id' =>
                    $pubRunId,
            ];
        }

        return $stamped;
    }


    /**
     * Save current counts without closing the run.
     */
    public function recordProgress(
        int $pubRunId,
        int $actualCount,
        int $failedCount
    ): void {
        $this->assertCounts(
            $pubRunId,
            $actualCount,
            $failedCount
        );

        $this->runRepository->setCounts(
            $pubRunId,
            $actualCount,
            $failedCount
        );
    }


    /**
     * Recalculate CREATE progress from the durable assets belonging
     * to this run.
     *
     * This is used when asynchronous CREATE work finishes later than
     * the original HTTP handoff. A queued asset remains "creating"
     * and is therefore not counted as actual until its physical file
     * has been promoted and the asset has advanced beyond CREATE.
     *
     * @return array{
     *   actual_count: int,
     *   failed_count: int,
     *   pending_count: int,
     *   asset_count: int
     * }
     */
    public function refreshProgressFromAssets(
        int $pubRunId
    ): array {
        $this->assertRunId(
            $pubRunId
        );


        $assets =
            $this->runRepository
                ->findAssets(
                    $pubRunId
                );


        $actualCount = 0;
        $failedCount = 0;
        $pendingCount = 0;


        foreach (
            $assets
            as $asset
        ) {
            $stage =
                strtolower(
                    trim(
                        (string)(
                            $asset[
                                'pipeline_stage'
                            ]
                            ?? ''
                        )
                    )
                );


            if ($stage === 'error') {
                $failedCount++;
                continue;
            }


            if (
                in_array(
                    $stage,
                    [
                        'created',
                        'packaged',
                        'scheduled',
                        'dispatched',
                        'published',
                    ],
                    true
                )
            ) {
                $actualCount++;
                continue;
            }


            /*
             * "creating" is the normal asynchronous state.
             * Unknown/nonfinal in-house states are also treated as
             * pending rather than falsely counted as created.
             */
            $pendingCount++;
        }


        $this->recordProgress(
            $pubRunId,
            $actualCount,
            $failedCount
        );


        return [
            'actual_count' =>
                $actualCount,

            'failed_count' =>
                $failedCount,

            'pending_count' =>
                $pendingCount,

            'asset_count' =>
                count(
                    $assets
                ),
        ];
    }


    /**
     * Close a run and determine its final status.
     */
    public function complete(
        int $pubRunId,
        int $actualCount,
        int $failedCount
    ): array {
        $this->assertCounts(
            $pubRunId,
            $actualCount,
            $failedCount
        );

        $run =
            $this->runRepository->findById(
                $pubRunId
            );

        if ($run === null) {
            throw new RuntimeException(
                "PUB run #{$pubRunId} was not found."
            );
        }

        $expectedCount =
            (int)(
                $run['expected_count']
                ?? 0
            );

        $status =
            $this->finalStatus(
                $expectedCount,
                $actualCount,
                $failedCount
            );

        $this->runRepository->complete(
            $pubRunId,
            $status,
            $actualCount,
            $failedCount
        );

        $completed =
            $this->runRepository->findById(
                $pubRunId
            );

        if ($completed === null) {
            throw new RuntimeException(
                "PUB run #{$pubRunId} could not be reloaded after completion."
            );
        }

        return $completed;
    }


    public function get(
        int $pubRunId
    ): ?array {
        if ($pubRunId <= 0) {
            return null;
        }

        return $this->runRepository->findById(
            $pubRunId
        );
    }


    public function assets(
        int $pubRunId
    ): array {
        $this->assertRunId(
            $pubRunId
        );

        return $this->runRepository->findAssets(
            $pubRunId
        );
    }


    private function finalStatus(
        int $expectedCount,
        int $actualCount,
        int $failedCount
    ): string {
        if (
            $failedCount === 0
            && $actualCount === $expectedCount
        ) {
            return 'ready';
        }

        if ($actualCount > 0) {
            return 'partial';
        }

        return 'failed';
    }


    private function assertCounts(
        int $pubRunId,
        int $actualCount,
        int $failedCount
    ): void {
        $this->assertRunId(
            $pubRunId
        );

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


    private function assertRunId(
        int $pubRunId
    ): void {
        if ($pubRunId <= 0) {
            throw new RuntimeException(
                'Valid pub_run_id required.'
            );
        }
    }
}