<?php
declare(strict_types=1);

use App\repos\PdoColorRepository;
use App\services\ScoreCandidates;

test('white: Greek Villa prefers Milk Glass over Abstract White', function () {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available; ensure /api/db.php defines $pdo.');
    }

    $repo = new PdoColorRepository($GLOBALS['pdo']);
    $svc  = new ScoreCandidates($repo);

    $seedId = 1829;   // Greek Villa
    $mgId   = 28280;  // Milk Glass
    $awId   = 27074;  // Abstract White

    $r = $svc->run($seedId, [$mgId, $awId], 'white');

    if (count($r['results']) !== 2) {
        throw new RuntimeException('Expected 2 results, got '.count($r['results']));
    }

    $firstId = $r['results'][0]['id'];
    if ($firstId !== $mgId) {
        $dbg = ['seed'=>$r['seed'], 'results'=>$r['results']];
        throw new RuntimeException('Expected Milk Glass first. Debug: '.json_encode($dbg, JSON_UNESCAPED_SLASHES));
    }
});
