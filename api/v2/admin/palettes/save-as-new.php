<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\PALETTES\Endpoints\AdminPaletteSaveAsNewEndpoint;

AdminPaletteSaveAsNewEndpoint::handle($pdo);
