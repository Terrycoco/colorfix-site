<?php
declare(strict_types=1);

namespace App\lib;

/**
 * CIEDE2000 ΔE for LAB colors.
 * Returns a float distance: lower = closer match.
 */
final class ColorDelta
{
    public static function deltaE2000(
        float $L1, float $a1, float $b1,
        float $L2, float $a2, float $b2
    ): float {
        $deg2rad = M_PI / 180;
        $rad2deg = 180 / M_PI;

        $avgLp = 0.5 * ($L1 + $L2);
        $C1 = hypot($a1, $b1);
        $C2 = hypot($a2, $b2);
        $avgC = 0.5 * ($C1 + $C2);

        $G = 0.5 * (1 - sqrt(pow($avgC, 7) / (pow($avgC, 7) + pow(25, 7))));
        $a1p = (1 + $G) * $a1;
        $a2p = (1 + $G) * $a2;

        $C1p = hypot($a1p, $b1);
        $C2p = hypot($a2p, $b2);
        $avgCp = 0.5 * ($C1p + $C2p);

        $h1p = ($b1 === 0.0 && $a1p === 0.0) ? 0.0 : fmod(atan2($b1, $a1p) * $rad2deg + 360.0, 360.0);
        $h2p = ($b2 === 0.0 && $a2p === 0.0) ? 0.0 : fmod(atan2($b2, $a2p) * $rad2deg + 360.0, 360.0);

        $dLp = $L2 - $L1;
        $dCp = $C2p - $C1p;

        $dhp = 0.0;
        if ($C1p * $C2p !== 0.0) {
            $dh = $h2p - $h1p;
            if ($dh > 180)      $dh -= 360;
            elseif ($dh < -180) $dh += 360;
            $dhp = $dh;
        }
        $dHp = 2 * sqrt($C1p * $C2p) * sin(($dhp * $deg2rad) / 2);

        $avgHp = 0.0;
        if ($C1p * $C2p === 0.0) {
            $avgHp = $h1p + $h2p;
        } else {
            $hSum = $h1p + $h2p;
            if (abs($h1p - $h2p) > 180) {
                $avgHp = ($hSum < 360) ? ($hSum + 360) * 0.5 : ($hSum - 360) * 0.5;
            } else {
                $avgHp = 0.5 * $hSum;
            }
        }

        $T = 1
            - 0.17 * cos(($avgHp - 30) * $deg2rad)
            + 0.24 * cos((2 * $avgHp) * $deg2rad)
            + 0.32 * cos((3 * $avgHp + 6) * $deg2rad)
            - 0.20 * cos((4 * $avgHp - 63) * $deg2rad);

        $Sl = 1 + (0.015 * pow($avgLp - 50, 2)) / sqrt(20 + pow($avgLp - 50, 2));
        $Sc = 1 + 0.045 * $avgCp;
        $Sh = 1 + 0.015 * $avgCp * $T;

        $deltaTheta = 30 * exp(-pow(($avgHp - 275) / 25, 2));
        $Rc = 2 * sqrt(pow($avgCp, 7) / (pow($avgCp, 7) + pow(25, 7)));
        $Rt = -$Rc * sin(2 * $deltaTheta * $deg2rad);

        $dL = $dLp / $Sl;
        $dC = $dCp / $Sc;
        $dH = $dHp / $Sh;

        return sqrt(($dL * $dL) + ($dC * $dC) + ($dH * $dH) + ($Rt * $dC * $dH));
    }
}
