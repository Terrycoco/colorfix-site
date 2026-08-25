<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\PUB\Endpoints\CreateEndpoint;

/*
 * PUBLIC CREATE DOOR.
 *
 * NEW and REDO use this same door.
 * CreateManager determines which kind of order arrived.
 */
CreateEndpoint::handle(
    $pdo
);