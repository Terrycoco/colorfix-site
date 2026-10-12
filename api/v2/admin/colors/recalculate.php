<?php
declare(strict_types=1);

// Dumb doorbell: bootstrap, require existing admin auth, hand off.
require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

\App\COLORS\Endpoints\RecalculateCategoriesEndpoint::handle($pdo);
