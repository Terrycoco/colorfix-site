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

/** Build a FriendsService (unique helper name to avoid collisions) */
function _fs_make_service2(PDO $pdo): FriendsService {
    $colorRepo   = new PdoColorRepository($pdo);
    $swatchRepo  = new PdoSwatchRepository($pdo);
    $detailRepo  = new PdoColorDetailRepository($pdo);
    $clusterRepo = new PdoClusterRepository($pdo);

    // ↓↓↓ add this probe
    if (!method_exists($colorRepo, 'getColorWithCluster')) {
        $rc = new \ReflectionClass($colorRepo);
        error_log('PdoColorRepository file: '.$rc->getFileName());
        error_log('PdoColorRepository methods: '.implode(',', get_class_methods($colorRepo)));
        throw new \RuntimeException('getColorWithCluster() not found on loaded PdoColorRepository');
    }
    // ↑↑↑ remove after green

    $rules    = new Rules();
    $scorer   = new ScoreCandidates($colorRepo);
    $perBrand = new FindBestPerBrand($colorRepo);

    $matching = new MatchingService(
        $colorRepo, $swatchRepo, $detailRepo,
        $rules, $scorer, $perBrand
    );

    return new FriendsService($pdo, $clusterRepo, $matching);
}


/** Pick N real color ids that already have a cluster (unique name to avoid clash) */
function _fs_pick_anchors2(PDO $pdo, int $n): array {
    $st = $pdo->query("SELECT id FROM colors WHERE cluster_id IS NOT NULL LIMIT " . max(1,$n));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
}

