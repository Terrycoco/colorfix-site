<?php
declare(strict_types=1);

// Public dumb doorbell: bootstrap and delegate. No domain SQL or composition here.
require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

\App\ARTWORK\Endpoints\ArtworkPageEndpoint::handle($pdo);
