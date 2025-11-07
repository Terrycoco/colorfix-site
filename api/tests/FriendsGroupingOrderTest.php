<?php
declare(strict_types=1);

use App\Controllers\FriendsController;

test('friends: group_header/order present and non-decreasing order', function(array $ctx) {
    $pdo = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof PDO, 'pdo required');

    $colorId = (int)$pdo->query("
        SELECT c.id
        FROM colors c
        WHERE c.cluster_id IS NOT NULL
        ORDER BY c.id DESC
        LIMIT 1
    ")->fetchColumn();
    assert_true($colorId > 0, 'need anchor id');

    $ctrl = new FriendsController($pdo);
    $out  = $ctrl->handle(['ids'=>(string)$colorId, 'mode'=>'colors'], []);
    assert_true(isset($out['items']) && is_array($out['items']), 'payload ok');

    $last = -INF;
    foreach ($out['items'] as $row) {
        assert_true(array_key_exists('group_header', $row), 'missing group_header');
        assert_true(array_key_exists('group_order',  $row), 'missing group_order');
        $go = (int)$row['group_order'];
        assert_true($go >= $last, 'group_order must be non-decreasing');
        $last = $go;
    }
});
