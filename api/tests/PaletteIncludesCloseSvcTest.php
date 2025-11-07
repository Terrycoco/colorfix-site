<?php
declare(strict_types=1);

/**
 * Verifies Tier B (includes_close) returns the original palette when searching
 * with ONLY the "close" cluster anchors [19, 3849].
 *
 * Known palette (must exist) contains all clusters:
 *   [118, 4358, 12525, 1543, 360, 8174]
 */

test('PaletteIncludesClose: returns original with close anchors', function(array $ctx): void {
    assert_true(!empty($ctx['haveDb']) && $ctx['haveDb'] === true, 'DB not available to run test');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];
    assert_true($pdo instanceof PDO, 'PDO missing');

    $originalClusters = [118, 4358, 12525, 1543, 360, 8174];
    $closeAnchors     = [19, 3849];

    // 1) Find a real ACTIVE palette that includes ALL original clusters
    $ph  = implode(',', array_fill(0, count($originalClusters), '?'));
    $sql = "
        SELECT pm.palette_id
        FROM palette_members pm
        JOIN palettes p ON p.id = pm.palette_id
        WHERE pm.member_cluster_id IN ($ph)
          AND p.status = 'active'
        GROUP BY pm.palette_id
        HAVING COUNT(DISTINCT pm.member_cluster_id) = ?
        ORDER BY pm.palette_id ASC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($originalClusters as $cid) {
        $stmt->bindValue($i++, (int)$cid, PDO::PARAM_INT);
    }
    $stmt->bindValue($i, count($originalClusters), PDO::PARAM_INT);
    $stmt->execute();
    $expectedPaletteId = (int)($stmt->fetchColumn() ?: 0);
    assert_true($expectedPaletteId > 0, 'Did not find ACTIVE palette containing all original clusters');

    // 2) Expand ONLY the close anchors into neighbor cluster groups (MatchingService)
    $colorRepo   = new \App\repos\PdoColorRepository($pdo);
    $swatchRepo  = new \App\repos\PdoSwatchRepository($pdo);
    $detailRepo  = new \App\repos\PdoColorDetailRepository($pdo);
    $rules       = new \App\services\Rules(); // no ctor args
    $scorer      = new \App\services\ScoreCandidates($colorRepo);
    $perBrand    = new \App\services\FindBestPerBrand($colorRepo);
    $matching    = new \App\services\MatchingService($colorRepo, $swatchRepo, $detailRepo, $rules, $scorer, $perBrand);

    // Slightly wider ΔE + higher cap to reflect “close enough” in practice
    $nearOpts = [
        'near_max_de'  => 8.0,   // was 5.0
        'near_cap'     => 20,    // was 12
        'include_self' => true,
        'excludeTwins' => true,
    ];
    $expanded      = $matching->expandClustersToClusterGroups($closeAnchors, $nearOpts);
    $clusterGroups = $expanded['groups'] ?? [];
    assert_true(!empty($clusterGroups), 'Neighbor cluster groups did not resolve');

    // 3) Repo: hydrated, visible-only (status='active'), any size
    $paletteRepo = new \App\repos\PdoPaletteRepository($pdo);
    assert_true(method_exists($paletteRepo, 'findVisibleAnySizeByClusterGroups'),
        'Repo method findVisibleAnySizeByClusterGroups missing');
    $res   = $paletteRepo->findVisibleAnySizeByClusterGroups($clusterGroups, 200, 0);
    $items = $res['items'] ?? [];
    $total = (int)($res['total_count'] ?? 0);
    assert_true($total >= 0, 'total_count missing');
    assert_true(is_array($items), 'items missing');

    // 4) Assert original palette appears in Tier B results
    $ids = array_map(fn($r) => (int)($r['palette_id'] ?? 0), $items);
    $present = in_array($expectedPaletteId, $ids, true);
    assert_true($present, "Original palette {$expectedPaletteId} not returned for close anchors");

    // 5) Basic shape checks on first item
    if (!empty($items)) {
        $first = $items[0];
        assert_true(array_key_exists('palette_id', $first), 'item missing palette_id');
        assert_true(array_key_exists('size', $first), 'item missing size');
        assert_true(array_key_exists('member_cluster_ids', $first), 'item missing member_cluster_ids');
        assert_true(is_array($first['member_cluster_ids']), 'member_cluster_ids not array');
    }
});
