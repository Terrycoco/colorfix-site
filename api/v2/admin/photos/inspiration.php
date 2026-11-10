<?php
declare(strict_types=1);

// Thin admin doorbell. PHOTOS owns the operations.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';
\App\PHOTOS\Endpoints\PhotoInspirationEndpoint::handle($pdo);
