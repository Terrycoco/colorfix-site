<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';


if (!function_exists('pickClass')) {
    function pickClass(string $caps, string $lower): string {
        if (class_exists($caps))  return $caps;
        if (class_exists($lower)) return $lower;
        throw new RuntimeException("Neither $caps nor $lower found");
    }
}



test('Palette: controller browseByAnchors (clusters) returns palettes including all anchors', function(array $ctx) {
    assert_true(($ctx['haveDb'] ?? false) === true, 'DB not available for tests');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    // Pick classes with flexible casing
    $PaletteController     = pickClass(\App\Controllers\PaletteController::class,     \App\controllers\PaletteController::class);
    $PdoPaletteRepository  = pickClass(\App\Repos\PdoPaletteRepository::class,       \App\repos\PdoPaletteRepository::class);
    $PaletteTierAService   = pickClass(\App\Services\PaletteTierAService::class,     \App\services\PaletteTierAService::class);

    // Build TierA service, as required by PaletteController’s constructor
    $palRepo = new $PdoPaletteRepository($pdo);
    $tierA   = new $PaletteTierAService($pdo, $palRepo);

    // Controller expects (PaletteTierAService $svc, PDO $pdo)
    $controller = new $PaletteController($tierA, $pdo);

    // Known brochure palette (cluster IDs)
    $anchors = [118, 4358, 12525, 1543, 360, 8174];

    $in = [
        'exact_anchor_cluster_ids' => $anchors,
        'match_mode' => 'includes', // includes_close later
        'size_min'   => 3,
        'size_max'   => 7,
        'limit'      => 300,
        'offset'     => 0,
        'tier'       => 'A',
        'status'     => 'active',
    ];

    $out = $controller->browseByAnchors($in);

    assert_true(is_array($out), 'out should be array');
    assert_true(isset($out['items']) && is_array($out['items']), 'items should be array');
    assert_true(isset($out['total_count']), 'total_count missing');
    assert_true(array_key_exists('counts_by_size', $out), 'counts_by_size missing');
    assert_true(array_key_exists('limit', $out), 'limit missing');
    assert_true(array_key_exists('next_offset', $out), 'next_offset missing');

    // Verify at least one palette contains ALL anchors (superset check)
    $found = false;
    foreach ($out['items'] as $p) {
        $members = array_values(array_unique(array_map('intval', $p['member_cluster_ids'] ?? [])));
        if (empty(array_diff($anchors, $members))) { $found = true; break; }
    }
    if (!$found) {
        $dbg = [
            'anchors' => $anchors,
            'first3'  => array_slice(array_map(fn($p)=>$p['member_cluster_ids'] ?? [], $out['items']), 0, 3),
            'count'   => count($out['items']),
        ];
        throw new RuntimeException('No palette contained all anchors; debug=' . json_encode($dbg));
    }
});
