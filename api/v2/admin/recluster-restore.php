<?php
declare(strict_types=1);
require_once __DIR__.'/../../autoload.php';
require_once __DIR__.'/../../db.php';

use App\Repos\PdoColorRepository;
use App\Repos\PdoClusterRepository;

$pdo = $GLOBALS['pdo'];
$colorRepo   = new PdoColorRepository($pdo);
$clusterRepo = new PdoClusterRepository($pdo);

// get all colors that have HCL (restored)
$ids = $pdo->query("SELECT id FROM colors WHERE hcl_h IS NOT NULL AND hcl_c IS NOT NULL AND hcl_l IS NOT NULL")
           ->fetchAll(PDO::FETCH_COLUMN);

$res = $clusterRepo->assignClustersBulkByColorIds(array_map('intval', $ids));
echo json_encode(['ok'=>true, 'summary'=>$res], JSON_UNESCAPED_SLASHES);
