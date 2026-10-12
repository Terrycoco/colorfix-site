<?php
declare(strict_types=1);

namespace App\COLORS\Managers;

use App\COLORS\Repos\PdoCategoryDefinitionRepository;
use App\COLORS\Repos\PdoColorRepository;
use App\COLORS\Repos\PdoColorClassificationRepository;
use App\COLORS\Repos\PdoMaterializedCategoriesRepository;
use App\COLORS\Repos\PdoClusterCategoryRepository;
use App\COLORS\Services\ColorClassifier;
use PDO;
use RuntimeException;

/**
 * New classification coordinator. NOT wired to any public endpoint.
 * compare() is read-only. rebuild() must only be invoked after comparison
 * and a controlled cutover, because legacy colors cache fields are NOT updated.
 */
final class CategoryRecalcManager
{
    private PdoCategoryDefinitionRepository $definitions;
    private PdoColorRepository $colors;
    private PdoColorClassificationRepository $assignments;
    private PdoMaterializedCategoriesRepository $materialized;
    private PdoClusterCategoryRepository $clusters;
    private ColorClassifier $classifier;

    public function __construct(PDO $pdo)
    {
        $this->definitions = new PdoCategoryDefinitionRepository($pdo);
        $this->colors = new PdoColorRepository($pdo);
        $this->assignments = new PdoColorClassificationRepository($pdo);
        $this->materialized = new PdoMaterializedCategoriesRepository($pdo);
        $this->clusters = new PdoClusterCategoryRepository($pdo);
        $this->classifier = new ColorClassifier();
        $this->pdo = $pdo;
    }

    private PDO $pdo;

