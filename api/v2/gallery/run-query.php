<?php
declare(strict_types=1);

// Public Gallery doorbell. Business logic belongs to app/GALLERY.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}
header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

$apiRoot = dirname(__DIR__, 2);
require_once $apiRoot . '/autoload.php';
require_once $apiRoot . '/db.php';

\App\GALLERY\Endpoints\GalleryRunQueryEndpoint::handle(
    $pdo,
    $apiRoot . '/run-query-error.log'
);
