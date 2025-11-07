<?php
declare(strict_types=1);

use App\Repos\PdoCategoryRepository;

test('CategoryRepo: fetchByType returns visible rows', function ($ctx) {
    if (!$ctx['haveDb']) {
        throw new RuntimeException('DB not available for test');
    }

    $pdo = $ctx['pdo'];
    $repo = new PdoCategoryRepository($pdo);

    // Hue categories (visible only; calc_only excluded)
    $rows = $repo->fetchByType('hue');
    assert_true(is_array($rows), 'Expected array from fetchByType(hue)');
    if (count($rows) > 0) {
        $row = $rows[0];
        assert_true(array_key_exists('id', $row), 'id missing');
        assert_true(array_key_exists('name', $row), 'name missing');
        assert_true((int)$row['id'] > 0, 'id not int');
    }

    // Combined types example (hue + neutral in one list)
    $combo = $repo->fetchByTypes(['hue', 'neutral']);
    assert_true(is_array($combo), 'Expected array for fetchByTypes');
    if (count($combo) > 0) {
        foreach ($combo as $r) {
            assert_true(in_array($r['type'], ['hue', 'neutral'], true), 'Unexpected type in fetchByTypes');
        }
    }
});
