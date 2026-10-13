<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\ERR\Endpoints\ErrorStatusEndpoint;

// Admin authentication must be enforced by the shared API bootstrap/gate.
ErrorStatusEndpoint::respond($pdo);
