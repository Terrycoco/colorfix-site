<?php
declare(strict_types=1);

// Dumb doorbell: existing SYSTEM authentication, then the COLORS endpoint.
require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

\App\COLORS\Endpoints\RefreshSwatchesEndpoint::handle($pdo);
