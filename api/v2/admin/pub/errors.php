<?php
declare(strict_types=1);

use App\PUB\Endpoints\PubErrorsEndpoint;

/*
 * PUB ERRORS DOORBELL
 *
 * Public admin entrypoint only:
 *   - bootstrap app + database
 *   - enforce admin auth
 *   - hand control to PubErrorsEndpoint
 *
 * No PUB error-log business logic belongs here.
 */

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

PubErrorsEndpoint::handle(
    $pdo
);
