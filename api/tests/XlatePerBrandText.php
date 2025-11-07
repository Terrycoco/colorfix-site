<?php
declare(strict_types=1);

test('Translate v2: per-brand excludes source brand and hydrates from swatch_view', function(array $ctx) {
    assert_true($ctx['haveDb'] && $ctx['pdo'] instanceof PDO, 'DB required');
    $pdo = $ctx['pdo'];

    // --- TODO: set a real source color id you use on Matches page (any brand) ---
    $SOURCE_ID = 6746;

    // Repos + service
    $colorRepo  = new \App\Repos\PdoColorRepository($pdo);
    $swatchRepo = new \App\Repos\PdoSwatchRepository($pdo);
    $finder     = new \App\Services\FindBestPerBrand($colorRepo);

    // Get source from swatch_view
    $srcMap = $swatchRepo->getByIds([$SOURCE_ID]);
    $src    = $srcMap[$SOURCE_ID] ?? null;
    assert_true(!!$src, 'source swatch not found in swatch_view');

    // Normalize to array and source brand
    $srcArr   = is_object($src) && method_exists($src, 'toArray') ? $src->toArray() : (array)$src;
    $srcBrand = strtolower((string)($srcArr['brand'] ?? ''));
    assert_true($srcBrand !== '' && $srcBrand !== 'true', 'source brand must be real');

    // Build ALL brands except calibration + source brand
    $brandRows = $pdo->query("
        SELECT DISTINCT LOWER(brand) AS b
        FROM swatch_view
        WHERE brand IS NOT NULL AND brand <> ''
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $brands = array_values(array_unique(array_filter(
        array_map(fn($r)=> strtolower(trim((string)($r['b'] ?? ''))), $brandRows),
        fn($b)=> $b !== '' && $b !== 'true' && $b !== $srcBrand
    )));
    assert_true(count($brands) > 0, 'no target brands found');

    // Run per-brand finder (use metric 'white' to mirror UI default)
    $best = $finder->run($SOURCE_ID, $brands, 'white', 5000);
    $rows = (array)($best['results'] ?? []);
    assert_true(count($rows) > 0, 'no per-brand matches returned');

    // Collect IDs to hydrate
    $ids = [];
    foreach ($rows as $r) {
        $cid = (int)($r['id'] ?? 0);
        $b   = strtolower((string)($r['brand'] ?? ''));
        assert_true($b !== $srcBrand, 'result includes source brand');
        if ($cid > 0 && $b !== 'true' && $b !== $srcBrand && $cid !== $SOURCE_ID) {
            $ids[] = $cid;
        }
    }
    $ids = array_values(array_unique($ids));
    assert_true(count($ids) > 0, 'no hydratable result ids');

    // Hydrate from swatch_view and assert hex6 presence
    $byId = $swatchRepo->getByIds($ids);
    foreach ($ids as $cid) {
        $row = $byId[$cid] ?? null;
        assert_true(!!$row, "missing hydrated swatch for id=$cid");
        $arr = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
        $hex6 = strtoupper(ltrim((string)($arr['hex6'] ?? ''), '#'));
        assert_true((bool)preg_match('/^[0-9A-F]{6}$/', $hex6), "hex6 missing/invalid for id=$cid");
    }
});
