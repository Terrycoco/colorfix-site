<?php
declare(strict_types=1);

use App\Repos\PdoCategoryRepository;
use App\Services\CategoriesService;

test('CategoriesService: basic recalcAll runs without error', function ($ctx) {
    if (!$ctx['haveDb']) {
        throw new RuntimeException('DB not available for test');
    }

    $pdo = $ctx['pdo'];
    $repo = new PdoCategoryRepository($pdo);
    $svc  = new CategoriesService($repo);

    // small batch, skip canonicalization for speed in tests
    $summary = $svc->recalcAll(50, false);

    assert_true(is_array($summary), 'Summary not array');
    assert_true(isset($summary['total_colors']), 'Missing total_colors');
    assert_true(isset($summary['processed']), 'Missing processed');
    assert_true($summary['processed'] >= 0, 'Processed negative');
});

test('CategoriesService: calc_only affects assignment but not visible CSV', function ($ctx) {
    if (!$ctx['haveDb']) {
        throw new RuntimeException('DB not available for test');
    }

    $pdo = $ctx['pdo'];
    $repo = new PdoCategoryRepository($pdo);
    $svc  = new CategoriesService($repo);

    $defs = $repo->fetchCategoryDefinitions();

    // Use a plausible HCL to hit some defs; exact hit set doesn't matter for the rule
    $h = 40.0; $c = 30.0; $l = 70.0;

    // Access resolveCategories via Reflection to validate calc_only behavior
    $ref  = new ReflectionClass($svc);
    $meth = $ref->getMethod('resolveCategories');
    $meth->setAccessible(true);

    [$catIds, $csvs] = $meth->invoke($svc, $h, $c, $l, $defs);

    assert_true(is_array($catIds), 'catIds not array');
    assert_true(is_array($csvs), 'csvs not array');

    // Any calc_only definition that matched must NOT appear in the visible CSVs
    foreach ($defs as $type => $deflist) {
        foreach ($deflist as $def) {
            $matched = in_array((int)$def['id'], $catIds, true);
            if ($matched && (int)$def['calc_only'] === 1) {
                $visible = str_contains($csvs[$type] ?? '', $def['name']);
                assert_true(!$visible, "calc_only category {$def['name']} leaked into visible CSV for type {$type}");
            }
        }
    }
});
