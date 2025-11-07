<?php
declare(strict_types=1);

/**
 * TraceMatchingPathTest.php
 *
 * No code changes. Just trace what MatchingService actually returns and
 * what the DB says about that pick — brand, ids, and whether the cluster is a White.
 * Uses local variables (no constants) to avoid cross-file collisions.
 */

test('TRACE: MatchingService bestPerBrandFromCluster (de, white metric)', function(array $ctx): void {
    assert_true(!empty($ctx['haveDb']) && $ctx['haveDb'] === true, 'DB not available');
    /** @var \PDO|null $pdo */
    $pdo = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof \PDO, 'No PDO from db.php');

    $seedClusterId = 8947;  // Behr Swiss Coffee cluster
    $brandCodeDe   = 'de';  // exact colors.brand code

    $ms = buildMS($pdo);

    // Resolve seed to a real color id (what MatchingService uses internally)
    $seedId = getAnyColorIdForClusterWithLab($pdo, $seedClusterId);
    assert_true($seedId > 0, 'Could not resolve a seed color with LAB from the seed cluster');
    $seedRow = fetchColorById($pdo, $seedId);
    error_log('[trace] seed_color: '.json_encode($seedRow, JSON_UNESCAPED_SLASHES));

    // Call SSOT with metric=white, brand=de
    $res = $ms->bestPerBrandFromCluster($seedClusterId, [$brandCodeDe], [
        'metric' => 'white',
        'perBrandMax' => 500
    ]);

    // Dump raw result payload for visibility
    error_log('[trace] raw_result: '.json_encode($res, JSON_UNESCAPED_SLASHES));

    $items = $res['results'] ?? [];
    assert_true(is_array($items) && count($items) > 0, 'No results returned from bestPerBrandFromCluster');

    // Find the row where the ACTUAL color.brand is 'de' (not just the label field)
    $row = null;
    foreach ($items as $r) {
        $actualBrand = strtolower((string)($r['color']['brand'] ?? ''));
        if ($actualBrand === $brandCodeDe) { $row = $r; break; }
    }

    if ($row === null) {
        // Log each row to see what brands actually came back
        foreach ($items as $i => $r) {
            error_log('[trace] item['.$i.']: '.json_encode($r, JSON_UNESCAPED_SLASHES));
        }
        assert_true(false, "No result where color.brand='{$brandCodeDe}'");
    }

    $pickedColorId   = (int)($row['id'] ?? 0);
    $pickedClusterId = (int)($row['color']['cluster_id'] ?? 0);
    assert_true($pickedColorId > 0, 'Picked row missing color id');
    assert_true($pickedClusterId > 0, 'Picked row missing cluster id');

    // Cross-check both ids directly from DB
    $pickedColorRow   = fetchColorById($pdo, $pickedColorId);
    $clusterAny       = fetchAnyColorInCluster($pdo, $pickedClusterId);
    $clusterCats      = fetchNeutralCatsForCluster($pdo, $pickedClusterId);

    error_log('[trace] picked_color (DB): '.json_encode($pickedColorRow, JSON_UNESCAPED_SLASHES));
    error_log('[trace] picked_cluster sample (DB): '.json_encode($clusterAny, JSON_UNESCAPED_SLASHES));
    error_log('[trace] picked_cluster neutral_cats: '.var_export($clusterCats, true));

    // Quick inventory: how many DE white clusters exist?
    $counts = countDeWhiteClusters($pdo, $brandCodeDe);
    error_log('[trace] inventory: de_white_clusters.count='.$counts['clusters'].' de_white_colors.count='.$counts['colors']);

    // Assertions are deliberately light; we’re inspecting facts:
    assert_true(strtolower((string)($pickedColorRow['brand'] ?? '')) === $brandCodeDe, 'Chosen color is not DE per DB');
    assert_true(stripos($clusterCats ?? '', 'Whites') !== false, 'Chosen cluster is not tagged as a White in swatch_view');
});

/* ---------- helpers (no app changes) ---------- */
if (!function_exists('buildMS')) {
    function buildMS(\PDO $pdo) {
        $repoColor  = new \App\Repos\PdoColorRepository($pdo);
        $repoSwatch = class_exists(\App\Repos\PdoSwatchRepository::class) ? new \App\Repos\PdoSwatchRepository($pdo) : null;
        $repoDetail = class_exists(\App\Repos\PdoColorDetailRepository::class) ? new \App\Repos\PdoColorDetailRepository($pdo) : null;
        $rules      = class_exists(\App\Services\Rules::class) ? new \App\Services\Rules() : null;
        $scorer     = new \App\Services\ScoreCandidates($repoColor);
        $perBrand   = class_exists(\App\Services\FindBestPerBrand::class) ? new \App\Services\FindBestPerBrand($repoColor) : null;
        return new \App\Services\MatchingService($repoColor, $repoSwatch, $repoDetail, $rules, $scorer, $perBrand);
    }
}
if (!function_exists('getAnyColorIdForClusterWithLab')) {
    function getAnyColorIdForClusterWithLab(\PDO $pdo, int $clusterId): int {
        $st = $pdo->prepare("SELECT id FROM colors WHERE cluster_id = :cid AND lab_l IS NOT NULL AND lab_a IS NOT NULL AND lab_b IS NOT NULL ORDER BY hcl_c DESC, lab_l DESC LIMIT 1");
        $st->execute([':cid'=>$clusterId]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : 0;
    }
}
if (!function_exists('fetchColorById')) {
    function fetchColorById(\PDO $pdo, int $id): array {
        $st = $pdo->prepare("SELECT id,brand,code,name,cluster_id FROM colors WHERE id = :id LIMIT 1");
        $st->execute([':id'=>$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('fetchAnyColorInCluster')) {
    function fetchAnyColorInCluster(\PDO $pdo, int $cid): array {
        $st = $pdo->prepare("SELECT brand,code,name FROM colors WHERE cluster_id = :cid ORDER BY hcl_c DESC, lab_l DESC LIMIT 1");
        $st->execute([':cid'=>$cid]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('fetchNeutralCatsForCluster')) {
    function fetchNeutralCatsForCluster(\PDO $pdo, int $cid): ?string {
        $st = $pdo->prepare("SELECT neutral_cats FROM swatch_view WHERE cluster_id = :cid LIMIT 1");
        $st->execute([':cid'=>$cid]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? (string)($r['neutral_cats'] ?? '') : null;
    }
}
if (!function_exists('countDeWhiteClusters')) {
    function countDeWhiteClusters(\PDO $pdo, string $brandCodeDe): array {
        $clusters = $pdo->query("SELECT COUNT(DISTINCT sv.cluster_id) FROM swatch_view sv WHERE LOWER(sv.brand)='{$brandCodeDe}' AND sv.cluster_id IS NOT NULL AND sv.neutral_cats LIKE '%Whites%'")->fetchColumn() ?: 0;
        $colors   = $pdo->query("SELECT COUNT(*) FROM swatch_view sv WHERE LOWER(sv.brand)='{$brandCodeDe}' AND sv.cluster_id IS NOT NULL AND sv.neutral_cats LIKE '%Whites%'")->fetchColumn() ?: 0;
        return ['clusters' => (int)$clusters, 'colors' => (int)$colors];
    }
}
