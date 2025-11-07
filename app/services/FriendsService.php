<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Repos\PdoClusterRepository;
use App\Services\MatchingService;

/**
 * FriendsService (v2, with TierB provenance tagging + adaptive neighbors)
 *
 * - Base rule: friends are clusters from cluster_friends (union inside each anchor group).
 * - If includeCloseMatches=true, expand each anchor's GROUP with neighbor clusters
 *   (via MatchingService->neighborsForCluster with adaptive widen).
 * - Neighbors NEVER appear in the main "items" list; they are returned separately
 *   under "neighbors_used" for UI display (chips).
 *
 * Non-breaking per-item fields:
 *   - tierb (bool)
 *   - tierb_source_cluster_id (int|null)
 */
final class FriendsService
{
    private const TBL_CLUSTER_FRIENDS = 'cluster_friends';

    public function __construct(
        private PDO $pdo,
        private PdoClusterRepository $clusters,
        private MatchingService $matching
    ) {}

    /**
     * @param int[]     $ids     color ids (anchors)
     * @param string[]  $brands  optional brand filter (DB codes)
     * @param array     $opts    [
     *   'onlyNeutrals'        => bool (default false)
     *   'excludeNeutrals'     => bool (default false; ignored if onlyNeutrals=true)
     *   'includeCloseMatches' => bool (default false)
     *   // neighbor cluster expansion (for Tier-A seed expansion)
     *   'near_cap'            => int    (default 18)    // max cluster neighbors
     *   'near_max_de'         => float  (default 0.7)   // starting ΔE00 gate
     *   'near_step'           => float  (default 0.10)  // widen step
     *   'near_hard_max'       => float  (default 1.20)  // ceiling
     *   'target_count'        => int    (default 12)    // desired count
     *   'near_mode'           => 'white'|'de' (default 'white')
     *   // chip list (“Also checked…”)
     *   'closeLimit'          => int    (default 12)    // HARD cap for chips (1..16)
     *   'near_chip_max_de'    => float  (default 1.0)   // only show chips <= this ΔE00
     *   // debug
     *   'debug'               => int/bool (optional)
     * ]
     * @return array{
     *   items: array<int, array>,
     *   neighbors_used?: array<string, array<int, array>>,
     *   neighbors_meta?: array<string, mixed>,
     *   _debug_groups?: array<string, mixed>
     * }
     */
    public function getFriendSwatches(array $ids, array $brands = [], array $opts = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) return ['items' => []];


 

        // Flags / neutrals mode (from $opts only)
        $includeCloseMatches = !empty($opts['includeCloseMatches']);
        $onlyNeutrals        = !empty($opts['onlyNeutrals']);
        $excludeNeutrals     = !empty($opts['excludeNeutrals']);
        if ($onlyNeutrals) $excludeNeutrals = false;

        // Neighbor-adaptive tuning (cluster expansion)
        $nearCap      = max(1, (int)($opts['near_cap']      ?? 18));
        $nearMaxDe    =       (float)($opts['near_max_de']   ?? 0.7);
        $nearStep     =       (float)($opts['near_step']     ?? 0.10);
        $nearHardMax  =       (float)($opts['near_hard_max'] ?? 1.20);
        $targetCount  = max(1,(int)  ($opts['target_count']  ?? 12));



        $nm = $opts['near_mode'] ?? 'white';
        if (!in_array($nm, ['white','de'], true)) {
            $nm = 'white';
        }
        $nearMode = (string)$nm;

        // Chip list limits (“Also checked…”)
        $closeLimit   = min(max(1, (int)($opts['closeLimit'] ?? 12)), 16);
        $chipMaxDe    = (float)($opts['near_chip_max_de'] ?? 1.0);


        // 1) Resolve anchors → id, hex6, cluster_id
        $anchors = $this->clusters->getAnchorsByColorIds($ids);
        if (!$anchors) return ['items'=>[]];

        $anchorHex      = [];
        $anchorClusters = [];
        foreach ($anchors as $a) {
            if (!empty($a['hex6']))       $anchorHex[] = strtoupper((string)$a['hex6']);
            if (!empty($a['cluster_id'])) $anchorClusters[] = (int)$a['cluster_id'];
        }
        $anchorHex      = array_values(array_unique($anchorHex));
        $anchorClusters = array_values(array_unique(array_filter($anchorClusters, fn($v)=>$v>0)));
        if (!$anchorClusters) return ['items'=>[]];

