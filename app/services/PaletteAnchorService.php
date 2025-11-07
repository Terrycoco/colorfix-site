<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPaletteRepository;
use App\Repos\PdoClusterRepository;

/**
 * PaletteAnchorService
 *
 * Thin wrapper around PdoPaletteRepository with helpers used by controllers/tests.
 * - Legacy: includesAllClusters(...)  → status-aware strict include (size window)
 * - Visible-only strict: includesAllClustersVisibleAnySize(...)
 * - Group selection: includesOneFromEachGroupIds(...)
 * - Hydration: hydrateVisibleAnySizeByIds(...)
 * - Include-close helper: includesCloseUnionStrictHydrate(...) → unions strict Tier-A
 */
final class PaletteAnchorService
{
    public function __construct(
        private PdoPaletteRepository $repo
    ) {}

    /**
     * LEGACY strict include (status-aware): palettes that include ALL anchors,
     * filtered by p.status and size range.
     *
     * Returns: ['items'=>[{palette_id,size,member_cluster_ids}], 'total_count'=>int]
     */
    public function includesAllClusters(
        array $anchorClusterIds,
        string $tier   = 'A',
        string $status = 'active',
        int $sizeMin   = 3,
        int $sizeMax   = 7,
        int $limit     = 200,
        int $offset    = 0
    ): array {
        return $this->repo->findIncludingAllClusters(
            $anchorClusterIds, $tier, $status, $sizeMin, $sizeMax, $limit, $offset
        );
    }

    /**
     * VISIBLE-ONLY strict include, any size (excludes hidden).
     * Returns: ['items'=>[{palette_id,size,member_cluster_ids}], 'total_count'=>int]
     */
    public function includesAllClustersVisibleAnySize(
        array $anchorClusterIds,
        string $tier = 'A',
        int $limit = 200,
        int $offset = 0
    ): array {
        return $this->repo->findIncludingAllClustersVisibleAnySize(
            $anchorClusterIds, $tier, 1, 99, $limit, $offset
        );
    }

    /**
     * IDs-only: require >=1 member from each seed group (K-of-N via $minGroupsHit).
     * Returns: ['palette_ids'=>int[], 'total_count'=>int]
     */
    public function includesOneFromEachGroupIds(
        array $clusterGroups,
        int $limit = 200,
        int $offset = 0,
        ?int $minGroupsHit = null
    ): array {
        return $this->repo->findPaletteIdsByClusterGroups(
            $clusterGroups, $limit, $offset, $minGroupsHit
        );
    }

    /**
     * Hydrate full members for a set of palette IDs (visible-only, any size).
     * Returns: ['items'=>[{palette_id,size,member_cluster_ids}], 'total_count'=>int]
     */
    public function hydrateVisibleAnySizeByIds(
        array $paletteIds,
        int $limit = 200,
        int $offset = 0
    ): array {
        return $this->repo->hydrateVisibleAnySizeByIds($paletteIds, $limit, $offset);
    }

    /**
     * SERVICE-LEVEL include_close helper:
     * 1) Select by seed groups (neighbors) → IDs.
     * 2) UNION strict Tier-A exact-anchor matches (visible-only).
     * 3) Hydrate (visible-only; any size), optional size window + presence enforcement.
     *
     * Returns: ['items'=>[{palette_id,size,member_cluster_ids,member_pairs?}], 'total_count'=>int]
     */
    public function includesCloseUnionStrictHydrate(
        array $seedClusterGroups,          // int[][]
        array $exactAnchorClusterIds,      // int[]
        string $tier = 'A',
        int $limit = 200,
        int $offset = 0,
        ?int $minGroupsHit = null,
        ?int $sizeMin = null,
        ?int $sizeMax = null,
        bool $enforceSeedPresence = false
    ): array {
        // 1) IDs by seed groups (neighbors)
        $idsRes = $this->repo->findPaletteIdsByClusterGroups(
            $seedClusterGroups, 800, 0, $minGroupsHit
        );
        $union = [];
        foreach (($idsRes['palette_ids'] ?? []) as $pid) $union[(int)$pid] = true;

        // 2) UNION strict Tier-A (visible-only, any size)
        $strict = $this->repo->findIncludingAllClustersVisibleAnySize(
            $exactAnchorClusterIds, $tier, 1, 99, 800, 0
        );
        if (!empty($strict['items'])) {
            foreach ($strict['items'] as $row) {
                $pid = (int)($row['palette_id'] ?? 0);
                if ($pid > 0) $union[$pid] = true;
            }
        }

        if (empty($union)) return ['items'=>[], 'total_count'=>0];

        // 3) Hydrate
        $finalIds = array_keys($union);
        $hydrated = $this->repo->hydrateVisibleAnySizeByIds($finalIds, $limit, $offset);
        $items    = is_array($hydrated['items'] ?? null) ? $hydrated['items'] : [];
        $total    = (int)($hydrated['total_count'] ?? 0);

        // Optional size window
        if ($sizeMin !== null || $sizeMax !== null) {
            $min = ($sizeMin !== null) ? (int)$sizeMin : 1;
            $max = ($sizeMax !== null) ? (int)$sizeMax : 99;
            $items = array_values(array_filter($items, static function(array $p) use ($min,$max): bool {
                $sz = (int)($p['size'] ?? 0);
                return ($sz >= $min && $sz <= $max);
            }));
        }

        // Optional ≥1-from-each-group enforcement
        if ($enforceSeedPresence && !empty($seedClusterGroups)) {
            $items = array_values(array_filter($items, function(array $p) use ($seedClusterGroups): bool {
                $members   = array_map('intval', $p['member_cluster_ids'] ?? []);
                $memberSet = array_fill_keys($members, true);
                foreach ($seedClusterGroups as $group) {
                    $ok = false;
                    foreach ((array)$group as $cid) {
                        if (isset($memberSet[(int)$cid])) { $ok = true; break; }
                    }
                    if (!$ok) return false;
                }
                return true;
            }));
        }

        // Convenience: member_pairs (cid:hex) for chips in UI/tests
        if (!empty($items)) {
            $allCids = [];
            foreach ($items as $it) foreach (($it['member_cluster_ids'] ?? []) as $cid) $allCids[] = (int)$cid;
            $allCids = array_values(array_unique(array_filter($allCids, fn($v)=>$v>0)));
            if ($allCids) {
                // Access PDO behind the repo via reflection (keeps ctor signature stable)
                $refl = new \ReflectionClass($this->repo);
                $pdoProp = $refl->getProperty('pdo');
                $pdoProp->setAccessible(true);
                $pdo = $pdoProp->getValue($this->repo);

                $hexMap = (new PdoClusterRepository($pdo))->getRepHexForClusterIds($allCids);
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

        return ['items'=>$items, 'total_count'=>$total];
    }
}
