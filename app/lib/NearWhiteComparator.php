<?php
declare(strict_types=1);

namespace App\lib;

/**
 * Experimental distance for near-whites.
 * Does NOT replace ΔE2000; it's a helper we can use in tests or as a tiebreaker.
 * Seed-gated: only applies near-white logic when the SEED looks like a white.
 */
final class NearWhiteComparator
{
    // Near-white gate (seed must satisfy BOTH)
    private const L_MIN = 85.0; // seed is "near-white" if L* >= 85
    private const C_MAX = 8.0;  // and C* <= 8

    // 🔧 Tunable weights for near-whites
    private const W_L_DARK   = 1.10; // penalty when candidate is darker
    private const W_L_LIGHT  = 0.20; // penalty when candidate is lighter
    private const W_C_GAIN   = 1.60; // penalty when candidate has more chroma
    private const W_C_LOSS   = 0.60; // penalty when candidate has less chroma
    private const W_HUE      = 1.00; // hue/undertone weight (with chord-length penalty)
    private const W_INTERACT = 1.60; // extra penalty when darker AND more chroma

    /**
     * Primary: ΔE2000; Secondary: near-white-aware score.
     * Sort by the returned tuple: [$deltaE2000, $whiteAware]
     */
    public static function combinedKeyForWhiteSeed(
        float $L1, float $a1, float $b1,
        float $L2, float $a2, float $b2
    ): array {
        $dE = \App\lib\ColorDelta::deltaE2000($L1,$a1,$b1,$L2,$a2,$b2);
        $nw = self::whiteAwareDistance($L1,$a1,$b1,$L2,$a2,$b2);
        return [$dE, $nw];
    }

    /**
     * Near-white-aware distance:
     * - Penalize getting DARKER and gaining CHROMA more than the reverse.
     * - Add an interaction penalty when the candidate is BOTH darker and more chromatic.
     * - Hue penalty uses chord length: 2*C_avg*sin(Δh/2) so large hue gaps matter.
     * Lower return value = "closer".
     */
    public static function whiteAwareDistance(
        float $L1, float $a1, float $b1,   // seed LAB
        float $L2, float $a2, float $b2    // candidate LAB
    ): float {
        [$C1, $h1] = self::labToCh($a1, $b1);
        [$C2, $h2] = self::labToCh($a2, $b2);
        $avgC = 0.5 * ($C1 + $C2);

        // Gate by SEED only (your use case)
        $nearWhite = ($L1 >= self::L_MIN) && ($C1 <= self::C_MAX);

        // Signed deltas
        $dL = $L2 - $L1;                  // negative = darker than seed
        $dC = $C2 - $C1;                  // positive = more chroma than seed
        $dh = self::hueDiffDeg($h1, $h2); // 0..180 (degrees)

        if ($nearWhite) {
            // One-sided components (direction-aware)
            $darker     = max(0.0, -$dL);
            $lighter    = max(0.0,  $dL);
            $chromaGain = max(0.0,  $dC);
            $chromaLoss = max(0.0, -$dC);

            // Interaction: only when both conditions happen
            $interaction = self::W_INTERACT * ($darker * $chromaGain);

            // Hue penalty as chord length on chroma circle (scaled by avgC)
            $huePenalty = self::W_HUE * (2.0 * $avgC * sin(($dh * M_PI / 180.0) / 2.0));

            return
                self::W_L_DARK  * $darker +
                self::W_L_LIGHT * $lighter +
                self::W_C_GAIN  * $chromaGain +
                self::W_C_LOSS  * $chromaLoss +
                $huePenalty +
                $interaction;
        }

        // Fallback outside near-white: balanced L/C + modest hue
        return abs($dL) + abs($dC) + 0.5 * ($dh / 180.0);
    }

    public static function labToCh(float $a, float $b): array
    {
        $C = hypot($a, $b);
        $h = $C > 0 ? fmod(atan2($b, $a) * 180 / M_PI + 360.0, 360.0) : 0.0;
        return [$C, $h];
    }

    public static function hueDiffDeg(float $h1, float $h2): float
    {
        $d = abs($h1 - $h2);
        return ($d > 180.0) ? 360.0 - $d : $d;
    }


// In App\lib\NearWhiteComparator (add this method)

public static function combinedHueFirstKeyForWhiteSeed(
    float $L1, float $a1, float $b1,
    float $L2, float $a2, float $b2
): array {
    // compute seed/cand chroma + hue
    [$C1, $h1] = self::labToCh($a1, $b1);
    [$C2, $h2] = self::labToCh($a2, $b2);

    $nearWhite = ($L1 >= self::L_MIN) && ($C1 <= self::C_MAX);

    $de00 = \App\lib\ColorDelta::deltaE2000($L1,$a1,$b1,$L2,$a2,$b2);
    if ($nearWhite) {
        $dh = self::hueDiffDeg($h1, $h2);   // 0..180 degrees, shorter arc
        return [$dh, $de00];                // hue first, then ΔE00
    }
    // fallback: keep your current philosophy when seed isn't a near-white
    return [$de00, 0.0];
}





}
