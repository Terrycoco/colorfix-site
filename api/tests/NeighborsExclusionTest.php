<?php
declare(strict_types=1);

use App\Controllers\FriendsController;

test('friends: neighbors excluded from items; addendum shape ok', function(array $ctx) {
    $pdo = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof PDO, 'pdo required');

    // pick up to 2 anchors with clusters to force intersection behavior
    $ids = $pdo->query("
        SELECT c.id
        FROM colors c
        WHERE c.cluster_id IS NOT NULL
        ORDER BY c.id DESC
        LIMIT 2
    ")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(!empty($ids), 'need at least one anchor id');

    // collect anchor hexes
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT UPPER(hex6) FROM colors WHERE id IN ($ph)");
    $st->execute($ids);
    $anchorHex = array_filter(array_map('strtoupper', $st->fetchAll(PDO::FETCH_COLUMN)));

    $ctrl = new FriendsController($pdo);
    $out  = $ctrl->handle([
        'ids' => implode(',', array_map('strval', $ids)),
        'mode'=> 'colors',
        'include_neighbors' => '1',
    ], []);

    assert_true(is_array($out) && array_key_exists('items', $out), 'payload shape');
    assert_true(is_array($out['items']), 'items is array');

    // Verify no anchor hex leaks into items
    if (!empty($anchorHex)) {
        foreach ($out['items'] as $row) {
            $hx = strtoupper((string)($row['hex6'] ?? ''));
            assert_true($hx === '' || !in_array($hx, $anchorHex, true), 'items must not include anchor hexes');
        }
    }

    // neighbors_used is optional; if present ensure basic shape
    if (array_key_exists('neighbors_used', $out) && $out['neighbors_used'] !== null) {
        assert_true(is_array($out['neighbors_used']), 'neighbors_used must be array/object if present');
    }
});
