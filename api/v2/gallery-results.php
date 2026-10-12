<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

\App\GALLERY\Endpoints\GalleryResultsEndpoint::handle($pdo);
