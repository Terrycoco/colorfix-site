<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Endpoints\PinterestOAuthCallbackEndpoint;

/*
 * PUBLIC PINTEREST OAUTH CALLBACK DOOR.
 *
 * Real OAuth behavior lives under app/PUB.
 */
PinterestOAuthCallbackEndpoint::handle(
    $pdo
);
