<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\Repos\PdoPaletteRepository;

test('Palette: includes_all (current v2 path) returns a palette that includes all anchors', function(array $ctx) {
    assert_true(($ctx['haveDb'] ?? false) === true, 'DB not available for tests');
    /** @var PDO $pdo */
    $pdo = $ctx['pdo'];

    // Known brochure palette (cluster IDs)
    $anchors = [118, 4358, 12525, 1543, 360, 8174];

    $repo = new PdoPaletteRepository($pdo);

    // For now we test the superset behavior (no neighbors yet).
    // Later, when includes_close is implemented, this test can call the controller/service.
    $res  = $repo->findIncludingAllClusters($anchors, 'A', 'active', 3, 7, 300, 0);

    assert_true(is_array($res), 'Result should be array');
    assert_true(isset($res['items']) && is_array($res['items']), 'items should be array');

    $found = false;
    foreach ($res['items'] as $p) {
        $members = array_values(array_unique(array_map('intval', $p['member_cluster_ids'] ?? [])));
        if (empty(array_diff($anchors, $members))) { $found = true; break; }
    }

    if (!$found) {
        $dbg = [
            'anchors' => $anchors,
            'first3'  => array_slice(array_map(fn($p)=>$p['member_cluster_ids'] ?? [], $res['items']), 0, 3),
            'count'   => count($res['items']),
        ];
        throw new RuntimeException('No palette contained all anchors; debug=' . json_encode($dbg));
    }

    assert_true($found, 'Expected a palette that includes all anchors');
});
