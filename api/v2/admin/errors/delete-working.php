<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\ERR\Endpoints\DeleteWorkingEndpoint;

// Apply the same admin authorization and CSRF gate as other mutating admin routes.
DeleteWorkingEndpoint::respond($pdo);
