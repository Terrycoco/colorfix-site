<?php
declare(strict_types=1);

namespace App\services;

use App\entities\Color;
use App\Lib\ColorDelta;
use App\Lib\NearWhiteComparator;

final class Rules
{
    private const WHITE_L_MIN = 85.0; // seed is "near-white" if L* >= 85
    private const WHITE_C_MAX = 8.0;  // and C* = hypot(a,b) <= 8

    /**
     * Composite sort key for (seed, candidate).
     * Always starts with ΔE2000, then appends rule-based keys if applicable.
     * Lower is better across all elements.
     *
     * $mode:
     *  - 'delta' → ΔE only
     *  - 'auto'  → ΔE + apply rules that match (currently: near-white)
     */
    public static function compositeKey(Color $seed, Color $cand, string $mode = 'auto'): array
    {
        // 1) Base metric (always first)
        $dE = ColorDelta::deltaE2000(
            $seed->L(), $seed->a(), $seed->b(),
            $cand->L(), $cand->a(), $cand->b()
        );
        if ($mode === 'delta') return [$dE];

        $key = [$dE];

        // 2) Near-white rule (seed-gated)
        if (self::isNearWhiteSeed($seed)) {
            [, $nw] = NearWhiteComparator::combinedKeyForWhiteSeed(
                $seed->L(), $seed->a(), $seed->b(),
                $cand->L(), $cand->a(), $cand->b()
            );
            $key[] = $nw; // lower = closer under near-white logic
        }

        // (Add future rules here; each pushes another number)
        return $key;
    }

    private static function isNearWhiteSeed(Color $seed): bool
    {
        $L = $seed->L();
        $C = hypot($seed->a(), $seed->b());
        return ($L >= self::WHITE_L_MIN) && ($C <= self::WHITE_C_MAX);
    }
}
