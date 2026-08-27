<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Endpoints\PackageEndpoint;

/*
 * PUBLIC PACKAGE DOOR.
 *
 * Package administration and the Package department
 * live under app/PUB.
 */
PackageEndpoint::handle(
    $pdo
);
