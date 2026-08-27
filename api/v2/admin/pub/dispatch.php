<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Endpoints\DispatchEndpoint;

/*
 * PUBLIC DISPATCH DOOR.
 *
 * Dispatch administration lives under app/PUB.
 * This file only boots the admin environment and
 * hands the request to the PUB Dispatch endpoint.
 */
DispatchEndpoint::handle(
    $pdo
);
