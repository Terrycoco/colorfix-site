<?php
declare(strict_types=1);

use App\Controllers\FriendsController;

test('friends: CSV brands parsed and does not error', function(array $ctx) {
    $pdo = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof PDO, 'pdo required');

    $colorId = (int)$pdo->query("
        SELECT c.id
        FROM colors c
        WHERE c.cluster_id IS NOT NULL
        ORDER BY c.id DESC
        LIMIT 1
    ")->fetchColumn();
    assert_true($colorId > 0, 'need a color id');

    $brands = $pdo->query("
        SELECT DISTINCT LOWER(brand) b
        FROM colors
        WHERE brand IS NOT NULL AND brand <> ''
        LIMIT 2
    ")->fetchAll(PDO::FETCH_COLUMN);

    $csv = is_array($brands) && count($brands) >= 2 ? implode(',', $brands) : '';

    $ctrl = new FriendsController($pdo);
    $q = ['ids'=>(string)$colorId, 'mode'=>'colors'];
    if ($csv !== '') $q['brands'] = $csv;

    $out = $ctrl->handle($q, []);
    assert_true(is_array($out) && array_key_exists('items', $out), 'payload shape ok with CSV brands');
    assert_true(is_array($out['items']), 'items is array');
});
