<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Lib\ColorDelta;
use App\Lib\NearWhiteComparator;
use App\Repos\PdoColorRepository;
use App\Repos\PdoSwatchRepository;
use App\Repos\PdoColorDetailRepository;

/**
 * MatchingService = single source of truth for "near/close" logic.
 *
 * Two engines:
 * 1) Cluster engine (palettes / neighbors / friends)
 *    - Input:  cluster_id
 *    - Output: cluster_ids (deduped; one per cluster)
 *    - Metric: 'white' | 'de' via scorer
 *
 * 2) Color/Brand engine (brand-aware matching, closest swatches)
 *    - Input:  color_id
 *    - Output: colors (rows where 'id' is a color_id)
 */
final class MatchingService
{
    public function __construct(
        private PdoColorRepository       $colorRepo,
        private PdoSwatchRepository      $swatchRepo,   // kept for compat; unused here
        private PdoColorDetailRepository $detailRepo,   // kept for compat; unused here
        private Rules                    $rules,        // kept for compat; unused here (Rules live in ScoreCandidates)
        private ScoreCandidates          $scorer,
        private FindBestPerBrand         $perBrand
    ) {}

    /**
     * Raw nearest COLORS for an anchor color_id (ΔE2000; includes cluster_id per row).
     *
     * $opts = [
     *   'brands'       => string[]|string|null, // null = all brands
     *   'limit'        => int,                  // default 10
     *   'excludeTwins' => bool,                 // default true (drop same-cluster)
     * ]
     */
    public function closestRaw(int $anchorColorId, array $opts = []): array
    {
        $anchorColorId = (int)$anchorColorId;
        if ($anchorColorId <= 0) return [];

        $limit        = max(1, (int)($opts['limit'] ?? 10));
        $excludeTwins = !empty($opts['excludeTwins']);
        $brands       = $this->normalizeBrands($opts['brands'] ?? null);

        // Anchor with LAB + cluster
        $a = $this->colorRepo->getColorWithCluster($anchorColorId);
        if (!$a || $a['lab_l'] === null || $a['lab_a'] === null || $a['lab_b'] === null) return [];

        $aL = (float)$a['lab_l']; $aA = (float)$a['lab_a']; $aB = (float)$a['lab_b'];
        $aCluster = isset($a['cluster_id']) ? (int)$a['cluster_id'] : null;

        // Pre-candidate pool (ΔE76 in SQL), then refine to ΔE2000
        $pre = $this->colorRepo->nearestColorCandidates([
            'anchorId'      => $anchorColorId,
            'aL'            => $aL, 'aA' => $aA, 'aB' => $aB,
            'anchorCluster' => $aCluster,
            'brands'        => $brands,
            'excludeTwins'  => $excludeTwins,
            'preLimit'      => max($limit * 5, 50),
        ]) ?: [];

        foreach ($pre as &$r) {
            $r['delta_e2000'] = ColorDelta::deltaE2000(
                $aL, $aA, $aB, (float)$r['lab_l'], (float)$r['lab_a'], (float)$r['lab_b']
            );
            $hex = strtoupper((string)($r['hex6'] ?? ''));
            $r['hex']        = $hex;
            $r['rep_hex']    = $hex;
            $r['color_id']   = (int)$r['color_id'];
            $r['cluster_id'] = isset($r['cluster_id']) ? (int)$r['cluster_id'] : null;
            unset($r['de76_sq'], $r['lab_l'], $r['lab_a'], $r['lab_b'], $r['hex6']);
        }
        unset($r);

        usort($pre, fn($x,$y) => $x['delta_e2000'] <=> $y['delta_e2000']);
        return array_slice($pre, 0, $limit);
    }

