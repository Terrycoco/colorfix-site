<?php
declare(strict_types=1);

require __DIR__ . '/api/autoload.php';
require __DIR__ . '/api/db.php';

use App\REX\Endpoints\RexPublicEntryEndpoint;

RexPublicEntryEndpoint::handle(
    $pdo,
    __DIR__ . '/index.html'
);