        // 2) Build per-anchor GROUP (anchor ∪ neighbors when enabled)
        $seenAnchorClusters     = [];
        $groupFriendsLists      = [];
        $neighborsUsed          = []; // swatch-level chips for “Also checked…”
        $neighborMeta           = []; // per-anchor: applied gate + more_available + chip meta
        $neighborClusterIdsAll  = []; // union: clusters to EXCLUDE from final items
        $neighborHexAllUpper    = []; // union: hexes to EXCLUDE from final items
        $groupProv              = []; // provenance for Tier-B tagging
        $debugGroups            = []; // optional debug: which seeds we used per anchor

        foreach ($anchors as $a) {
            $anchorId = (int)$a['id'];
            $cid      = (int)($a['cluster_id'] ?? 0);
            if ($cid <= 0) continue;

            if (isset($seenAnchorClusters[$cid])) continue;
            $seenAnchorClusters[$cid] = true;

            // Start group with the anchor only
            $group = [$cid];
            $neighborToAnchor = [];
            $debugGroups[$anchorId] = ['anchor_cluster'=>$cid, 'seeds'=>[$cid], 'neighbors'=>[]];

            if ($includeCloseMatches && $anchorId > 0) {
                // (A) cluster-level expansion that actually changes the seed set
                $nRes = $this->matching->neighborsForCluster($cid, [
                    'include_self'  => true,
                    'near_cap'      => $nearCap,
                    'near_max_de'   => $nearMaxDe,
                    'near_step'     => $nearStep,
                    'near_hard_max' => $nearHardMax,
                    'target_count'  => $targetCount,
                    'metric'        => $nearMode,
                ]);

                $neighborMeta[$anchorId] = [
                    'applied_gate'   => (float)($nRes['tuning']['applied_gate'] ?? ($nRes['tuning']['near_max_de'] ?? 0.0)),
                    'hard_max'       => (float)($nRes['tuning']['hard_max']     ?? $nearHardMax),
                    'more_available' => (bool)($nRes['more_available']          ?? false),
                ];

                $nc = array_values(array_filter((array)($nRes['neighbors'] ?? []), fn($x)=> (int)$x>0));
                foreach ($nc as $ncid) {
                    if ($ncid === $cid) continue;               // skip self (already in)
                    $group[] = $ncid;                            // <-- expands Tier-A seeds
                    $neighborToAnchor[$ncid] = $cid;
                    $neighborClusterIdsAll[$ncid] = true;
                    $debugGroups[$anchorId]['neighbors'][] = $ncid;
                    $debugGroups[$anchorId]['seeds'][]     = $ncid;
                }

                // (B) swatch-level neighbor chips for the “Also checked…” UI (tight + sliced)
                $near = $this->matching->closestRaw($anchorId, [
                    'brands'       => null,
                    // ask for a little more than we will show, so we can filter by ΔE and still fill the cap
                    'limit'        => max($closeLimit * 2, 24),
                    'excludeTwins' => true,
                ]);

                if ($near) {
                    // keep only the tight chips (ΔE00 <= $chipMaxDe), then slice to cap
                    $near = array_values(array_filter($near, function ($n) use ($chipMaxDe) {
                        $d = isset($n['delta_e2000']) ? (float)$n['delta_e2000'] : INF;
                        return $d <= $chipMaxDe;
                    }));
                    $moreChips = count($near) > $closeLimit;
                    if ($moreChips) $near = array_slice($near, 0, $closeLimit);

                    // build the L* map (for fg contrast)
                    $nearIds = [];
                    foreach ($near as $n) {
                        $cid2 = (int)($n['color_id'] ?? 0);
                        if ($cid2 > 0) $nearIds[$cid2] = true;
                    }
                    $Lmap = !empty($nearIds)
                        ? $this->clusters->getLightnessMapForColorIds(array_keys($nearIds))
                        : [];

                    $neighborsUsed[$anchorId] = [];
                    foreach ($near as $n) {
                        $hex = (string)($n['rep_hex'] ?? ($n['hex'] ?? ''));
                        if ($hex !== '') $neighborHexAllUpper[strtoupper(ltrim($hex, '#'))] = true;

                        $nColorId = (int)($n['color_id'] ?? 0);
                        $L        = $Lmap[$nColorId] ?? null;
                        $fg       = ($L !== null && $L >= 70.0) ? '#000' : '#fff';

                        $neighborsUsed[$anchorId][] = [
                            'color_id'   => $nColorId,
                            'cluster_id' => (int)($n['cluster_id'] ?? 0),
                            'name'       => (string)($n['name']  ?? ''),
                            'brand'      => (string)($n['brand'] ?? ''),
                            'hex'        => $hex,
                            'hcl_l'      => $L,
                            'fg'         => $fg,
                            'de'         => isset($n['delta_e2000']) ? (float)$n['delta_e2000'] : null,
                        ];
                    }

                    // surface “See more…” signal for the UI
                    $neighborMeta[$anchorId] += [
                        'more_chips'  => $moreChips,
                        'chip_cap'    => $closeLimit,
                        'chip_max_de' => $chipMaxDe,
                    ];
                }
            }

                    // Friends for this GROUP (union inside the group)
        // --- FRIENDS UNION (with "close" effectiveness check) ---
        $fsetBase = $this->clusters->getFriendClustersUnion([$cid]); // anchor-only friends
        $fset     = $this->clusters->getFriendClustersUnion($group); // expanded (anchor+neighbors)

        // If including close matches but the expanded group adds nothing, widen once
        if ($includeCloseMatches) {
            $added = array_values(array_diff($fset, $fsetBase));
            if (count($added) === 0) {
                // widen gate slightly (2 steps) but never past hard max
                $wGate = min(
                    (float)($neighborMeta[$anchorId]['applied_gate'] ?? $nearMaxDe) + $nearStep * 2.0,
                    $nearHardMax
                );

                // re-expand neighbors with the wider gate
                $nRes2 = $this->matching->neighborsForCluster($cid, [
                    'include_self'  => true,
                    'near_cap'      => $nearCap,
                    'near_max_de'   => $wGate,
                    'near_step'     => $nearStep,
                    'near_hard_max' => $nearHardMax,
                    'target_count'  => $targetCount,
                    'metric'        => $nearMode,
                ]);

                $nc2 = array_values(array_filter((array)($nRes2['neighbors'] ?? []), fn($x)=> (int)$x>0));
                foreach ($nc2 as $ncid2) {
                    if ($ncid2 === $cid) continue;
                    if (!isset($neighborToAnchor[$ncid2])) {
                        $group[] = $ncid2;
                        $neighborToAnchor[$ncid2] = $cid;
                        $neighborClusterIdsAll[$ncid2] = true;
                        $debugGroups[$anchorId]['neighbors'][] = $ncid2;
                        $debugGroups[$anchorId]['seeds'][]     = $ncid2;
                    }
                }

                // recompute friends after widening once
                $fset = $this->clusters->getFriendClustersUnion($group);
                $neighborMeta[$anchorId]['widened_once'] = true;
                $neighborMeta[$anchorId]['applied_gate'] = $wGate;
            } else {
                $neighborMeta[$anchorId]['widened_once'] = false;
            }
        }

        $groupFriendsLists[] = $fset;


            // Provenance for Tier-B tagging
            if (!empty($fset)) {
                $edges = $this->clusters->getFriendEdgesForSeeds($group);
                $groupProv[$cid] = $groupProv[$cid] ?? [];
                foreach ($edges as $edge) {
                    $seed   = (int)$edge['cluster_id'];
                    $friend = (int)$edge['friend_cluster_id'];
                    if (!in_array($friend, $fset, true)) continue;

                    if (!isset($groupProv[$cid][$friend])) {
                        $groupProv[$cid][$friend] = [
                            'direct' => false,
                            'via_neighbors_from_anchor' => false,
                        ];
                    }
                    if ($seed === $cid) {
                        $groupProv[$cid][$friend]['direct'] = true;
                    } elseif (isset($neighborToAnchor[$seed]) && $neighborToAnchor[$seed] === $cid) {
                        $groupProv[$cid][$friend]['via_neighbors_from_anchor'] = true;
                    }
                }
            }
        }