    /**
     * Canonical neighbors for a *cluster* (palette/friends meaning of "near").
     *
     * $opts = [
     *   'near_max_de'  => float,            // default 1.6 (tight ΔE00 gate)
     *   'near_cap'     => int,              // default 36
     *   'include_self' => bool,             // default false
     *   'metric'       => 'white'|'de',     // default 'white'
     * ]
     */
public function neighborsForCluster(int $clusterId, array $opts = []): array
{
    $clusterId   = (int)$clusterId;
    $includeSelf = (bool)($opts['include_self'] ?? false);

    // Mirrors My Palette behavior: ΔE00 ranking, near-white awareness,
    // and — IMPORTANT — exclude same-cluster "twins" from the candidate pool.
    $metric      = (string)($opts['metric'] ?? 'white');
    $metric      = ($metric === 'white') ? 'white' : 'de';

    $nearCap     = (int)  ($opts['near_cap']      ?? 24);
    $gateStart   = (float)($opts['near_max_de']   ?? 0.8);
    $gateStep    = (float)($opts['near_step']     ?? 0.1);
    $gateHardMax = (float)($opts['near_hard_max'] ?? 1.6);
    $targetCount = (int)  ($opts['target_count']  ?? 18);

    if ($clusterId <= 0) {
        return [
            'cluster_id' => 0,
            'neighbors'  => [],
            'tuning'     => [
                'near_max_de'  => $gateStart,
                'applied_gate' => $gateStart,
                'hard_max'     => $gateHardMax,
                'near_cap'     => $nearCap,
                'target_count' => $targetCount,
                'metric'       => $metric,
            ],
            'seed_color_id' => null,
            'more_available'=> false,
        ];
    }

    // Resolve a real seed color in the cluster (must have LAB)
    $seedId = $this->colorRepo->getAnyColorIdForClusterWithLab($clusterId);
    if (!$seedId) {
        return [
            'cluster_id'    => $clusterId,
            'neighbors'     => $includeSelf ? [$clusterId] : [],
            'tuning'        => [
                'near_max_de'  => $gateStart,
                'applied_gate' => $gateStart,
                'hard_max'     => $gateHardMax,
                'near_cap'     => $nearCap,
                'target_count' => $targetCount,
                'metric'       => $metric,
            ],
            'seed_color_id' => null,
            'more_available'=> false,
        ];
    }

    // Anchor LAB + cluster
    $a = $this->colorRepo->getColorWithCluster($seedId);
    if (!$a || $a['lab_l'] === null || $a['lab_a'] === null || $a['lab_b'] === null) {
        return [
            'cluster_id'    => $clusterId,
            'neighbors'     => $includeSelf ? [$clusterId] : [],
            'tuning'        => [
                'near_max_de'  => $gateStart,
                'applied_gate' => $gateStart,
                'hard_max'     => $gateHardMax,
                'near_cap'     => $nearCap,
                'target_count' => $targetCount,
                'metric'       => $metric,
            ],
            'seed_color_id' => $seedId,
            'more_available'=> false,
        ];
    }

    $aL = (float)$a['lab_l']; $aA = (float)$a['lab_a']; $aB = (float)$a['lab_b'];
    $aCluster = isset($a['cluster_id']) ? (int)$a['cluster_id'] : null;

    // === KEY CHANGE ===
    // Build the candidate pool with SAME-CLUSTER EXCLUDED,
    // matching My Palette's neighbor chips behavior.
    $pre = $this->colorRepo->nearestColorCandidates([
        'anchorId'      => $seedId,
        'aL'            => $aL, 'aA' => $aA, 'aB' => $aB,
        'anchorCluster' => $aCluster,
        'brands'        => [],            // all brands
        'excludeTwins'  => true,          // <-- was false; exclude same-cluster
        'preLimit'      => 800,           // generous; we’ll gate by ΔE00
    ]) ?: [];

    // Extract candidate color_ids (already excludes seed & twins)
    $pool = [];
    foreach ($pre as $r) {
        $id = (int)($r['color_id'] ?? 0);
        if ($id > 0) $pool[] = $id;
    }
    $pool = array_values(array_unique($pool));
    if (!$pool) {
        $out = $includeSelf ? [$clusterId] : [];
        return [
            'cluster_id'    => $clusterId,
            'neighbors'     => $out,
            'tuning'        => [
                'near_max_de'  => $gateStart,
                'applied_gate' => $gateStart,
                'hard_max'     => $gateHardMax,
                'near_cap'     => $nearCap,
                'target_count' => $targetCount,
                'metric'       => $metric,
            ],
            'seed_color_id' => $seedId,
            'more_available'=> false,
        ];
    }

    // Rank with the same engine My Palette uses (ΔE00 / near-white aware)
    $scored = $this->scorer->run($seedId, $pool, $metric);
    $rows   = is_array($scored['results'] ?? null) ? $scored['results'] : [];

    // Helper: fold rows into UNIQUE cluster_ids under a ΔE00 gate
    $foldClusters = function(float $gate) use ($rows, $clusterId, $nearCap): array {
        $seen = [];
        $out  = [];
        foreach ($rows as $row) {
            $dE = (float)($row['deltaE'] ?? $row['delta_e'] ?? $row['delta_e2000'] ?? 9e9);
            if (!is_finite($dE) || $dE > $gate) continue;

            $color = $row['color'] ?? [];
            $cid   = isset($color['cluster_id']) ? (int)$color['cluster_id'] : 0;
            if ($cid <= 0) continue;

            if (!isset($seen[$cid])) {
                $seen[$cid] = true;
                $out[] = $cid;
                if (count($out) >= $nearCap) break;
            }
        }
        return $out;
    };

    // Count availability within hard ceiling to set more_available
    $availWithinHard = $foldClusters($gateHardMax);

    // Adaptive widen to reach targetCount
    $appliedGate = $gateStart;
    $picked      = [];
    for ($gate = $gateStart; $gate <= $gateHardMax + 1e-9; $gate += $gateStep) {
        if ($gate > $gateHardMax) $gate = $gateHardMax;
        $picked = $foldClusters($gate);
        $appliedGate = $gate;
        if (count($picked) >= $targetCount) break;
        if ($gate >= $gateHardMax) break;
    }

// If we still didn't pick anything within the hard gate, fall back to the best few by score
if (empty($picked)) {
    $seen = [];
    $picked = [];
    foreach ($rows as $row) {
        $color = $row['color'] ?? [];
        $cid   = isset($color['cluster_id']) ? (int)$color['cluster_id'] : 0;
        if ($cid <= 0) continue;
        if (!isset($seen[$cid])) {
            $seen[$cid] = true;
            $picked[] = $cid;
            if (count($picked) >= $nearCap) break;
        }
    }
    // If STILL empty, at least return self when requested
    if (empty($picked) && $includeSelf) {
        $picked = [$clusterId];
    }
    // Reflect that we had to go past the intended gate
    $appliedGate = $gateHardMax;
}




    // Final neighbor cluster list (+self if requested)
    $neighbors = $picked;
    if ($includeSelf) array_unshift($neighbors, $clusterId);

    return [
        'cluster_id'    => $clusterId,
        'neighbors'     => array_values(array_unique($neighbors)),
        'tuning'        => [
            'near_max_de'  => $gateStart,
            'applied_gate' => $appliedGate,
            'hard_max'     => $gateHardMax,
            'near_cap'     => $nearCap,
            'target_count' => $targetCount,
            'metric'       => $metric,
        ],
        'seed_color_id' => $seedId,
        'more_available'=> (count($availWithinHard) > count($picked)),
    ];
}