/** Compute expected friend clusters (intersection across anchors) using raw SQL */
function _fs_expected_friend_clusters(PDO $pdo, array $anchorClusterIds): array {
    $anchorClusterIds = array_values(array_unique(array_filter(array_map('intval',$anchorClusterIds), fn($v)=>$v>0)));
    if (!$anchorClusterIds) return [];

    // For each anchor cluster, pull union of friends; then intersect across anchors.
    $lists = [];
    foreach ($anchorClusterIds as $cid) {
        $st = $pdo->prepare("
            SELECT friends AS friend_cluster_id
            FROM cluster_friends_union
            WHERE cluster_key = :cid
            GROUP BY friend_cluster_id
        ");
        $st->execute([':cid'=>$cid]);
        $lists[] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
    }
    if (!$lists) return [];
    $inter = $lists[0];
    for ($i=1;$i<count($lists);$i++) $inter = array_values(array_intersect($inter, $lists[$i]));
    return array_values($inter);
}

/** Expand clusters -> swatches with the same filters used by FriendsService */
function _fs_expand_to_swatches(PDO $pdo, array $friendClusters, array $excludeHexUpper, array $excludeClusterIds, array $brands, bool $excludeNeutrals): array {
    if (!$friendClusters) return [];
    $params = [];
    $inClusters = implode(',', array_fill(0, count($friendClusters), '?'));
    $params = array_merge($params, $friendClusters);

    $notInHex = '';
    if ($excludeHexUpper) {
        $excludeHexUpper = array_values(array_unique(array_map('strtoupper',$excludeHexUpper)));
        $notInHex = " AND UPPER(sv.hex6) NOT IN (" . implode(',', array_fill(0, count($excludeHexUpper), '?')) . ") ";
        $params = array_merge($params, $excludeHexUpper);
    }

    $notInCluster = '';
    if ($excludeClusterIds) {
        $excludeClusterIds = array_values(array_unique(array_map('intval',$excludeClusterIds)));
        $notInCluster = " AND ch.cluster_id NOT IN (" . implode(',', array_fill(0, count($excludeClusterIds), '?')) . ") ";
        $params = array_merge($params, $excludeClusterIds);
    }

    $brandSql = '';
    if ($brands) {
        $brands = array_values(array_unique(array_map('trim',$brands)));
        $brandSql = " AND sv.brand IN (" . implode(',', array_fill(0, count($brands), '?')) . ") ";
        $params = array_merge($params, $brands);
    }

    $neutralsClause = $excludeNeutrals
        ? " AND (sv.neutral_cats IS NULL OR sv.neutral_cats = '') "
        : " AND sv.neutral_cats IS NOT NULL AND sv.neutral_cats <> '' ";

    $sql = "
        SELECT sv.*
        FROM cluster_hex ch
        JOIN swatch_view sv ON sv.hex6 = ch.hex6
        WHERE ch.cluster_id IN ($inClusters)
          $notInHex
          $notInCluster
          $neutralsClause
          $brandSql
        ORDER BY sv.hue_cat_order ASC, sv.hcl_c DESC, sv.hcl_l ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ------------------------------------------------------------------ */
/* F-INV1: Items must equal expected (friends(intersection) expanded)  */
/* ------------------------------------------------------------------ */
test('Friends invariants: items == expand(intersection(friends))', function(array $ctx) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $anchors = _fs_pick_anchors2($pdo, 2);
    assert_true(count($anchors) >= 1, 'need at least 1 anchor');

    // Resolve anchors -> clusters + hex exclusions
    $stA = $pdo->prepare("SELECT id, hex6, COALESCE(cluster_id, (SELECT cluster_id FROM cluster_hex WHERE hex6 = colors.hex6 LIMIT 1)) AS cluster_id FROM colors WHERE id IN (" . implode(',', array_fill(0,count($anchors),'?')) . ")");
    $stA->execute($anchors);
    $rowsA = $stA->fetchAll(PDO::FETCH_ASSOC);

    $anchorHex = [];
    $anchorClusters = [];
    foreach ($rowsA as $r) {
        if (!empty($r['hex6'])) $anchorHex[] = strtoupper($r['hex6']);
        if (!empty($r['cluster_id'])) $anchorClusters[] = (int)$r['cluster_id'];
    }
    $anchorHex = array_values(array_unique($anchorHex));
    $anchorClusters = array_values(array_unique(array_filter($anchorClusters, fn($v)=>$v>0)));
    assert_true(count($anchorClusters) >= 1, 'anchors must have clusters');

    // Expected cluster set: intersection(friends(anchor_clusters)) minus anchors
    $friendClusters = _fs_expected_friend_clusters($pdo, $anchorClusters);
    $friendClusters = array_values(array_diff($friendClusters, $anchorClusters)); // drop twins
    // Expand to expected rows (no brand filter, neutrals excluded)
    $expected = _fs_expand_to_swatches($pdo, $friendClusters, $anchorHex, $anchorClusters, [], true);

    // Actual via service (no neighbors)
    $svc = _fs_make_service2($pdo);
    $out = $svc->getFriendSwatches($anchors, [], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => false,
    ]);

    $exHex = array_map(fn($r)=>strtoupper($r['hex6']), $expected);
    $itHex = array_map(fn($r)=>strtoupper($r['hex6']), $out['items'] ?? []);
    sort($exHex); sort($itHex);

    // Allow that expected may contain clusters with no eligible swatches (unlikely),
    // but items must be exactly the expanded rows under the same filters.
    assert_equals($itHex, $exHex, 'items must exactly match expand(intersection(friends))');
});

/* ------------------------------------------------------------------ */
/* F-INV2: Neighbors do NOT leak into items; neighbors bucket only     */
/* ------------------------------------------------------------------ */
test('Friends neighbors: includeCloseMatches broadens TierA (superset) and excludes neighbor clusters', function(array $ctx) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $anchors = _fs_pick_anchors2($pdo, 1);
    assert_true(count($anchors) === 1, 'need exactly 1 anchor for this check');

    $svc = _fs_make_service2($pdo);
    $base = $svc->getFriendSwatches($anchors, [], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => false,
    ]);
    $wNeighbors = $svc->getFriendSwatches($anchors, [], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => true,
        'closeLimit'          => 8,
    ]);

    $baseHex = array_map(fn($r)=>strtoupper($r['hex6']), $base['items'] ?? []);
    $nearHex = array_map(fn($r)=>strtoupper($r['hex6']), $wNeighbors['items'] ?? []);
    sort($baseHex); sort($nearHex);

    // With neighbors on, TierA must be a superset of the base (or equal)
    $missingFromNear = array_values(array_diff($baseHex, $nearHex));
    assert_equals($missingFromNear, [], 'with-neighbors TierA must contain all base items');

    // Neighbor clusters themselves must not appear in TierA
    if (!empty($wNeighbors['neighbors_used'])) {
        $neighborCids = [];
        foreach ($wNeighbors['neighbors_used'] as $arr) {
            foreach ($arr as $n) {
                if (!empty($n['cluster_id'])) $neighborCids[(int)$n['cluster_id']] = true;
            }
        }
        if ($neighborCids) {
            $hexes = $nearHex;
            if ($hexes) {
                $ph = implode(',', array_fill(0, count($hexes), '?'));
                $st = $pdo->prepare("SELECT ch.cluster_id FROM cluster_hex ch WHERE UPPER(ch.hex6) IN ($ph)");
                $st->execute($hexes);
                $itemCids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
                foreach ($itemCids as $cid) {
                    assert_true(!isset($neighborCids[$cid]), 'neighbor cluster leaked into TierA items');
                }
            }
        }
    }
});


