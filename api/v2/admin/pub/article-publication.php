<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
\App\PUB\Endpoints\ArticlePublicationEndpoint::handle($pdo);