    /**
     * Expand many anchor clusters into Tier-B cluster groups.
     */
    public function expandClustersToClusterGroups(array $anchorClusterIds, array $opts = []): array
    {
        $ids = array_values(array_filter(array_map('intval', $anchorClusterIds), fn($v)=>$v>0));
        if (!$ids) return ['groups'=>[], 'neighbors_used'=>[]];

        if (!array_key_exists('include_self', $opts)) $opts['include_self'] = true;

        $groups = [];
        $used   = [];
        foreach ($ids as $cid) {
            $res = $this->neighborsForCluster($cid, $opts);
            $groups[]   = $res['neighbors'];
            $used[$cid] = $res['neighbors'];
        }

        return ['groups'=>$groups, 'neighbors_used'=>$used];
    }

    /**
     * Brand engine (SSOT): pick the best COLOR per brand from a seed COLOR.
     */
    public function bestPerBrandFromColor(int $seedColorId, array|string $brands, array $opts = []): array
    {
        $seedColorId = (int)$seedColorId;
        if ($seedColorId <= 0) {
            throw new \InvalidArgumentException('seedColorId must be > 0');
        }

        // Ensure the seed color exists and has LAB
        $a = $this->colorRepo->getColorWithCluster($seedColorId);
        if (!$a || $a['lab_l'] === null || $a['lab_a'] === null || $a['lab_b'] === null) {
            throw new \RuntimeException("Seed color {$seedColorId} missing LAB");
        }

        $metric      = (string)($opts['metric'] ?? 'white');
        $perBrandMax = (int)($opts['perBrandMax'] ?? 500);
        $brandList   = $this->normalizeBrands($brands);

        if (!method_exists($this->perBrand, 'run')) {
            throw new \RuntimeException('FindBestPerBrand service is missing run()');
        }

        return $this->perBrand->run($seedColorId, $brandList, $metric, $perBrandMax);
    }

