<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Services\PaletteTierAService;
use App\Services\PaletteAnchorService;
use App\Repos\PdoPaletteRepository;
use App\Repos\PdoClusterRepository;
use App\Repos\PdoColorRepository;
use App\Repos\PdoSwatchRepository;
use App\Repos\PdoColorDetailRepository;
use App\Services\Rules;
use App\Services\ScoreCandidates;
use App\Services\FindBestPerBrand;
use App\Services\MatchingService;

final class PaletteController
{
    public function __construct(
        private PaletteTierAService $svc,
        private PDO $pdo
    ) {}

    // POST: { pivot_cluster_id, max_k?, budget_ms? }
    public function generateTierA(array $body): array {
        $pivot  = (int)($body['pivot_cluster_id'] ?? 0);
        if ($pivot <= 0) return ['ok'=>false,'error'=>'pivot_cluster_id required'];
        $maxK   = (int)($body['max_k'] ?? 6);
        $budget = (int)($body['budget_ms'] ?? 4000);
        return $this->svc->generateForPivot($pivot, $maxK, $budget);
    }

    // GET/POST: pivot_cluster_id, tier='A'
    public function byPivot(array $query, array $body): array {
        $pivot = isset($query['pivot_cluster_id']) ? (int)$query['pivot_cluster_id']
               : (int)($body['pivot_cluster_id'] ?? 0);
        if ($pivot <= 0) return ['ok'=>false,'error'=>'pivot_cluster_id required'];
        $tier  = (string)($query['tier'] ?? ($body['tier'] ?? 'A'));
        return ['ok'=>true,'pivot_cluster_id'=>$pivot,'tier'=>$tier,'palettes'=>$this->svc->fetchByPivot($pivot, $tier)];
    }

