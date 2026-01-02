<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * ClusterQuantize
 *
 * Source of truth for rounding LAB→HCL to discrete cluster buckets.
 * - Uses ColorCompute::labToLch() for LCH conversion
 * - Applies standard "round-to-nearest" (floor(x+0.5)) semantics
 * - Hue wraps into 0..359 (int)
 */
final class ClusterQuantize
{
    /** @return array{h_r:int,c_r:int,l_r:int} */
    public static function quantizeLab(float $L, float $a, float $b): array
    {
        $lch = ColorCompute::labToLch($L, $a, $b);
        $h = self::hueRound($lch['h']);      // 0..359
        $c = (int)floor($lch['C'] + 0.5);
        $l = (int)floor($lch['L'] + 0.5);
        return ['h_r'=>$h, 'c_r'=>$c, 'l_r'=>$l];
    }

    /** @return array{h_r:int,c_r:int,l_r:int} */
    public static function roundTriplet(float $h, float $c, float $l): array
    {
        $h_r = self::hueRound($h);
        $c_r = (int)floor($c + 0.5);
        $l_r = (int)floor($l + 0.5);
        return ['h_r'=>$h_r, 'c_r'=>$c_r, 'l_r'=>$l_r];
    }

    private static function hueRound(float $h): int
    {
        // round-to-nearest int and wrap into [0,360)
        $hr = (int)floor($h + 0.5);
        $hr = ($hr % 360 + 360) % 360;
        return $hr;
    }
}
