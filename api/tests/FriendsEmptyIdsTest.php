<?php
declare(strict_types=1);

use App\Controllers\FriendsController;

test('friends: empty ids returns empty items', function(array $ctx) {
    $pdo  = $ctx['pdo'] ?? null;
    assert_true($pdo instanceof PDO, 'pdo is required');

    $ctrl = new FriendsController($pdo);
    $out  = $ctrl->handle(['mode' => 'colors'], []);
    assert_true(is_array($out), 'payload must be array');
    assert_true(array_key_exists('items', $out), 'must include items');
    assert_true(is_array($out['items']), 'items is array');
    assert_true(count($out['items']) === 0, 'items should be empty for no ids');
});
