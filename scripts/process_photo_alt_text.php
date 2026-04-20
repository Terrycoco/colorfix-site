<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI.\n");
}

require_once __DIR__ . '/../api/autoload.php';
require_once __DIR__ . '/../api/db.php';

use App\Services\PhotoAltTextQueueService;

$limit = 3;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    }
}

$service = PhotoAltTextQueueService::fromPdo($pdo, dirname(__DIR__));
$result = $service->processReady($limit);
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
