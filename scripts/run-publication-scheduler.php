<?php
declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';
require __DIR__ . '/../api/db.php';

use App\Lib\SecretBox;
use App\Repos\PdoPublicationScheduleRepository;
use App\Repos\PdoPublisherRepository;
use App\Services\PublicationExecutor;
use App\Services\PublicationScheduler;

$options = getopt('', ['limit::', 'worker::']);
$limit = max(1, min(50, (int)($options['limit'] ?? 10)));
$worker = trim((string)($options['worker'] ?? ('cli-' . gethostname() . '-' . getmypid())));

$repo = new PdoPublicationScheduleRepository($pdo);
$scheduler = new PublicationScheduler($repo);
$executor = new PublicationExecutor($repo, new PdoPublisherRepository($pdo), new SecretBox());

echo json_encode($scheduler->runDue($executor, $limit, $worker), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
