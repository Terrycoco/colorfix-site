<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\PHOTOS\Endpoints\UploadEndpoint;

/*
 * ADMIN PHOTO UPLOAD DOOR.
 *
 * Request validation, upload orchestration,
 * and response handling belong to PHOTOS.
 */
UploadEndpoint::handle($pdo);