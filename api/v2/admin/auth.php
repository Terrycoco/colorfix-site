<?php
declare(strict_types=1);

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

// Compatibility doorbell for existing admin endpoints.
function cf_require_admin(): void
{
    global $pdo;
    \App\SYSTEM\Http\AdminGuard::requireForEndpoint($pdo);
}

cf_require_admin();
