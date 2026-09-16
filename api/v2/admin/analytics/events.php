<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\ANA\Endpoints\EventsEndpoint;

EventsEndpoint::handle($pdo);
