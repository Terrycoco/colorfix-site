<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Temporary diagnostic: return a clear error if bootstrap fails before
// the export endpoint's normal exception handler is available.
$stage = 'autoload';
try {
    require_once __DIR__ . '/../../../autoload.php';
    $stage = 'database';
    require_once __DIR__ . '/../../../db.php';
    $stage = 'admin authentication';
    require_once __DIR__ . '/../auth.php';
    $stage = 'export endpoint initialization';
    \App\PUB\Analyze\Website\ArticleResearchExportEndpoint::handle($pdo);
} catch (\Throwable $error) {
    error_log('[ArticleResearchExport bootstrap] ' . $stage . ': ' . $error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo 'Article research export failed at: ' . $stage . "\n";
    echo 'Error type: ' . get_class($error) . "\n";
    echo 'Message: ' . $error->getMessage() . "\n";
}
