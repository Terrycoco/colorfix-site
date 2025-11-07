<?php
declare(strict_types=1);

// MatchingNeighborsSvcTest.php
//
// Verifies that MatchingService::neighborsForCluster() returns sensible neighbors
// for a known cluster (5746). We assert that it returns a non-empty list and that
// the expected close clusters (from the "My Palette" page) appear near the top.
//
// Expected close neighbors (visual sanity picks): 6588, 7110, 6587

use App\services\MatchingService;
use App\services\Rules;
use App\services\ScoreCandidates;
use App\services\FindBestPerBrand;
use App\repos\PdoColorRepository;
use App\repos\PdoSwatchRepository;
use App\repos\PdoColorDetailRepository;

test('Matching: neighborsForCluster returns expected close neighbors for 5746', function(array $ctx) {
    assert_true(!empty($ctx['haveDb']) && $ctx['haveDb'] && $ctx['pdo'] instanceof PDO, 'DB (pdo) required for this test');

    $pdo = $ctx['pdo'];

    // Repos + services (same wiring as controller)
    $colorRepo  = new PdoColorRepository($pdo);
    $swatchRepo = new PdoSwatchRepository($pdo);
    $detailRepo = new PdoColorDetailRepository($pdo);

    $rules    = new Rules();
    $scorer   = new ScoreCandidates($colorRepo);
    $perBrand = new FindBestPerBrand($colorRepo);

    $svc = new MatchingService($colorRepo, $swatchRepo, $detailRepo, $rules, $scorer, $perBrand);

    $anchor = 5746;

    // Try a couple of radii/caps (tight then wider) to ensure we see neighbors.
    // We do *not* include self in neighbors for this check.
    $attempts = [
        ['near_max_de' => 12.0, 'near_cap' => 80],
        ['near_max_de' => 15.0, 'near_cap' => 150],
        ['near_max_de' => 18.0, 'near_cap' => 220],
    ];

    $expected = [6588, 7110, 6587]; // from My Palette neighbors (visual)
    $found    = [];
    $used     = null;

    foreach ($attempts as $i => $opt) {
        $out = $svc->neighborsForCluster($anchor, [
            'near_max_de'  => $opt['near_max_de'],
            'near_cap'     => $opt['near_cap'],
            'include_self' => false,
            'excludeTwins' => true,
        ]);

        $neighbors = $out['neighbors'] ?? [];
        // Keep the first attempt that returns anything
        if (!empty($neighbors)) {
            $found = $neighbors;
            $used  = $opt;
            break;
        }
    }

    // Must have at least some neighbors
    assert_true(!empty($found), 'neighborsForCluster returned no neighbors for 5746 under reasonable thresholds');

    // Sanity: all values should be positive ints, and anchor not included
    foreach ($found as $cid) {
        assert_true(is_int($cid) && $cid > 0, 'neighbor id must be positive int');
        assert_true($cid !== $anchor, 'neighbor list should not contain the anchor itself');
    }

    // Check that our visually expected neighbors appear within top N (order may vary slightly).
    // We'll allow top-12 window to be forgiving across brand density.
    $topN = array_slice($found, 0, 12);
    $topSet = array_fill_keys($topN, true);

    $missing = [];
    foreach ($expected as $cid) {
        if (!isset($topSet[$cid])) $missing[] = $cid;
    }

    // If any are missing, provide a helpful message
    $msg = '';
    if (!empty($missing)) {
        $msg = 'Expected close neighbors missing from top results: '
            . implode(',', $missing)
            . ' | got top=' . implode(',', $topN)
            . ' | used near_max_de=' . ($used['near_max_de'] ?? 'n/a')
            . ' cap=' . ($used['near_cap'] ?? 'n/a');
    }
    assert_true(empty($missing), $msg);
});