    /**
     * Convenience wrapper: resolve a seed COLOR from a CLUSTER, then delegate to bestPerBrandFromColor().
     */
    public function bestPerBrandFromCluster(int $seedClusterId, array|string $brands, array $opts = []): array
    {
        $seedClusterId = (int)$seedClusterId;
        if ($seedClusterId <= 0) {
            throw new \InvalidArgumentException('seedClusterId must be > 0');
        }

        // Resolve a real seed color id with LAB from the cluster
        $seedId = $this->colorRepo->getAnyColorIdForClusterWithLab($seedClusterId);
        if (!$seedId) {
            throw new \RuntimeException("No LAB-bearing color found for cluster {$seedClusterId}");
        }

        return $this->bestPerBrandFromColor($seedId, $brands, $opts);
    }

    /* ----------------------
       Private helpers (pure)
       ---------------------- */

    private function normalizeBrands(null|array|string $brands): array
    {
        if ($brands === null) return [];
        if (is_string($brands)) $brands = [$brands];
        $brands = array_values(array_filter(array_map('trim', $brands), fn($b)=>$b!==''));
        return array_values(array_unique($brands));
    }

    /**
     * Anchor-first nearest matches (legacy helper).
     */
    public static function nearest(
        PDO $pdo,
        int $anchorId,
        array $brands = [],
        int $limit = 10,
        int $preLimit = 200
    ): array {
        $repo   = new PdoColorRepository($pdo);

        $anchor = $repo->getById($anchorId);
        if (!$anchor) return [];
        $aL = (float)$anchor->lab_l;
        $aA = (float)$anchor->lab_a;
        $aB = (float)$anchor->lab_b;

        $rows = $repo->nearestColorCandidates([
            'aL' => $aL, 'aA' => $aA, 'aB' => $aB,
            'anchorId' => $anchorId,
            'anchorCluster' => $anchor->cluster_id ?? null,
            'excludeTwins' => true,
            'brands' => $brands,
            'preLimit' => $preLimit,
        ]);

        foreach ($rows as &$r) {
            $L = (float)$r['lab_l'];
            $A = (float)$r['lab_a'];
            $B = (float)$r['lab_b'];

            [$k1, $k2] = NearWhiteComparator::combinedHueFirstKeyForWhiteSeed($aL, $aA, $aB, $L, $A, $B);
            $r['k1'] = $k1;
            $r['k2'] = $k2;
        }
        unset($r);

        usort($rows, fn($x,$y) => ($x['k1'] <=> $y['k1']) ?: ($x['k2'] <=> $y['k2']));
        return array_slice($rows, 0, $limit);
    }

    /** Effective L* from LRV (else raw L*) */
    private static function lFromLrvOrRaw(?float $lrv, float $labL): float {
        if ($lrv === null || !is_finite($lrv)) return $labL;
        $Y = max(0.0, min(1.0, $lrv / 100.0));
        return 116.0 * pow($Y, 1.0/3.0) - 16.0;
    }

