<?php
declare(strict_types=1);

/**
 * Refresh ONLY mv_color_categories from existing color_category assignments.
 * Usage (from project root): php scripts/colors-refresh-mv.php --confirm-mv-refresh
 * Does not recalculate HCL, update colors, clusters, or database views.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--confirm-mv-refresh' || count($argv) !== 2) {
    fwrite(STDERR, "No changes made. Usage: php scripts/colors-refresh-mv.php --confirm-mv-refresh\n");
    exit(2);
}

require_once dirname(__DIR__) . '/api/autoload.php';
require_once dirname(__DIR__) . '/api/db.php';

use App\COLORS\Repos\PdoMaterializedCategoriesRepository;

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $engineStmt = $pdo->query(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() "
        . "AND TABLE_NAME IN ('color_category', 'category_definitions', 'mv_color_categories')"
    );
    $engines = $engineStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['color_category', 'category_definitions', 'mv_color_categories'] as $table) {
        if (strtoupper((string) ($engines[$table] ?? '')) !== 'INNODB') {
            throw new RuntimeException("{$table} must be InnoDB; no changes made.");
        }
    }

    $repo = new PdoMaterializedCategoriesRepository($pdo);
    $before = $repo->rowCount();
    $started = microtime(true);
    // The repository owns the transaction and rolls back if refresh or row-count validation fails.
    $result = $repo->refresh();
    $after = $repo->rowCount();
    if ($after !== (int) $result['expected']) {
        throw new RuntimeException("Post-commit count differs: {$after} vs {$result['expected']}.");
    }
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'operation' => 'mv_only_refresh',
        'rows_before' => $before,
        'rows_after' => $after,
        'expected' => (int) $result['expected'],
        'seconds' => round(microtime(true) - $started, 2),
        'recalculated_hcl' => false,
        'updated_clusters' => false,
        'updated_swatch_view' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "COLORS MV refresh failed: {$e->getMessage()}\n");
    exit(1);
}
