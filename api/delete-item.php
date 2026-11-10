<?php
declare(strict_types=1);

require_once __DIR__ . '/v2/admin/auth.php';

\App\GALLERY\Endpoints\DeleteItemEndpoint::handle($pdo);
