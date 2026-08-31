<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Endpoints\YouTubeOAuthStartEndpoint;

/*
 * PUBLIC PUB YOUTUBE OAUTH START DOOR.
 *
 * Real OAuth behavior lives under app/PUB.
 */
YouTubeOAuthStartEndpoint::handle(
    $pdo
);
