<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../project-workflow/_helpers.php';
require_once __DIR__ . '/_worker_auth.php';

use App\PUB\Endpoints\VideoJobCompletionEndpoint;

VideoJobCompletionEndpoint::handle(
    $pdo
);