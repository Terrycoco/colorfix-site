<?php
declare(strict_types=1);

require_once __DIR__ . '/api/autoload.php';
require_once __DIR__ . '/api/db.php';

use App\PUB\Endpoints\YouTubeOAuthCallbackEndpoint;

/*
 * PUBLIC GOOGLE / YOUTUBE OAUTH CALLBACK DOOR.
 *
 * Google is configured to return here:
 *   https://colorfix.terrymarr.com/oauth-google.php
 *
 * Real OAuth behavior lives under app/PUB.
 */

YouTubeOAuthCallbackEndpoint::handle(
    $pdo
);