        // 3) AND across groups (intersection across anchors)
        if (count($groupFriendsLists) === 1) {
            $friendClusters = $groupFriendsLists[0];
        } elseif (count($groupFriendsLists) > 1) {
            $friendClusters = array_values(array_intersect(...$groupFriendsLists));
        } else {
            $friendClusters = [];
        }

        // Exclude anchors + neighbor clusters from final items
        $excludeClusterIds = $anchorClusters;
        if (!empty($neighborClusterIdsAll)) {
            $excludeClusterIds = array_values(array_unique(array_merge(
                $excludeClusterIds,
                array_map('intval', array_keys($neighborClusterIdsAll))
            )));
        }
        $friendClusters = array_values(array_diff($friendClusters, $excludeClusterIds));

        if (!$friendClusters) {
            // Payload with meta (so FE can still show chips + see-more info)
            $payload = ['items'=>[]];
            if ($includeCloseMatches && !empty($neighborsUsed)) $payload['neighbors_used'] = $neighborsUsed;
            if ($includeCloseMatches && !empty($neighborMeta))  $payload['neighbors_meta'] = $neighborMeta;

            $wantDebug = (
                (isset($_GET['debug']) && (string)$_GET['debug'] === '1') ||
                (isset($opts['debug']) && (int)$opts['debug'] === 1)
            );
            if ($wantDebug && !empty($debugGroups)) $payload['_debug_groups'] = $debugGroups;

            return $payload;
        }

