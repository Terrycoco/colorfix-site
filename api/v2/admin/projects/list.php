<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\PROJECTS\Endpoints\ListEndpoint;

ListEndpoint::handle(
    $pdo
);
