<?php
declare(strict_types=1);

test('Swatch repo always returns hex6', function($ctx){
    $pdo = $ctx['pdo']; if (!$pdo) return;
    $repo = new \App\repos\PdoSwatchRepository($pdo);
    // use a handful of real ids from your DB
    $rows = $repo->getByIds([1,2,3]);
    foreach ($rows as $r) {
        assert_true(isset($r['hex6']) && is_string($r['hex6']) && strlen($r['hex6']) === 6, 'hex6 missing/bad');
    }
});
