<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\ERR\Endpoints\RecoverErrorEndpoint;

// This mutating route must remain behind the same admin authentication/CSRF gate
// as the existing admin endpoints. Verify that protection before deployment.
RecoverErrorEndpoint::respond($pdo, dirname(__DIR__, 4));
