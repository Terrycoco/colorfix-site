<?php
declare(strict_types=1);

use App\Controllers\FriendsController;

test('friends: colors vs neutrals exclusivity + shape', function(array $ctx) {
    $pdo = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof PDO, 'pdo required');

    // pick a real color id that has a cluster_id
    $colorId = (int)$pdo->query("
        SELECT c.id
        FROM colors c
        WHERE c.cluster_id IS NOT NULL
        ORDER BY c.id DESC
        LIMIT 1
    ")->fetchColumn();
    assert_true($colorId > 0, 'need at least one color with cluster_id');

    $ctrl = new FriendsController($pdo);

    // COLORS (non-neutrals only)
    $outC = $ctrl->handle(['ids'=>(string)$colorId, 'mode'=>'colors', 'include_neighbors'=>'1'], []);
    assert_true(isset($outC['items']) && is_array($outC['items']), 'colors payload shape ok');

    foreach ($outC['items'] as $row) {
        $nc = trim((string)($row['neutral_cats'] ?? ''));
        assert_true($nc === '', 'colors mode must not return rows with neutral_cats');
        // basic swatch fields coming from swatch_view
        foreach (['id','name','brand','hex6','cluster_id','hcl_h','hcl_c','hcl_l'] as $k) {
            assert_true(array_key_exists($k, $row), "missing swatch_view field: $k");
        }
        // grouping metadata from SQL
        foreach (['group_header','group_order'] as $k) {
            assert_true(array_key_exists($k, $row), "missing grouping field: $k");
        }
    }

    // NEUTRALS (neutrals only)
    $outN = $ctrl->handle(['ids'=>(string)$colorId, 'mode'=>'neutrals'], []);
    assert_true(isset($outN['items']) && is_array($outN['items']), 'neutrals payload shape ok');

    foreach ($outN['items'] as $row) {
        $nc = trim((string)($row['neutral_cats'] ?? ''));
        assert_true($nc !== '', 'neutrals mode must return only rows with neutral_cats');
    }
});
