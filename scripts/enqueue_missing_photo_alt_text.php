<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI.\n");
}

require_once __DIR__ . '/../api/autoload.php';
require_once __DIR__ . '/../api/db.php';

use App\Services\PhotoAltTextQueueService;

$limit = 200;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    }
}

$enabled = strtolower((string)(getenv('PHOTO_ALT_TEXT_WORKER_ENABLED') ?: ''));
if (!in_array($enabled, ['1', 'true', 'yes'], true)) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'PHOTO_ALT_TEXT_WORKER_ENABLED is not enabled',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$service = PhotoAltTextQueueService::fromPdo($pdo, dirname(__DIR__));
$queued = $service->enqueueMissing($limit);
echo json_encode(['ok' => true, 'queued' => $queued], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
