<?php
declare(strict_types=1);

// Run from the project root via PHP CLI only, after a database backup.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!in_array(($argv[1] ?? ''), ['--confirm-atomic-rebuild', '--test-rollback'], true)) {
    fwrite(STDERR, "No changes made. Run --test-rollback first; production requires --confirm-atomic-rebuild.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/api/autoload.php';
require_once dirname(__DIR__) . '/api/db.php';

use App\COLORS\Managers\CategoryRecalcManager;

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // No schema migrations; both tables must be InnoDB for the rollback guarantee.
    $engineStmt = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('color_category','mv_color_categories','clusters')");
    $engines = $engineStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['color_category', 'mv_color_categories', 'clusters'] as $table) {
        if (strtoupper((string)($engines[$table] ?? '')) !== 'INNODB') {
            throw new RuntimeException("{$table} must use InnoDB; no changes made.");
        }
    }
    $testRollback = ($argv[1] === '--test-rollback');
    // Fingerprint the committed rows before and after a deliberately failed rebuild.
    // The fingerprint includes all materialized/category and cluster summary columns.
    $fingerprint = static function (PDO $db): array {
        $tables = [
            'color_category' => 'SELECT color_id, category_id FROM color_category ORDER BY color_id, category_id',
            'mv_color_categories' => 'SELECT color_id, hue_cats, neutral_cats, lightness_cats, chroma_cats FROM mv_color_categories ORDER BY color_id',
            'clusters' => 'SELECT id, hue_cats, neutral_cats, lightness_cats, chroma_cats FROM clusters ORDER BY id',
        ];
        $out = [];
        foreach ($tables as $table => $sql) {
            $ctx = hash_init('sha256');
            $count = 0;
            $q = $db->query($sql);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                hash_update($ctx, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
                ++$count;
            }
            $q->closeCursor();
            $out[$table] = ['rows' => $count, 'sha256' => hash_final($ctx)];
        }
        return $out;
    };
    $before = $testRollback ? $fingerprint($pdo) : null;
    $manager = new CategoryRecalcManager($pdo);
    $started = microtime(true);
    try {
        $result = $manager->rebuild(500, static function (int $processed, int $total): void {
        if ($processed % 5000 === 0 || $processed === $total) {
            fwrite(STDERR, "Classified {$processed}/{$total} (uncommitted)\n");
        }
    }, $testRollback);
    } catch (RuntimeException $e) {
        if (!$testRollback || $e->getMessage() !== 'COLORS_TEST_ROLLBACK_INJECTED') {
            throw $e;
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Rollback test failed: transaction still active.');
        }
        $after = $fingerprint($pdo);
        if ($before !== $after) {
            throw new RuntimeException('Rollback test FAILED: committed data fingerprints differ.');
        }
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'mode' => 'intentional_rollback_test',
            'failure_injected_after_all_writes' => true,
            'transaction_rolled_back' => true,
            'data_unchanged' => true,
            'fingerprints' => $after,
            'seconds' => round(microtime(true) - $started, 2),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(0);
    }
    $result['seconds'] = round(microtime(true) - $started, 1);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'COLORS rebuild failed; transaction rolled back if active: ' . $e->getMessage() . "\n");
    exit(1);
}
