<?php
declare(strict_types=1);

// Run with: php api/tools/recalc-mask-overlays.php [--asset=PHO_ABC123] [--force]
if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../db.php';

use App\Repos\PdoPhotoRepository;
use App\Services\MaskOverlayService;

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "DB not initialized via api/db.php\n");
    exit(1);
}

$assetId = '';
$force = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset=')) {
        $assetId = trim(substr($arg, 8));
    } elseif ($arg === '--force') {
        $force = true;
    }
}

$repo = new PdoPhotoRepository($pdo);
$svc = new MaskOverlayService($repo, $pdo);

if ($assetId !== '') {
    $res = $svc->applyDefaultsForAsset($assetId, $force, true);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$offset = 0;
$limit = 200;
$totalUpdated = 0;
$totalSkipped = 0;

while (true) {
    $rows = $repo->listPhotos(['limit' => $limit, 'offset' => $offset]);
    if (!$rows) break;
    foreach ($rows as $row) {
        $aid = (string)($row['asset_id'] ?? '');
        if ($aid === '') continue;
        $res = $svc->applyDefaultsForAsset($aid, $force, true);
        $totalUpdated += (int)($res['updated'] ?? 0);
        $totalSkipped += (int)($res['skipped'] ?? 0);
        echo "{$aid}: updated={$res['updated']} skipped={$res['skipped']}\n";
    }
    if (count($rows) < $limit) break;
    $offset += $limit;
}

echo "✅ Done. updated={$totalUpdated} skipped={$totalSkipped}\n";
