<?php
declare(strict_types=1);

use App\REX\Services\RexSitemapService;

require_once __DIR__ . '/api/autoload.php';
require_once __DIR__ . '/api/db.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    // Query current publication/reservation state on every request.
    echo RexSitemapService::xml((new RexSitemapService($pdo))->urls());
} catch (Throwable $e) {
    error_log('REX sitemap generation failed: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Sitemap temporarily unavailable</error>';
}
