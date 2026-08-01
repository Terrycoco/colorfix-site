<?php
declare(strict_types=1);

require_once __DIR__ . '/api/autoload.php';
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/functions/card-config.php';

$config = loadCardConfig($pdo ?? null);
$target = normalizeCardTargetUrl((string)($config['target_url'] ?? '/'));

header('Location: ' . $target, true, 302);
exit;
