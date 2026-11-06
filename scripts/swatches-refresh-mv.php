<?php
declare(strict_types=1);

/** Rebuild mv_swatch_view on demand, including its is_inactive field. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--confirm-mv-refresh' || count($argv) !== 2) {
    fwrite(STDERR, "No changes made. Usage: php scripts/swatches-refresh-mv.php --confirm-mv-refresh\n");
    exit(2);
}
require_once dirname(__DIR__) . '/api/autoload.php';
require_once dirname(__DIR__) . '/api/db.php';

use App\COLORS\Repos\PdoMaterializedSwatchRepository;

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $started = microtime(true);
    $result = (new PdoMaterializedSwatchRepository($pdo))->refresh();
    fwrite(STDOUT, json_encode([
        'ok' => true, 'operation' => 'swatch_mv_refresh',
        ...$result, 'seconds' => round(microtime(true) - $started, 2),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Swatch refresh failed: ' . $e->getMessage() . "\n");
    exit(1);
}
