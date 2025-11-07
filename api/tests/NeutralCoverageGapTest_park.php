<?php
declare(strict_types=1);

/**
 * Neutral coverage gap finder (grid scan).
 * - Uses ONLY your live category_definitions (type='neutral', active=1).
 * - Normalizes negative hue bounds and wrap-around.
 * - You control scan region via CONFIG below.
 * - Fails with a compact summary of sectors that contain uncovered grid points,
 *   plus a few sample (H,C,L) triples to inspect.
 *
 * Edit CONFIG values to change the grid; this test does NOT assume any envelope.
 */

// ---------- CONFIG (edit freely) ----------
const NDGC_H_START = 0.0;     // degrees
const NDGC_H_END   = 360.0;   // degrees (exclusive in loop)
const NDGC_H_STEP  = 5.0;     // degrees

const NDGC_C_START = 0.0;     // chroma
const NDGC_C_END   = 8.0;     // chroma
const NDGC_C_STEP  = 0.5;

const NDGC_L_START = 88.0;    // lightness
const NDGC_L_END   = 100.0;   // lightness
const NDGC_L_STEP  = 0.5;

const NDGC_SECTOR_WIDTH = 30.0;   // for summaries
const NDGC_MAX_EXAMPLES = 30;     // how many uncovered HCL samples to show
// -----------------------------------------

test('neutral defs: grid gap scan (reports uncovered HCL bins)', function($ctx) {

    if (!$ctx['haveDb'] || !$ctx['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available for gap scan');
    }
    $pdo = $ctx['pdo'];

    $defs = $pdo->query("
        SELECT id, name, hue_min, hue_max,
               chroma_min, chroma_max, light_min, light_max,
               active, calc_only, sort_order
        FROM category_definitions
        WHERE active = 1 AND type = 'neutral'
        ORDER BY sort_order, name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$defs) {
        throw new RuntimeException('No active neutral definitions found');
    }

    // helpers: normalize hue; range checks w/ null bounds
    $norm = static function(float $x): float {
        $y = fmod($x, 360.0);
        return ($y < 0) ? $y + 360.0 : $y;
    };
    $inRange = static function(float $x, $min, $max): bool {
        if ($min === null && $max === null) return true;
        if ($min !== null && $x < (float)$min) return false;
        if ($max !== null && $x > (float)$max) return false;
        return true;
    };
    $hueOk = static function(float $H, $min, $max) use ($norm): bool {
        if ($min === null && $max === null) return true;
        $Hn = $norm($H);
        $A  = ($min === null) ? null : $norm((float)$min);
        $B  = ($max === null) ? null : $norm((float)$max);
        if ($A !== null && $B !== null) {
            return ($A <= $B) ? ($Hn >= $A && $Hn <= $B)
                              : ($Hn >= $A || $Hn <= $B); // wrap or negative min
        }
        if ($A !== null) return ($Hn >= $A) || ($A == 0.0);
        if ($B !== null) return ($Hn <= $B) || ($B >= 359.999);
        return true;
    };

    $covers = function(float $H, float $C, float $L) use ($defs, $inRange, $hueOk): bool {
        foreach ($defs as $d) {
            if (!$hueOk($H, $d['hue_min'], $d['hue_max'])) continue;
            if (!$inRange($C, $d['chroma_min'],  $d['chroma_max'])) continue;
            if (!$inRange($L, $d['light_min'],   $d['light_max']))  continue;
            return true;
        }
        return false;
    };

    $uncovered = [];
    $bySector  = []; // sector => ['total'=>n, 'miss'=>m]

    for ($H = NDGC_H_START; $H < NDGC_H_END - 1e-9; $H += NDGC_H_STEP) {
        $sec = (int)(floor($H / NDGC_SECTOR_WIDTH) * NDGC_SECTOR_WIDTH);
        for ($C = NDGC_C_START; $C <= NDGC_C_END + 1e-9; $C += NDGC_C_STEP) {
            for ($L = NDGC_L_START; $L <= NDGC_L_END + 1e-9; $L += NDGC_L_STEP) {
                $bySector[$sec]['total'] = ($bySector[$sec]['total'] ?? 0) + 1;
                if (!$covers($H, $C, $L)) {
                    $bySector[$sec]['miss'] = ($bySector[$sec]['miss'] ?? 0) + 1;
                    if (count($uncovered) < NDGC_MAX_EXAMPLES) {
                        $uncovered[] = [
                            'H' => round($H, 2),
                            'C' => round($C, 2),
                            'L' => round($L, 2),
                            'sector' => sprintf('%03d–%03d', $sec, $sec + NDGC_SECTOR_WIDTH),
                        ];
                    }
                }
            }
        }
    }

    // Summarize (only sectors with any miss)
    ksort($bySector);
    $summary = [];
    $total = 0; $miss = 0;
    foreach ($bySector as $sec => $agg) {
        $t = (int)($agg['total'] ?? 0);
        $m = (int)($agg['miss']  ?? 0);
        if ($m > 0) {
            $summary[] = [
                'hue_sector' => sprintf('%03d–%03d', $sec, $sec + NDGC_SECTOR_WIDTH),
                'miss' => $m,
                'total' => $t,
                'pct' => $t ? round(100.0 * $m / $t, 2) : 0.0,
            ];
        }
        $total += $t; $miss += $m;
    }

    if ($miss > 0) {
        throw new RuntimeException(
            'Neutral defs gaps: '. $miss .' of '. $total .' grid points uncovered; '.
            'sectors='. json_encode($summary) .'; examples='. json_encode($uncovered)
        );
    }
});
