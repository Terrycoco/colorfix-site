<?php
declare(strict_types=1);

/**
 * Hue coverage gap finder (grid scan on hue; fixed sample C/L list you control).
 * - Uses ONLY your live category_definitions (type='hue', active=1).
 * - Normalizes negative hue bounds and wrap-around.
 * - Reports sectors that have uncovered samples.
 */

// ---------- CONFIG ----------
const HDGC_H_START = 0.0;
const HDGC_H_END   = 360.0;
const HDGC_H_STEP  = 5.0;

const HDGC_SAMPLE_CL = [
    // (C, L) pairs to probe per hue; edit freely
    [ 8.0, 65.0 ],
    [20.0, 50.0 ],
    [40.0, 60.0 ],
];
// -----------------------------------------

test('hue defs: grid gap scan (reports uncovered hue sectors)', function($ctx) {

    if (!$ctx['haveDb'] || !$ctx['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available for hue gap scan');
    }
    $pdo = $ctx['pdo'];

    $defs = $pdo->query("
        SELECT id, name, hue_min, hue_max,
               chroma_min, chroma_max,
               light_min, light_max,
               active, calc_only, sort_order
        FROM category_definitions
        WHERE active = 1 AND type = 'hue'
        ORDER BY sort_order, name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$defs) {
        throw new RuntimeException('No active hue definitions found');
    }

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
                              : ($Hn >= $A || $Hn <= $B);
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
    $bySector  = []; // 30° sectors
    for ($H = HDGC_H_START; $H < HDGC_H_END - 1e-9; $H += HDGC_H_STEP) {
        $sec = (int)(floor($H / 30.0) * 30);
        foreach (HDGC_SAMPLE_CL as $pair) {
            [$C, $L] = $pair;
            $bySector[$sec]['total'] = ($bySector[$sec]['total'] ?? 0) + 1;
            if (!$covers($H, (float)$C, (float)$L)) {
                $bySector[$sec]['miss'] = ($bySector[$sec]['miss'] ?? 0) + 1;
                if (count($uncovered) < 30) {
                    $uncovered[] = [
                        'H' => round($H, 2),
                        'C' => (float)$C,
                        'L' => (float)$L,
                        'sector' => sprintf('%03d–%03d', $sec, $sec+30),
                    ];
                }
            }
        }
    }

    ksort($bySector);
    $summary = [];
    $total = 0; $miss = 0;
    foreach ($bySector as $sec => $agg) {
        $t = (int)($agg['total'] ?? 0);
        $m = (int)($agg['miss']  ?? 0);
        if ($m > 0) {
            $summary[] = [
                'hue_sector' => sprintf('%03d–%03d', $sec, $sec+30),
                'miss' => $m,
                'total' => $t,
                'pct' => $t ? round(100.0 * $m / $t, 2) : 0.0,
            ];
        }
        $total += $t; $miss += $m;
    }

    if ($miss > 0) {
        throw new RuntimeException(
            'Hue defs gaps: '.$miss.' of '.$total.' samples uncovered; '.
            'sectors='.json_encode($summary).'; examples='.json_encode($uncovered)
        );
    }
});
