<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Endpoints\ChannelConnectionEndpoint;

/*
 * PUBLIC PUB CHANNEL-CONNECTION DOOR.
 *
 * Real connection logic lives under app/PUB/Dispatch.
 * This file only boots the admin environment and hands
 * the request to the PUB endpoint.
 */
ChannelConnectionEndpoint::handle(
    $pdo
);