    /**
     * Browse by anchor clusters.
     *
     * Inputs (array $in):
     * - exact_anchor_cluster_ids: int[] | CSV
     * - include_close: 0/1  OR  match_mode='includes_close'
     * - limit/offset
     * - tier (default 'A')
     * - enforce_seed_presence: 0/1 (default ON when include_close=1)
     * - size_min/size_max (optional; only applied when provided)
     */
    public function browseByAnchors(array $in): array
    {
        // ---- Canonical inputs ----
        $tier   = is_string($in['tier'] ?? null) ? $in['tier'] : 'A';
        $limit  = max(1, (int)($in['limit']  ?? 60));
        $offset = max(0, (int)($in['offset'] ?? 0));
        $tagsAny = isset($in['include_tags_any']) && is_array($in['include_tags_any'])
            ? $in['include_tags_any']
            : [];
        $tagsAll = isset($in['include_tags_all']) && is_array($in['include_tags_all'])
            ? $in['include_tags_all']
            : [];
        $tagsAny = array_values(array_unique(array_filter(array_map(static function($t){
            $t = trim((string)$t);
            return $t === '' ? null : $t;
        }, $tagsAny))));
        $tagsAll = array_values(array_unique(array_filter(array_map(static function($t){
            $t = trim((string)$t);
            return $t === '' ? null : $t;
        }, $tagsAll))));
        $tagModeAll = !empty($tagsAll);
        $tagsFilter = $tagModeAll ? $tagsAll : $tagsAny;
        $hasTags = !empty($tagsFilter);

        // Anchors: cluster_ids only
        $anchors = [];
        if (!empty($in['exact_anchor_cluster_ids'])) {
            $src = $in['exact_anchor_cluster_ids'];
            if (is_array($src))       $anchors = $src;
            elseif (is_string($src))  $anchors = preg_split('/[,\s]+/', trim($src), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $anchors = array_values(array_unique(array_filter(array_map('intval', (array)$anchors), fn($v)=>$v>0)));

        // Optional size window (applied only if provided)
        $sizeMin = isset($in['size_min']) ? (int)$in['size_min'] : null;
        $sizeMax = isset($in['size_max']) ? (int)$in['size_max'] : null;

        if (!$anchors) {
            if ($hasTags) {
                $paletteRepo = new PdoPaletteRepository($this->pdo);
                $idsRes = $paletteRepo->findPaletteIdsByTags($tagsFilter, $tagModeAll, $limit, $offset);
                $ids = $idsRes['palette_ids'] ?? [];
                if (!$ids) {
                    return [
                        'items'          => [],
                        'total_count'    => 0,
                        'counts_by_size' => (object)[],
                        'limit'          => $limit,
                        'next_offset'    => null,
                        'branch'         => 'TagsOnly',
                    ];
                }
                $svc = new PaletteAnchorService($paletteRepo);
                $hydrated = $svc->hydrateVisibleAnySizeByIds($ids, $limit, 0);
                $items = is_array($hydrated['items'] ?? null) ? $hydrated['items'] : [];
                $total = (int)($idsRes['total_count'] ?? 0);

                // Optional size window
                if ($sizeMin !== null || $sizeMax !== null) {
                    $min = ($sizeMin !== null) ? (int)$sizeMin : 1;
                    $max = ($sizeMax !== null) ? (int)$sizeMax : 99;
                    $items = array_values(array_filter($items, static function(array $p) use ($min,$max): bool {
                        $sz = (int)($p['size'] ?? 0);
                        return ($sz >= $min && $sz <= $max);
                    }));
                    $total = count($items);
                }

                $counts = [];
                foreach ($items as $p) {
                    $s = (string)($p['size'] ?? 0);
                    if ($s !== '0') $counts[$s] = ($counts[$s] ?? 0) + 1;
                }
                $next = ($offset + $limit < $total) ? ($offset + $limit) : null;

                // Ensure member_pairs for swatch colors
                if (!empty($items)) {
                    $allCids = [];
                    foreach ($items as $it) {
                        foreach (($it['member_cluster_ids'] ?? []) as $cid) $allCids[] = (int)$cid;
                    }
                    $allCids = array_values(array_unique(array_filter($allCids, fn($v)=>$v>0)));
                    if ($allCids) {
                        $hexMap = (new PdoClusterRepository($this->pdo))->getRepHexForClusterIds($allCids);
                        foreach ($items as &$it) {
                            $pairs = [];
                            foreach (($it['member_cluster_ids'] ?? []) as $cid) {
                                $cid = (int)$cid; $hex = $hexMap[$cid] ?? '';
                                $pairs[] = $cid . ':' . ltrim($hex, '#');
                            }
                            $it['member_pairs'] = implode(',', $pairs);
                        }
                        unset($it);
                    }
                }

                // Attach meta/tags
                $pids = array_values(array_unique(array_filter(array_map(
                    fn($it) => (int)($it['palette_id'] ?? 0), $items
                ), fn($v)=>$v>0)));
                if ($pids) {
                    $metaMap = $paletteRepo->getMetaForPaletteIds($pids);
                    $tagsMap = $paletteRepo->getTagsForPaletteIds($pids);
                    foreach ($items as &$it) {
                        $pid = (int)($it['palette_id'] ?? 0);
                        $it['meta'] = array_merge(
                            ['nickname'=>null,'terry_says'=>null,'terry_fav'=>0,'tags'=>[]],
                            $metaMap[$pid] ?? []
                        );
                        $it['meta']['tags'] = $tagsMap[$pid] ?? [];
                    }
                    unset($it);
                }

                return [
                    'items'          => $items,
                    'total_count'    => $total,
                    'counts_by_size' => (object)$counts,
                    'limit'          => $limit,
                    'next_offset'    => $next,
                    'branch'         => 'TagsOnly',
                ];
            }

            return [
                'items'          => [],
                'total_count'    => 0,
                'counts_by_size' => (object)[],
                'limit'          => $limit,
                'next_offset'    => null,
            ];
        }

        // include_close ← match_mode OR explicit flag
        $includeClose = false;
        if (isset($in['match_mode']) && (string)$in['match_mode'] === 'includes_close') {
            $includeClose = true;
        } elseif (array_key_exists('include_close', $in)) {
            $v = $in['include_close'];
            $includeClose = ($v === 1 || $v === '1' || $v === true || $v === 'true' || $v === 'TRUE');
        }

        // Enforce presence (default ON whenever include_close=1; overrideable)
        $enforceSeedPresence = $includeClose;
        if (array_key_exists('enforce_seed_presence', $in)) {
            $v = $in['enforce_seed_presence'];
            $enforceSeedPresence = ($v === 1 || $v === '1' || $v === true || $v === 'true' || $v === 'TRUE');
        }

        // Debug snapshot + headers
        $__debug_snapshot = [
            'parsed_anchors'        => $anchors,
            'include_close'         => $includeClose,
            'tier'                  => $tier,
            'enforce_seed_presence' => $enforceSeedPresence ? 1 : 0,
        ];
        @header('X-CF-Anchors: ' . implode(',', $anchors));
        @header('X-CF-Include-Close: ' . ($includeClose ? '1' : '0'));

        // ---------- Tier A (strict, exact clusters) ----------
        if (!$includeClose) {
            $svc = new PaletteAnchorService(new PdoPaletteRepository($this->pdo));
            $res = $svc->includesAllClustersVisibleAnySize($anchors, $tier, $limit, $offset);

            $items = is_array($res['items'] ?? null) ? $res['items'] : [];
            $allCids = [];
            foreach ($items as $it) foreach (($it['member_cluster_ids'] ?? []) as $cid) $allCids[] = (int)$cid;
            $allCids = array_values(array_unique(array_filter($allCids, fn($v)=>$v>0)));
            $hexMap = $allCids ? (new PdoClusterRepository($this->pdo))->getRepHexForClusterIds($allCids) : [];
            foreach ($items as &$it) {
                $pairs = [];
                foreach (($it['member_cluster_ids'] ?? []) as $cid) {
                    $cid = (int)$cid; $hex = $hexMap[$cid] ?? '';
                    $pairs[] = $cid . ':' . ltrim($hex, '#');
                }
                $it['member_pairs'] = implode(',', $pairs);
            }
            unset($it);

            $paletteRepo = new \App\Repos\PdoPaletteRepository($this->pdo);
            $pids = array_values(array_unique(array_filter(array_map(
                fn($it) => (int)($it['palette_id'] ?? 0), $items
            ), fn($v)=>$v>0)));

            // Filter by tags if requested
            if ($hasTags && $pids) {
                $tagsMap = $paletteRepo->getTagsForPaletteIds($pids);
                $need = array_map('strtolower', $tagsFilter);
                $items = array_values(array_filter($items, function(array $it) use ($tagsMap, $need, $tagModeAll): bool {
                    $pid = (int)($it['palette_id'] ?? 0);
                    $tags = array_map('strtolower', $tagsMap[$pid] ?? []);
                    if (!$need) return true;
                    if ($tagModeAll) {
                        return empty(array_diff($need, $tags));
                    }
                    return !empty(array_intersect($need, $tags));
                }));
                $pids = array_values(array_unique(array_filter(array_map(
                    fn($it) => (int)($it['palette_id'] ?? 0), $items
                ), fn($v)=>$v>0)));
            }

            $counts = [];
            foreach ($items as $p) {
                $s = (string)($p['size'] ?? 0);
                if ($s !== '0') $counts[$s] = ($counts[$s] ?? 0) + 1;
            }
            $total = $hasTags ? count($items) : (int)($res['total_count'] ?? 0);
            $next  = ($offset + $limit < $total) ? ($offset + $limit) : null;

            // ---- Attach meta (nickname/terry_says/terry_fav/tags) ----
            if ($pids) {
                $metaMap = $paletteRepo->getMetaForPaletteIds($pids);
                $tagsMap = $paletteRepo->getTagsForPaletteIds($pids);
                foreach ($items as &$it) {
                    $pid = (int)($it['palette_id'] ?? 0);
                    $it['meta'] = array_merge(
                        ['nickname'=>null,'terry_says'=>null,'terry_fav'=>0,'tags'=>[]],
                        $metaMap[$pid] ?? []
                    );
                    $it['meta']['tags'] = $tagsMap[$pid] ?? [];
                }
                unset($it);
            }


            return [
                'items'          => $items,
                'total_count'    => $total,
                'counts_by_size' => (object)$counts,
                'limit'          => $limit,
                'next_offset'    => $next,
                'branch'         => 'TierA',
                'debug_in'       => $__debug_snapshot,
            ];
        }

        // ---------- Tier B (include_close): build SEED GROUPS and select by them ----------
        $START            = microtime(true);
        $TIME_BUDGET_MS   = 1500;
        $debugMode        = !empty($in['debug']);

        $colorRepo    = new PdoColorRepository($this->pdo);
        $swatchRepo   = new PdoSwatchRepository($this->pdo);
        $detailRepo   = new PdoColorDetailRepository($this->pdo);
        $rules        = new Rules();
        $scorer       = new ScoreCandidates($colorRepo);
        $perBrand     = new FindBestPerBrand($colorRepo);
        $matching     = new MatchingService($colorRepo, $swatchRepo, $detailRepo, $rules, $scorer, $perBrand);

        $paletteRepo  = new PdoPaletteRepository($this->pdo);
        $svcAnchors   = new PaletteAnchorService($paletteRepo);

        // Attempts ladder → only cap (how many neighbors we allow)
        $attempts = [
            ['cap' => 30],
            ['cap' => 60],
            ['cap' => 120],
        ];

        $unionIds                = []; // palette_id => true
        $attemptMeta             = ['used' => -1, 'cap'=>null, 'groups'=>0, 'elapsed_ms'=>0, 'found_ids'=>0, 'union_size'=>0, 'note'=>null];
        $debugOut                = ['groups_raw'=>[], 'groups_allowed'=>[], 'ids_by_attempt'=>[]];
        $neighborsUsedPills      = [];                  // UI pills (tightest attempt)
        $seedGroupsByAnchorChosen = [];                 // anchorCid => int[] (chosen attempt)

        foreach ($attempts as $idx => $a) {
            $elapsed = (int)round((microtime(true) - $START) * 1000);
            if ($elapsed >= $TIME_BUDGET_MS) break;

            // 1) Expand neighbors per anchor (same engine as My Palette)
            $nearCap = (int)$a['cap'];
            $expanded = $matching->expandClustersToClusterGroups($anchors, [
                'near_cap'     => $nearCap,
                'include_self' => true,
                'metric'       => 'white',
            ]);

            // Normalize groups
            $clusterGroups = [];
            $rawGroups = $expanded['groups'] ?? null;
            if (is_array($rawGroups)) {
                foreach ($rawGroups as $g) {
                    if (!is_array($g)) continue;
                    $g = array_values(array_unique(array_filter(array_map('intval', $g), fn($v)=>$v>0)));
                    if ($g) $clusterGroups[] = $g;
                }
            }
            if ($debugMode) $debugOut['groups_raw'][$idx] = $clusterGroups;

            // Save first (tightest) neighbor lists as pills (neighbors only, no self)
            if (empty($neighborsUsedPills) && is_array($expanded['neighbors_used'] ?? null)) {
                foreach ($expanded['neighbors_used'] as $ac => $list) {
                    $ac = (int)$ac;
                    $arr = array_values(array_filter(array_map('intval', (array)$list), fn($v)=>$v>0));
                    $neighborsUsedPills[(string)$ac] = array_values(array_diff($arr, [$ac]));
                }
            }

            if (!$clusterGroups) {
                $attemptMeta = ['used'=>$idx,'cap'=>$nearCap,'groups'=>0,'elapsed_ms'=>$elapsed,'found_ids'=>0,'union_size'=>count($unionIds),'note'=>'no groups'];
                continue;
            }

            // 2) Keep only clusters that actually appear in visible palettes
            $all = [];
            foreach ($clusterGroups as $g) foreach ($g as $cid) $all[] = $cid;
            $all        = array_values(array_unique($all));
            $allowed    = $paletteRepo->clustersHavingPalettes($all);
            $allowedSet = array_fill_keys($allowed, true);

            $filteredGroups  = [];
            foreach ($clusterGroups as $g) {
                $fg = [];
                foreach ($g as $cid) if (isset($allowedSet[$cid])) $fg[] = $cid;
                if ($fg) $filteredGroups[] = $fg;
            }
            if ($debugMode) $debugOut['groups_allowed'][$idx] = $filteredGroups;

            if (!$filteredGroups) {
                $attemptMeta = ['used'=>$idx,'cap'=>$nearCap,'groups'=>0,'elapsed_ms'=>$elapsed,'found_ids'=>0,'union_size'=>count($unionIds),'note'=>'groups filtered empty'];
                continue;
            }

            // 3) Select by SEED GROUPS directly: one-from-each-seed-group
            $minGroupsHit = count($filteredGroups); // require >=1 from each group
            $idsRes   = $svcAnchors->includesOneFromEachGroupIds($filteredGroups, 800, 0, $minGroupsHit);
            $gotIds   = is_array($idsRes['palette_ids'] ?? null) ? $idsRes['palette_ids'] : [];
            $totalIds = (int)($idsRes['total_count'] ?? 0);

            foreach ($gotIds as $pid) $unionIds[(int)$pid] = true;

            // Remember seed groups from this attempt (for presence filter + debug)
            $seedGroupsByAnchorChosen = [];
            foreach ($filteredGroups as $i => $fg) {
                $ac = (int)($anchors[$i] ?? 0);
                if ($ac > 0) $seedGroupsByAnchorChosen[$ac] = $fg;
            }

            $attemptMeta = [
                'used'       => $idx,
                'cap'        => $nearCap,
                'groups'     => count($filteredGroups),
                'elapsed_ms' => (int)round((microtime(true) - $START) * 1000),
                'found_ids'  => $totalIds,
                'union_size' => count($unionIds),
                'note'       => 'selected by seed groups',
            ];
        }

        // Fallback: ensure every anchor has a seed entry
        foreach ($anchors as $ac) {
            if (!isset($seedGroupsByAnchorChosen[$ac])) $seedGroupsByAnchorChosen[$ac] = [$ac];
        }

        // ---- UNION strict Tier-A once so originals are eligible (fixes “original not returned”) ----
        $strict = $svcAnchors->includesAllClustersVisibleAnySize($anchors, $tier, 800, 0);
        if (!empty($strict['items'])) {
            foreach ($strict['items'] as $it) {
                $pid = (int)($it['palette_id'] ?? 0);
                if ($pid > 0) $unionIds[$pid] = true;
            }
        }

        // 4) Hydrate full palettes (visible-only), no default size filter in Tier-B
        $finalIds = array_keys($unionIds);
        $hydrated = $svcAnchors->hydrateVisibleAnySizeByIds($finalIds, $limit, $offset);
        $items    = is_array($hydrated['items'] ?? null) ? $hydrated['items'] : [];
        $total    = (int)($hydrated['total_count'] ?? 0);

        // Optional size window ONLY if caller provided it
        if ($sizeMin !== null || $sizeMax !== null) {
            $min = ($sizeMin !== null) ? $sizeMin : 1;
            $max = ($sizeMax !== null) ? $sizeMax : 99;
            $items = array_values(array_filter($items, static function(array $p) use ($min,$max): bool {
                $sz = (int)($p['size'] ?? 0);
                return ($sz >= $min && $sz <= $max);
            }));
        }

        // Optional strictness: require ≥1 member from each seed group
         // Optional ≥1-from-each-group enforcement (treat exact anchors as valid members of their groups)
        if ($enforceSeedPresence && !empty($seedClusterGroups)) {
            // Start with the neighbor groups…
            $presenceGroups = $seedClusterGroups;

            // …then merge each exact anchor into its corresponding group by index (if provided).
            if (!empty($exactAnchorClusterIds)) {
                $presenceGroups = [];
                $max = max(count($seedClusterGroups), count($exactAnchorClusterIds));
                for ($i = 0; $i < $max; $i++) {
                    $g = isset($seedClusterGroups[$i]) ? (array)$seedClusterGroups[$i] : [];
                    $seedCid = isset($exactAnchorClusterIds[$i]) ? (int)$exactAnchorClusterIds[$i] : 0;
                    if ($seedCid > 0) $g[] = $seedCid;

                    // Normalize
                    $g = array_values(array_unique(array_filter(array_map('intval', $g), fn($v)=>$v>0)));
                    if ($g) $presenceGroups[] = $g;
                }
            }

            $items = array_values(array_filter($items, function(array $p) use ($presenceGroups): bool {
                $members   = array_map('intval', $p['member_cluster_ids'] ?? []);
                $memberSet = array_fill_keys($members, true);
                foreach ($presenceGroups as $group) {
                    $ok = false;
                    foreach ((array)$group as $cid) {
                        if (isset($memberSet[(int)$cid])) { $ok = true; break; }
                    }
                    if (!$ok) return false;
                }
                return true;
            }));
        }


        // Decorate member_pairs
        $allCids = [];
        foreach ($items as $it) foreach (($it['member_cluster_ids'] ?? []) as $cid) $allCids[] = (int)$cid;
        $allCids = array_values(array_unique(array_filter($allCids, fn($v)=>$v>0)));
        $hexMap = $allCids ? (new PdoClusterRepository($this->pdo))->getRepHexForClusterIds($allCids) : [];
        foreach ($items as &$it) {
            $pairs = [];
            foreach (($it['member_cluster_ids'] ?? []) as $cid) {
                $cid = (int)$cid; $hex = $hexMap[$cid] ?? '';
                $pairs[] = $cid . ':' . ltrim($hex, '#');
            }
            $it['member_pairs'] = implode(',', $pairs);
        }
        unset($it);

        // ---------- Tag group (exact|near), compute display anchors, and de-duplicate ----------
        // Build ordered anchor->group map
        $orderedAnchorGroups = [];
        foreach ($anchors as $i => $seedCid) {
            $seedCid = (int)$seedCid;
            $orderedAnchorGroups[] = [
                'seed'  => $seedCid,
                'group' => array_values(array_unique(array_map('intval', $seedGroupsByAnchorChosen[$seedCid] ?? [$seedCid]))),
            ];
        }

        // Equivalence map: any member in an anchor's group canonicalized to "A{i}" for dedupe_key
        $equiv = []; // cluster_id => "A0"/"A1"/...
        foreach ($orderedAnchorGroups as $i => $ag) {
            foreach ($ag['group'] as $cid) $equiv[(int)$cid] = 'A'.$i;
        }

        // Tag + dedupe key
        foreach ($items as &$p) {
            $members   = array_values(array_unique(array_map('intval', $p['member_cluster_ids'] ?? [])));
            $memberSet = array_fill_keys($members, true);

            $displayAnchors  = [];
            $neighborsDetail = [];
            $allExact        = true;

            foreach ($orderedAnchorGroups as $ag) {
                $seedCid   = (int)$ag['seed'];
                $groupCids = $ag['group'];

                $presentCid = null;
                foreach ($groupCids as $gcid) {
                    if (isset($memberSet[$gcid])) { $presentCid = $gcid; break; }
                }

                $displayAnchors[] = $presentCid;

                if ($presentCid !== $seedCid) {
                    $allExact = false;
                    if ($presentCid !== null && $seedCid > 0) {
                        $neighborsDetail[] = [
                            'seed_cluster_id'    => $seedCid,
                            'neighbor_cluster_id'=> $presentCid,
                        ];
                    }
                }
            }

            $p['group'] = $allExact ? 'exact' : 'near';
            $p['display_anchor_cluster_ids'] = $displayAnchors;
            if (!empty($neighborsDetail)) $p['neighbors_used_detail'] = $neighborsDetail;

            $canonParts = [];
            foreach ($members as $cid) $canonParts[] = $equiv[$cid] ?? (string)$cid;
            sort($canonParts, SORT_STRING);
            $p['dedupe_key'] = (string)($p['size'] ?? 0) . '|' . implode(',', $canonParts);
        }
        unset($p);

        // DEDUPE: prefer EXACT over NEAR when the same dedupe_key collides
        $byKey = [];
        foreach ($items as $row) {
            $key = (string)($row['dedupe_key'] ?? '');
            if ($key === '') {
                $byKey[spl_object_id((object)$row)] = $row;
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = $row;
                continue;
            }
            $keepG = (string)($byKey[$key]['group'] ?? '');
            $curG  = (string)($row['group'] ?? '');
            if ($curG === 'exact' && $keepG !== 'exact') {
                $byKey[$key] = $row;
            }
        }
        $items = array_values($byKey);

        // neighbors_used → hex for chips
        if (!empty($neighborsUsedPills)) {
            $pillIds = [];
            foreach ($neighborsUsedPills as $arr) foreach ($arr as $cid) $pillIds[] = $cid;
            $pillIds = array_values(array_unique($pillIds));
            $pillHex = $pillIds ? (new PdoClusterRepository($this->pdo))->getRepHexForClusterIds($pillIds) : [];
            $mapped  = [];
            foreach ($neighborsUsedPills as $anchorCidStr => $arr) {
                $rows = [];
                foreach ($arr as $cid) {
                    $rows[] = ['cluster_id' => (int)$cid, 'hex' => (string)($pillHex[$cid] ?? '')];
                }
                if ($rows) $mapped[(string)$anchorCidStr] = $rows;
            }
            $neighborsUsedPills = $mapped;
        }

        $paletteRepo = new \App\Repos\PdoPaletteRepository($this->pdo);
        $pids = array_values(array_unique(array_filter(array_map(
            fn($it) => (int)($it['palette_id'] ?? 0), $items
        ), fn($v)=>$v>0)));

        // Filter by tags if requested
        if ($hasTags && $pids) {
            $tagsMap = $paletteRepo->getTagsForPaletteIds($pids);
            $need = array_map('strtolower', $tagsFilter);
            $items = array_values(array_filter($items, function(array $it) use ($tagsMap, $need, $tagModeAll): bool {
                $pid = (int)($it['palette_id'] ?? 0);
                $tags = array_map('strtolower', $tagsMap[$pid] ?? []);
                if (!$need) return true;
                if ($tagModeAll) {
                    return empty(array_diff($need, $tags));
                }
                return !empty(array_intersect($need, $tags));
            }));
            $pids = array_values(array_unique(array_filter(array_map(
                fn($it) => (int)($it['palette_id'] ?? 0), $items
            ), fn($v)=>$v>0)));
        }

        // counts_by_size + next_offset (based on deduped $items)
        $counts = [];
        foreach ($items as $p) {
            $s = (string)($p['size'] ?? 0);
            if ($s !== '0') $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        $total = $hasTags ? count($items) : $total;
        $next  = ($offset + $limit < $total) ? ($offset + $limit) : null;

        // ---- Attach meta (nickname/terry_says/terry_fav/tags) ----
        if ($pids) {
            $metaMap = $paletteRepo->getMetaForPaletteIds($pids);
            $tagsMap = $paletteRepo->getTagsForPaletteIds($pids);
            foreach ($items as &$it) {
                $pid = (int)($it['palette_id'] ?? 0);
                $it['meta'] = array_merge(
                    ['nickname'=>null,'terry_says'=>null,'terry_fav'=>0,'tags'=>[]],
                    $metaMap[$pid] ?? []
                );
                $it['meta']['tags'] = $tagsMap[$pid] ?? [];
            }
            unset($it);
        }


        return [
            'items'                  => $items,
            'total_count'            => $total,
            'counts_by_size'         => (object)$counts,
            'limit'                  => $limit,
            'next_offset'            => $next,
            'also_checked_by_anchor' => (object)[],
            'neighbors_used'         => $neighborsUsedPills,
            'tuning'                 => $attemptMeta,
            'branch'                 => 'TierB',
            'debug_in'               => $__debug_snapshot + [
                'seed_groups_by_anchor' => $seedGroupsByAnchorChosen,
            ],
            'debug'                  => $debugMode ? $debugOut : null,
        ];
    }
}
