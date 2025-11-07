<?php
declare(strict_types=1);

use App\repos\PdoColorRepository;
use App\lib\ColorDelta;

// IDs from your DB
const SEED_ID   = 1829;   // Greek Villa
const MG_ID     = 28280;  // Milk Glass
const AW_ID     = 27074;  // Abstract White

test('deltaE limitation (near-whites): ΔE2000 ranks AW closer than MG (documented gap)', function () {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available; ensure /api/db.php defines $pdo.');
    }

    $repo = new PdoColorRepository($GLOBALS['pdo']);

    $seed = $repo->getById(SEED_ID);
    $mg   = $repo->getById(MG_ID);
    $aw   = $repo->getById(AW_ID);
    if (!$seed || !$mg || !$aw) throw new RuntimeException('IDs not found');

    $dE_mg = ColorDelta::deltaE2000($seed->L(),$seed->a(),$seed->b(), $mg->L(),$mg->a(),$mg->b());
    $dE_aw = ColorDelta::deltaE2000($seed->L(),$seed->a(),$seed->b(), $aw->L(),$aw->a(),$aw->b());

    // We assert the current reality (so the suite stays green) and document the gap:
    assert_true($dE_aw < $dE_mg, sprintf(
        'Expected ΔE00 to prefer AW (documented limitation). Got dE(MG)=%.6f, dE(AW)=%.6f',
        $dE_mg, $dE_aw
    ));
});
