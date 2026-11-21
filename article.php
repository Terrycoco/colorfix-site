<?php
require_once __DIR__ . '/api/autoload.php';
require_once __DIR__ . '/api/db.php';
\App\ARTICLES\Endpoints\ArticlePageEndpoint::handle($pdo, __DIR__);
