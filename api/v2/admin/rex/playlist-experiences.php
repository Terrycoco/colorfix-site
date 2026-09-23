<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\REX\Endpoints\PlaylistExperiencesEndpoint;


/*
 * PLAYLIST REX EXPERIENCE DOORBELL.
 *
 * GET  = inspect Public / Concept / Client.
 * POST = reconcile/fetch current Playlist REX graph.
 */
PlaylistExperiencesEndpoint::handle(
    $pdo
);