        // 4) Expand clusters → swatches (respect neutrals + brand filters)
        $excludeHexUpper = $anchorHex;
        if (!empty($neighborHexAllUpper)) {
            $excludeHexUpper = array_values(array_unique(array_merge(
                $excludeHexUpper,
                array_keys($neighborHexAllUpper)
            )));
        }

        $mode = $onlyNeutrals ? 'neutrals' : ($excludeNeutrals ? 'colors' : 'all');

        $rows = $this->clusters->expandClustersToSwatches(
            $friendClusters,
            $excludeHexUpper,
            $excludeClusterIds,
            $brands,
            $mode
        );

        if ($onlyNeutrals) {
            $rows = array_values(array_filter($rows, static function(array $r): bool {
                $isNeutralFlag   = !empty($r['is_neutral']);
                $hasNeutralCats  = isset($r['neutral_cats']) && trim((string)$r['neutral_cats']) !== '';
                $noHueButNeutral = (isset($r['hue_cats']) && trim((string)$r['hue_cats']) === '') && $hasNeutralCats;
                return ($isNeutralFlag || $hasNeutralCats || $noHueButNeutral);
            }));
        }

        // 5) Tier-B tagging
        $items = [];
        foreach ($rows as $r) {
            $friendCid   = (int)($r['cluster_id'] ?? 0);
            $tierb       = false;
            $tierbSource = null;

            foreach ($groupProv as $anchorCid => $provMap) {
                if (!isset($provMap[$friendCid])) continue;
                $p = $provMap[$friendCid];
                if ($p['via_neighbors_from_anchor'] && !$p['direct']) {
                    $tierb = true;
                    $tierbSource = (int)$anchorCid;
                    break;
                }
            }

            $r['tierb'] = $tierb;
            $r['tierb_source_cluster_id'] = $tierb ? $tierbSource : null;
            $items[] = $r;
        }

        // 6) Payload
        $payload = ['items' => array_values($items)];

        if ($includeCloseMatches && !empty($neighborsUsed)) {
            $nz = [];
            foreach ($neighborsUsed as $anchorIdX => $arr) {
                if (!empty($arr)) $nz[(string)$anchorIdX] = $arr;
            }
            if (!empty($nz)) $payload['neighbors_used'] = $nz;
        }
        if ($includeCloseMatches && !empty($neighborMeta)) {
            $payload['neighbors_meta'] = $neighborMeta;
        }

        $wantDebug = (
            (isset($_GET['debug']) && (string)$_GET['debug'] === '1') ||
            (isset($opts['debug']) && (int)$opts['debug'] === 1)
        );
        if ($wantDebug && !empty($debugGroups)) $payload['_debug_groups'] = $debugGroups;

        return $payload;
    }

    /**
     * (Legacy helper, kept for reference)
     * Use MatchingService to expand groups. Not used in getFriendSwatches().
     *
     * @return array{groups: int[][], neighbors_used: array<int,int[]>}
     */
    private function expandAnchorGroupsViaMatching(array $anchorClusterIds, bool $includeCloseMatches): array
    {
        $ids = array_values(array_filter(array_map('intval', $anchorClusterIds), fn($v)=>$v>0));
        if (!$ids) return ['groups'=>[], 'neighbors_used'=>[]];

        if (!$includeCloseMatches) {
            $groups = [];
            foreach ($ids as $cid) $groups[] = [$cid];
            return ['groups'=>$groups, 'neighbors_used'=>[]];
        }

        $match = new \App\services\MatchingService(
            new \App\repos\PdoColorRepository($this->pdo),
            new \App\repos\PdoSwatchRepository($this->pdo),
            new \App\repos\PdoColorDetailRepository($this->pdo),
            new \App\services\Rules(),
            new \App\services\ScoreCandidates(new \App\repos\PdoColorRepository($this->pdo)),
            new \App\services\FindBestPerBrand(new \App\repos\PdoColorRepository($this->pdo))
        );

        $opts = [
            'near_max_de'  => 12.0,
            'near_cap'     => 60,
            'include_self' => true,
            'excludeTwins' => true,
            'metric'       => 'white',
        ];

        $expanded = $match->expandClustersToClusterGroups($ids, $opts);
        $used = [];
        foreach (($expanded['neighbors_used'] ?? []) as $anchorCid => $clusters) {
            $anchorCid = (int)$anchorCid;
            $arr = [];
            foreach ((array)$clusters as $c) { $arr[] = (int)$c; }
            $used[$anchorCid] = array_values(array_unique(array_filter($arr, fn($v)=>$v>0)));
        }
        return [
            'groups'        => $expanded['groups'] ?? [],
            'neighbors_used'=> $used,
        ];
    }
}
