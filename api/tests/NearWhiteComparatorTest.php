<?php
declare(strict_types=1);

use App\repos\PdoColorRepository;
use App\lib\NearWhiteComparator;

// seed=1829 (Greek Villa), better=28280 (Milk Glass), worse=27074 (Abstract White)
test('near-white: Milk Glass beats Abstract White by whiteAwareDistance', function () {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available; ensure /api/db.php defines $pdo.');
    }

    $repo = new PdoColorRepository($GLOBALS['pdo']);

    $seed   = $repo->getById(1829);
    $better = $repo->getById(28280);
    $worse  = $repo->getById(27074);

    if (!$seed || !$better || !$worse) {
        throw new RuntimeException('One or more colors not found; verify IDs.');
    }

    $d_better = NearWhiteComparator::whiteAwareDistance(
        $seed->L(), $seed->a(), $seed->b(),
        $better->L(), $better->a(), $better->b()
    );
    $d_worse  = NearWhiteComparator::whiteAwareDistance(
        $seed->L(), $seed->a(), $seed->b(),
        $worse->L(),  $worse->a(),  $worse->b()
    );

    if (!($d_better < $d_worse)) {
        throw new RuntimeException(sprintf(
            'Expected near-white better<worse but got: better=%.6f, worse=%.6f',
            $d_better, $d_worse
        ));
    }
});
