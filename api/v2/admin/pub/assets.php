<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Endpoints\PubAssetsEndpoint;

/*
 * PUBLIC PUB-ASSETS DOOR.
 *
 * Asset administration lives under app/PUB.
 * Production/REDO does not pass through this file.
 */
PubAssetsEndpoint::handle(
    $pdo
);