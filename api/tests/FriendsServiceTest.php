<?php
declare(strict_types=1);

use App\repos\PdoClusterRepository;
use App\repos\PdoColorRepository;
use App\repos\PdoSwatchRepository;
use App\repos\PdoColorDetailRepository;
use App\services\FriendsService;
use App\services\MatchingService;
use App\services\Rules;
use App\services\ScoreCandidates;
use App\services\FindBestPerBrand;

// Build service via a closure to avoid global function name collisions
$makeFriends = static function(PDO $pdo): FriendsService {
    $colorRepo   = new PdoColorRepository($pdo);
    $swatchRepo  = new PdoSwatchRepository($pdo);
    $detailRepo  = new PdoColorDetailRepository($pdo);
    $clusterRepo = new PdoClusterRepository($pdo);

    $rules    = new Rules();
    $scorer   = new ScoreCandidates($colorRepo);        // REQUIRED arg
    $perBrand = new FindBestPerBrand($colorRepo);

    $matching = new MatchingService(
        $colorRepo, $swatchRepo, $detailRepo,
        $rules, $scorer, $perBrand
    );

    return new FriendsService($pdo, $clusterRepo, $matching);
};

// helper to grab 1–2 ids with clusters
$pickAnchors = static function(PDO $pdo, int $want = 2): array {
    $st = $pdo->query("SELECT id FROM colors WHERE cluster_id IS NOT NULL LIMIT " . max(1, $want));
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
    if (!$ids) return [];
    if ($want === 2 && count($ids) === 1) $ids[] = $ids[0];
    return $ids;
};

/* ------------------------------------------------------------------ */
test('FriendsService T1: empty ids → empty items', function(array $ctx) use ($makeFriends) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */ $pdo = $ctx['pdo'];

    $svc = $makeFriends($pdo);
    $out = $svc->getFriendSwatches([], []);

    assert_true(isset($out['items']) && is_array($out['items']), 'items array missing');
    assert_equals(count($out['items']), 0, 'items should be empty');
});

/* ------------------------------------------------------------------ */
test('FriendsService T2: friends no-close → items[] shape', function(array $ctx) use ($makeFriends, $pickAnchors) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */ $pdo = $ctx['pdo'];

    $anchors = $pickAnchors($pdo, 2);
    assert_true(count($anchors) >= 1, 'need at least one color with a cluster');

    $svc = $makeFriends($pdo);
    $out = $svc->getFriendSwatches($anchors, [], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => false
    ]);

    assert_true(isset($out['items']) && is_array($out['items']), 'items array missing');
});

/* ------------------------------------------------------------------ */
test('FriendsService T3: friends with-close → items[] + neighbors_used?', function(array $ctx) use ($makeFriends, $pickAnchors) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */ $pdo = $ctx['pdo'];

    $anchors = $pickAnchors($pdo, 1);
    assert_true(count($anchors) >= 1, 'need at least one color with a cluster');

    $svc = $makeFriends($pdo);
    $out = $svc->getFriendSwatches([$anchors[0]], [], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => true,
        'closeLimit'          => 5
    ]);

    assert_true(isset($out['items']) && is_array($out['items']), 'items array missing');
    if (isset($out['neighbors_used'])) {
        assert_true(is_array($out['neighbors_used']), 'neighbors_used must be array when present');
    }
});
