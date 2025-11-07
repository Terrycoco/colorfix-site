<?php
declare(strict_types=1);

/**
 * SwissCoffeeIdProbeTest.php
 *
 * Prints (to php_error.log) the exact IDs and labels returned by
 * MatchingService::bestPerBrandFromCluster(..., ['de']) using the white-aware metric.
 * Confirms which value is color_id vs cluster_id, and verifies the pick is a DE "White".
 */

const SEED_BEHR_SWISS_COFFEE_CLUSTER_ID = 8947;
const BRAND_CODE_DE                     = 'de';

test('Probe: per-brand (de) IDs + white check', function(array $ctx): void {
    assert_true(!empty($ctx['haveDb']) && $ctx['haveDb'] === true, 'DB not available');
    /** @var \PDO|null $pdo */
    $pdo = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof \PDO, 'No PDO from db.php');

    $ms = buildMS($pdo);

    $res = $ms->bestPerBrandFromCluster(
        SEED_BEHR_SWISS_COFFEE_CLUSTER_ID,
        [BRAND_CODE_DE],
        ['metric' => 'white', 'perBrandMax' => 500]
    );
    $items = $res['results'] ?? [];
    assert_true(is_array($items) && count($items) > 0, 'No per-brand results');

    // Find the actual DE row by the color's own brand
    $row = null;
    foreach ($items as $r) {
        $actualBrand = strtolower((string)($r['color']['brand'] ?? ''));
        if ($actualBrand === BRAND_CODE_DE) { $row = $r; break; }
    }
    assert_true($row !== null, "No row where color.brand='".BRAND_CODE_DE."'");

    $pickedColorId   = (int)($row['id'] ?? 0);
    $pickedClusterId = (int)($row['color']['cluster_id'] ?? 0);
    $pickedCode      = (string)($row['color']['code'] ?? '');
    $pickedName      = (string)($row['color']['name'] ?? '');

    // Label checks straight from DB
    $colorRow = fetchColorById($pdo, $pickedColorId);
    $clusterAny = fetchAnyColorInCluster($pdo, $pickedClusterId);
    $isWhiteCluster = clusterLooksWhite($pdo, $pickedClusterId);

    error_log('[probe] picked color_id='.$pickedColorId.' | db.brand='.strtolower((string)($colorRow['brand']??''))
        .' | code='.(string)($colorRow['code']??'').' | name='.(string)($colorRow['name']??'')
        .' | cluster_id='.$pickedClusterId);
    error_log('[probe] cluster sample: brand='.(string)($clusterAny['brand']??'').' code='.(string)($clusterAny['code']??'')
        .' name='.(string)($clusterAny['name']??''));
    error_log('[probe] cluster_is_white='.($isWhiteCluster ? 'true' : 'false'));

    // Assertions so the signal is clear:
    assert_true($pickedColorId > 0, 'Missing color_id');
    assert_true($pickedClusterId > 0, 'Missing cluster_id');
    assert_true(strtolower((string)($colorRow['brand']??'')) === BRAND_CODE_DE, 'Chosen color is not DE');
    assert_true($isWhiteCluster, 'Chosen cluster does not appear in neutrals "Whites"');
});

/* -------- helpers -------- */

function buildMS(\PDO $pdo) {
    $repoColor  = new \App\Repos\PdoColorRepository($pdo);
    $repoSwatch = class_exists(\App\Repos\PdoSwatchRepository::class) ? new \App\Repos\PdoSwatchRepository($pdo) : null;
    $repoDetail = class_exists(\App\Repos\PdoColorDetailRepository::class) ? new \App\Repos\PdoColorDetailRepository($pdo) : null;
    $rules      = class_exists(\App\Services\Rules::class) ? new \App\Services\Rules() : null;
    $scorer     = new \App\Services\ScoreCandidates($repoColor);
    $perBrand   = class_exists(\App\Services\FindBestPerBrand::class) ? new \App\Services\FindBestPerBrand($repoColor) : null;

    return new \App\Services\MatchingService($repoColor, $repoSwatch, $repoDetail, $rules, $scorer, $perBrand);
}

function fetchColorById(\PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT id,brand,code,name,cluster_id FROM colors WHERE id = :id LIMIT 1");
    $st->execute([':id'=>$id]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
}

function fetchAnyColorInCluster(\PDO $pdo, int $cid): array {
    $st = $pdo->prepare("SELECT brand,code,name FROM colors WHERE cluster_id = :cid ORDER BY hcl_c DESC, lab_l DESC LIMIT 1");
    $st->execute([':cid'=>$cid]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
}

function clusterLooksWhite(\PDO $pdo, int $cid): bool {
    // swatch_view should carry neutral_cats; treat any row in this cluster tagged Whites as true
    $st = $pdo->prepare("SELECT neutral_cats FROM swatch_view WHERE cluster_id = :cid LIMIT 1");
    $st->execute([':cid'=>$cid]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$r) return false;
    $cats = (string)($r['neutral_cats'] ?? '');
    return stripos($cats, 'Whites') !== false;
}
