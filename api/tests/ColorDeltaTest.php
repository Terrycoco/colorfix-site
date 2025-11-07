<?php
declare(strict_types=1);

use App\lib\ColorDelta;

/**
 * Pure unit tests for ΔE2000. No DB needed.
 */
test('deltaE: identical colors have distance 0', function () {
    $d = ColorDelta::deltaE2000(50, 0, 0, 50, 0, 0);
    assert_approx($d, 0.0, 1e-9, 'Identical LAB should be zero distance');
});

test('deltaE: symmetry d(a,b) == d(b,a)', function () {
    $d1 = ColorDelta::deltaE2000(60, 20, -10, 55, 18, -12);
    $d2 = ColorDelta::deltaE2000(55, 18, -12, 60, 20, -10);
    assert_approx($d1, $d2, 1e-9, 'Distance must be symmetric');
});

test('deltaE: closer vs farther sanity', function () {
    $seedL=60; $seedA=10; $seedB=5;
    $closer = ColorDelta::deltaE2000($seedL, $seedA, $seedB, 58, 11, 4);
    $farther= ColorDelta::deltaE2000($seedL, $seedA, $seedB, 50, 20, 15);
    assert_true($closer < $farther, 'Closer should be smaller ΔE than farther');
});