/* ------------------------------------------------------------------ */
/* F-INV3: Brand filter applies strictly (all items in allowed brands) */
/* ------------------------------------------------------------------ */
test('Friends brand filter: restricts and preserves invariants', function(array $ctx) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $anchors = _fs_pick_anchors2($pdo, 2);
    assert_true(count($anchors) >= 1, 'need anchors');

    $svc = _fs_make_service2($pdo);
    $outAll = $svc->getFriendSwatches($anchors, [], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => false,
    ]);
    $itemsAll = $outAll['items'] ?? [];
    // If there are no items, skip this brand test (DB dependent)
    if (!$itemsAll) return;

    $brand = (string)$itemsAll[0]['brand'];
    $outBrand = $svc->getFriendSwatches($anchors, [$brand], [
        'excludeNeutrals'     => true,
        'includeCloseMatches' => false,
    ]);

    foreach ($outBrand['items'] ?? [] as $r) {
        if ((string)$r['brand'] !== $brand) {
            throw new RuntimeException('brand filter failed: found '.$r['brand'].' expected '.$brand);
        }
    }

    // And brand-filtered items must be a subset of unfiltered hexes
    $hexAll = array_map(fn($r)=>strtoupper($r['hex6']), $itemsAll);
    $hexBrand = array_map(fn($r)=>strtoupper($r['hex6']), $outBrand['items'] ?? []);
    $diff = array_values(array_diff($hexBrand, $hexAll));
    assert_equals($diff, [], 'brand-filtered items must be subset of unfiltered');
});

/* ------------------------------------------------------------------ */
/* F-INV4: Missing cluster on anchor → empty items (safe default)      */
/* ------------------------------------------------------------------ */
test('Friends missing-cluster anchor yields empty items', function(array $ctx) {
    assert_true($ctx['haveDb'] === true, 'DB required');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    $hex = 'ZZZBAD'; // will change to a real hex value below
    // fabricate a unique hex unlikely to exist
    $rand = strtoupper(substr(md5((string)microtime(true)), 0, 6));
    $hex = $rand;

    $pdo->beginTransaction();
    try {
        // Insert color with hex6 but NO HCL (so no cluster assignment)
// Generate a real 6-char hex (A–F 0–9), and derive matching RGB
$hex = strtoupper(bin2hex(random_bytes(3))); // e.g. "A1B2C3"
assert_true((bool)preg_match('/^[0-9A-F]{6}$/', $hex), 'generated hex invalid');

$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));

// Insert with bound params so hex6 is never treated as NULL; include rgb to satisfy any triggers
$ins = $pdo->prepare("
  INSERT INTO colors (name, brand, code, hex6, r, g, b)
  VALUES ('_FS_TEST', 'ppg', '_FS', :hex, :r, :g, :b)
");
$ins->bindValue(':hex', $hex, \PDO::PARAM_STR);
$ins->bindValue(':r', $r, \PDO::PARAM_INT);
$ins->bindValue(':g', $g, \PDO::PARAM_INT);
$ins->bindValue(':b', $b, \PDO::PARAM_INT);
$ins->execute();

$newId = (int)$pdo->lastInsertId();
assert_true($newId > 0, 'insert failed');


        $svc = _fs_make_service2($pdo);
        $out = $svc->getFriendSwatches([$newId], [], [
            'excludeNeutrals'     => true,
            'includeCloseMatches' => false,
        ]);
        assert_true(isset($out['items']) && is_array($out['items']), 'items missing');
        assert_equals(count($out['items']), 0, 'expected empty items for missing-cluster anchor');

        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
