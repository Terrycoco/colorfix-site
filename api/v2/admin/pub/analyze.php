<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\PUB\Endpoints\AnalyzeEndpoint;

/*
 * PUBLIC ANALYZE DOOR.
 *
 * HTTP reaches /api.
 * PUB behavior lives under app/PUB.
 */
AnalyzeEndpoint::handle(
    $pdo
);