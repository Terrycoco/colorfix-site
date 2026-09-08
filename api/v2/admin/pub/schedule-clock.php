<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\PUB\Endpoints\ScheduleEndpoint;

/*
 * PUBLIC SCHEDULE CLOCK DOORBELL.
 *
 * This file knows nothing about scheduling.
 * Cron wakes it; it hands control to the PUB endpoint.
 */
ScheduleEndpoint::handleClock(
    $pdo
);