    /**
     * Re-rank candidate ROWS for a given seed LAB.
     * Expects rows from PdoColorRepository::nearestColorCandidates (with lab_l/a/b).
     * If rows['_seed_lrv'] is set, we use that to compute the seed's effective L*.
     */
 public static function rankCandidates(
    float $aL, float $aA, float $aB,
    array $rows,
    string $metric = 'white',
    array $tune = []
): array {
    [$aC_raw, $aH] = \App\Lib\NearWhiteComparator::labToCh($aA, $aB);
    $seedLRV = $rows['_seed_lrv'] ?? null;
    $aL_eff  = self::lFromLrvOrRaw(is_numeric($seedLRV) ? (float)$seedLRV : null, $aL);

    // ---- Tunables (with sensible defaults) ----
    $k        = isset($tune['k'])        ? (float)$tune['k']        : 0.75; // chroma→darkness coupling
    $HUE_MAX  = isset($tune['hue_max'])  ? (float)$tune['hue_max']  : 6.0;  // hue gate (deg)
    $GATE_DL  = isset($tune['gate_dl'])  ? (float)$tune['gate_dl']  : 1.0;  // |ΔL*| gate
    $GATE_DC  = isset($tune['gate_dc'])  ? (float)$tune['gate_dc']  : 2.8;  // |ΔC*| gate
    $BIN_DL1  = isset($tune['bin_dl1'])  ? (float)$tune['bin_dl1']  : 0.4;
    $BIN_DL2  = isset($tune['bin_dl2'])  ? (float)$tune['bin_dl2']  : 0.8;
    $BIN_DC1  = isset($tune['bin_dc1'])  ? (float)$tune['bin_dc1']  : 0.8;
    $BIN_DC2  = isset($tune['bin_dc2'])  ? (float)$tune['bin_dc2']  : 1.6;

    $seedNearWhite = ($metric === 'white' && $aL_eff >= 83.0 && $aC_raw <= 8.0);
    $useWhiteMode  = ($metric === 'white' && $seedNearWhite);

    if ($useWhiteMode) {
        // Tight pre-gate for whites (uses effective L* if candidate has LRV)
        $rows = array_values(array_filter($rows, function ($r) use ($aL_eff, $aH, $HUE_MAX, $aC_raw, $GATE_DL, $GATE_DC) {
            if (!isset($r['lab_l'],$r['lab_a'],$r['lab_b'])) return false;
            $L = (float)$r['lab_l']; $A = (float)$r['lab_a']; $B = (float)$r['lab_b'];
            [$C, $H] = \App\Lib\NearWhiteComparator::labToCh($A, $B);
            if (\App\Lib\NearWhiteComparator::hueDiffDeg($aH, $H) > $HUE_MAX) return false;

            $lrv = isset($r['lrv']) && is_numeric($r['lrv']) ? (float)$r['lrv'] : null;
            $Lef = self::lFromLrvOrRaw($lrv, $L);

            if (abs($Lef - $aL_eff) > $GATE_DL) return false;
            if (abs($C   - $aC_raw) > $GATE_DC) return false;
            return true;
        }));
        if (!$rows) return [];
    }

    $scale = 1_000_000; $eps = 1e-12;

    foreach ($rows as &$r) {
        if (!isset($r['lab_l'],$r['lab_a'],$r['lab_b'])) {
            $r['__k1']=$r['__k2']=$r['__k3']=$r['__k4']=$r['__k5']=$r['__k6']=$r['__k7']=$r['__k8']=$r['__k9']=$r['__k10']=PHP_INT_MAX;
            $r['__de00'] = INF;
            continue;
        }

        $L = (float)$r['lab_l']; $A = (float)$r['lab_a']; $B = (float)$r['lab_b'];
        [$C, $H] = \App\Lib\NearWhiteComparator::labToCh($A, $B);

        $de00 = \App\Lib\ColorDelta::deltaE2000($aL, $aA, $aB, $L, $A, $B);
        $r['__de00'] = $de00;

        if ($useWhiteMode) {
            $dh   = \App\Lib\NearWhiteComparator::hueDiffDeg($aH, $H);
            $hBin = ($dh <= 1.0) ? 0 : (($dh <= 2.5) ? 1 : 2);

            $lrv = isset($r['lrv']) && is_numeric($r['lrv']) ? (float)$r['lrv'] : null;
            $Lef = self::lFromLrvOrRaw($lrv, $L);

            $dL = $Lef - $aL_eff;  // + lighter, - darker
            $dC = $C   - $aC_raw;  // + dirtier, - cleaner

            // FIXED SIGN: more chroma = darker; less chroma = lighter
            $t    = $dL - $k * $dC;
            $absT = abs($t);

            $absDL = abs($dL);
            $absDC = abs($dC);

            $lBin = ($absDL <= $BIN_DL1) ? 0 : (($absDL <= $BIN_DL2) ? 1 : 2);
            $cBin = ($absDC <= $BIN_DC1) ? 0 : (($absDC <= $BIN_DC2) ? 1 : 2);
            $tBin = ($absT  <= 0.30)     ? 0 : (($absT  <= 0.60)     ? 1 : 2);

            $hasLRV = isset($r['lrv']) && is_numeric($r['lrv']);

            $r['__k1']  = (int)$hBin;                                  // undertone bin
            $r['__k2']  = (int)$lBin;                                  // ΔL bin
            $r['__k3']  = (int)$cBin;                                  // ΔC bin
            $r['__k4']  = (int)$tBin;                                  // apparent tone bin
            $r['__k5']  = (int)round($absT  * $scale + $eps);          // |t|
            $r['__k6']  = (int)round($absDL * $scale + $eps);          // |ΔL|
            $r['__k7']  = (int)round($absDC * $scale + $eps);          // |ΔC|
            $r['__k8']  = (int)round($de00 * $scale + $eps);           // ΔE00 guardrail
            $r['__k9']  = (int)($hasLRV ? 0 : 1);                      // prefer with LRV
            $r['__k10'] = (int)($r['color_id'] ?? 0);                  // stable tie-break
        } else {
            // Non-white: pure ΔE00
            $r['__k1']  = (int)round(max(0.0,$de00)*$scale + $eps);
            $r['__k2']=$r['__k3']=$r['__k4']=$r['__k5']=$r['__k6']=$r['__k7']=$r['__k8']=$r['__k9']=0;
            $r['__k10'] = (int)($r['color_id'] ?? 0);
        }
    }
    unset($r);

    usort($rows, fn($x,$y) =>
        ($x['__k1']  <=> $y['__k1'])  ?:
        ($x['__k2']  <=> $y['__k2'])  ?:
        ($x['__k3']  <=> $y['__k3'])  ?:
        ($x['__k4']  <=> $y['__k4'])  ?:
        ($x['__k5']  <=> $y['__k5'])  ?:
        ($x['__k6']  <=> $y['__k6'])  ?:
        ($x['__k7']  <=> $y['__k7'])  ?:
        ($x['__k8']  <=> $y['__k8'])  ?:
        ($x['__k9']  <=> $y['__k9'])  ?:
        ($x['__k10'] <=> $y['__k10'])
    );

    foreach ($rows as &$r) { unset($r['__k1'],$r['__k2'],$r['__k3'],$r['__k4'],$r['__k5'],$r['__k6'],$r['__k7'],$r['__k8'],$r['__k9'],$r['__k10']); }
    unset($r);
    return $rows;
}



public function closestRawFullScan(int $anchorColorId, array $opts = []): array
{
    $anchorColorId = (int)$anchorColorId;
    if ($anchorColorId <= 0) return [];

    $limit        = max(1, (int)($opts['limit'] ?? 10));
    $excludeTwins = !empty($opts['excludeTwins']);       // true → exclude same cluster
    $brands       = $this->normalizeBrands($opts['brands'] ?? null);
    $metric       = (string)($opts['metric'] ?? 'white');
    $metric       = ($metric === 'de') ? 'de' : 'white';

    // anchor
    $a = $this->colorRepo->getColorWithCluster($anchorColorId);
    if (!$a || $a['lab_l'] === null || $a['lab_a'] === null || $a['lab_b'] === null) return [];

    $aL = (float)$a['lab_l']; $aA = (float)$a['lab_a']; $aB = (float)$a['lab_b'];
    $aCluster = isset($a['cluster_id']) ? (int)$a['cluster_id'] : null;
    $seedLRV  = isset($a['lrv']) && is_numeric($a['lrv']) ? (float)$a['lrv'] : null;

    // FULL TABLE CANDIDATES (brand-filtered, exclude seed, optionally exclude same cluster)
    $rows = $this->colorRepo->listAllCandidates(
        $anchorColorId,
        $excludeTwins ? $aCluster : null,
        $brands
    );

    if (!$rows) return [];

    // seed LRV for virtual lightness logic
    $rows['_seed_lrv'] = $seedLRV;

    // single source of truth ranking
    $ranked = self::rankCandidates($aL, $aA, $aB, $rows, $metric);

    // decorate results
    foreach ($ranked as &$r) {
        if (isset($r['lab_l'],$r['lab_a'],$r['lab_b'])) {
            $r['delta_e2000'] = \App\Lib\ColorDelta::deltaE2000(
                $aL, $aA, $aB, (float)$r['lab_l'], (float)$r['lab_a'], (float)$r['lab_b']
            );
        } else {
            $r['delta_e2000'] = null;
        }
        $hex = strtoupper((string)($r['hex6'] ?? ($r['hex'] ?? '')));
        $r['hex']        = $hex;
        $r['rep_hex']    = $hex;
        $r['color_id']   = (int)($r['color_id'] ?? $r['id'] ?? 0);
        $r['cluster_id'] = isset($r['cluster_id']) ? (int)$r['cluster_id'] : null;
    }
    unset($r);

    return array_slice($ranked, 0, $limit);
}





}