    /**
     * Compare new HCL classifier results to existing raw color_category rows.
     * Does not write anything. Sample differences contain color IDs only.
     * @return array<string,mixed>
     */
    public function compare(int $batchSize = 2000, int $sampleLimit = 30): array
    {
        $batchSize = max(1, min(10000, $batchSize));
        $sampleLimit = max(0, min(100, $sampleLimit));
        $defs = $this->definitions->activeByType();
        if ($defs === []) throw new RuntimeException('No active category definitions.');
        $expected = $this->colors->count();
        $processed = 0;
        $differences = 0;
        $samples = [];
        $lastId = 0;
        while (true) {
            $batch = $this->colors->batchAfterId($lastId, $batchSize);
            if ($batch === []) break;
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $batch);
            $stored = $this->fetchStoredForIds($ids);
            foreach ($batch as $row) {
                $colorId = (int)$row['id'];
                $lastId = $colorId;
                $calculated = $this->classifyRow($row, $defs);
                sort($calculated, SORT_NUMERIC);
                $existing = $stored[$colorId] ?? [];
                sort($existing, SORT_NUMERIC);
                if ($calculated !== $existing) {
                    $differences++;
                    if (count($samples) < $sampleLimit) {
                        $samples[] = [
                            'color_id' => $colorId,
                            'existing_category_ids' => $existing,
                            'calculated_category_ids' => $calculated,
                        ];
                    }
                }
                $processed++;
            }
        }
        return [
            'mode' => 'compare_read_only',
            'total_colors_at_start' => $expected,
            'processed' => $processed,
            'different_colors' => $differences,
            'sample_differences' => $samples,
            'complete' => $processed === $expected,
        ];
    }

    /**
     * Read-only comparison of one bounded page. No colors or categories are modified.
     * Call repeatedly using next_after_id until complete=true.
     * @return array<string,mixed>
     */
    public function comparePage(int $afterId = 0, int $limit = 500, int $sampleLimit = 20): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min(2000, $limit));
        $sampleLimit = max(0, min(50, $sampleLimit));
        $definitions = $this->definitions->activeByType();
        if ($definitions === []) {
            throw new RuntimeException('No active category definitions.');
        }
        // Look ahead one row so a final full page can still report completion.
        $batch = $this->colors->batchAfterId($afterId, $limit + 1);
        $hasMore = count($batch) > $limit;
        if ($hasMore) {
            $batch = array_slice($batch, 0, $limit);
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $batch);
        $stored = $this->fetchStoredForIds($ids);
        $differences = 0;
        $samples = [];
        $next = $afterId;
        foreach ($batch as $row) {
            $id = (int)$row['id'];
            $next = $id;
            $calculated = $this->classifyRow($row, $definitions);
            sort($calculated, SORT_NUMERIC);
            $existing = $stored[$id] ?? [];
            sort($existing, SORT_NUMERIC);
            if ($calculated !== $existing) {
                $differences++;
                if (count($samples) < $sampleLimit) {
                    $samples[] = [
                        'color_id' => $id,
                        'existing_category_ids' => $existing,
                        'calculated_category_ids' => $calculated,
                    ];
                }
            }
        }
        return [
            'mode' => 'compare_read_only',
            'after_id' => $afterId,
            'next_after_id' => $next,
            'processed' => count($batch),
            'different_colors' => $differences,
            'sample_differences' => $samples,
            'complete' => !$hasMore,
        ];
    }

    /**
     * CLI-controlled, atomic rebuild. No web endpoint invokes this method.
     * Preserves current color_category and mv_color_categories if any phase fails.
     * All affected tables must use a transactional storage engine (InnoDB).
     * Warning: a full rebuild may hold substantial locks and run for minutes.
     * Updates cluster categories from MV; does NOT update legacy colors cached fields.
     *
     * @param callable(int,int):void|null $progress
     * @return array<string,mixed>
     */
    public function rebuild(int $batchSize = 500, ?callable $progress = null, bool $testRollback = false): array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Cannot start rebuild inside another transaction.');
        }
        $batchSize = max(1, min(2000, $batchSize));
        // This lock is connection-scoped and must be acquired before preflight.
        // It coordinates new COLORS rebuilds, not the legacy endpoint.
        $lockName = 'colorfix:COLORS:atomic_rebuild';
        $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lockStmt->execute([$lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new RuntimeException('Another COLORS rebuild holds the advisory lock. No changes made.');
        }
        try {
            return $this->rebuildWithLock($batchSize, $progress, $testRollback);
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function rebuildWithLock(int $batchSize, ?callable $progress, bool $testRollback): array
    {
        $defs = $this->definitions->activeByType();
        if ($defs === []) {
            throw new RuntimeException('No active category definitions.');
        }
        $expected = $this->colors->count();
        if ($expected <= 0) {
            throw new RuntimeException('No colors available for classification.');
        }

        // Fail before touching assignments if any HCL value is invalid.
        $preflightCount = 0;
        $cursor = 0;
        while ($rows = $this->colors->batchAfterId($cursor, $batchSize)) {
            foreach ($rows as $row) {
                $this->classifyRow($row, $defs);
                $cursor = (int)$row['id'];
                ++$preflightCount;
            }
        }
        if ($preflightCount !== $expected) {
            throw new RuntimeException('Color records changed during preflight; no changes made.');
        }

        $processed = 0;
        $cursor = 0;
        $this->pdo->beginTransaction();
        try {
            // Snapshot the expected count within the write transaction.
            if ($this->colors->count() !== $expected) {
                throw new RuntimeException('Color count changed before rebuild.');
            }
            while ($rows = $this->colors->batchAfterId($cursor, $batchSize)) {
                $assignments = [];
                foreach ($rows as $row) {
                    $id = (int)$row['id'];
                    $assignments[$id] = $this->classifyRow($row, $defs);
                    $cursor = $id;
                }
                $this->assignments->replaceBatch($assignments);
                $processed += count($rows);
                if ($progress !== null) {
                    $progress($processed, $expected);
                }
            }
            if ($processed !== $expected || $this->colors->count() !== $expected) {
                throw new RuntimeException('Color count changed while rebuilding; rolling back.');
            }
            // Remains uncommitted until BOTH assignment and MV validation succeed.
            $mv = $this->materialized->refreshWithinTransaction();
            $clusterResult = $this->clusters->refreshWithinTransaction();
            if ($testRollback) {
                // Deliberate failure AFTER all three write stages, BEFORE commit.
                throw new RuntimeException('COLORS_TEST_ROLLBACK_INJECTED');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return [
            'mode' => 'atomic_rebuild',
            'processed' => $processed,
            'materialized' => $mv,
            'clusters' => $clusterResult,
            'legacy_color_caches_updated' => false,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,list<array<string,mixed>>> $definitions */
    private function classifyRow(array $row, array $definitions): array
    {
        foreach (['hcl_h', 'hcl_c', 'hcl_l'] as $field) {
            if ($row[$field] === null || !is_numeric($row[$field])) {
                throw new RuntimeException("Color {$row['id']} has invalid {$field}.");
            }
        }
        return $this->classifier->classify(
            (float)$row['hcl_h'], (float)$row['hcl_c'], (float)$row['hcl_l'], $definitions
        );
    }

    /** @param list<int> $ids @return array<int,list<int>> */
    private function fetchStoredForIds(array $ids): array
    {
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT color_id, category_id FROM color_category WHERE color_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['color_id']][] = (int)$row['category_id'];
        }
        foreach ($result as &$categories) {
            $categories = array_values(array_unique($categories));
        }
        unset($categories);
        return $result;
    }
}
