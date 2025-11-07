<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPaletteRepository
{
    public function __construct(private PDO $pdo) {}

    /** Palettes that include this pivot cluster (default Tier A, active) */
    public function getPalettesByPivot(int $pivotClusterId, string $tier = 'A', string $status = 'active'): array
    {
        $sql = "
            SELECT p.*
            FROM palettes p
            JOIN palette_members pm ON pm.palette_id = p.id
            WHERE pm.member_cluster_id = :pivot
              AND p.tier   = :tier
              AND p.status = :status
            GROUP BY p.id
            ORDER BY p.size DESC, p.id ASC
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':pivot'=>$pivotClusterId, ':tier'=>$tier, ':status'=>$status]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Ordered member cluster ids for a palette */
    public function getPaletteMembers(int $paletteId): array
    {
        $st = $this->pdo->prepare("
            SELECT member_cluster_id
            FROM palette_members
            WHERE palette_id = :pid
            ORDER BY order_hint ASC, member_cluster_id ASC
        ");
        $st->execute([':pid'=>$paletteId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0)) ?: [];
    }

    /**
     * STRICT (status-aware): palettes that include ALL anchor clusters (superset),
     * filtered by status and size range. Kept for backward compatibility.
     *
     * Returns: ['items'=>[{'palette_id','size','member_cluster_ids'=>[...]}], 'total_count'=>int]
     */
    public function findIncludingAllClusters(
        array $anchorClusterIds,
        string $tier = 'A',
        string $status = 'active',
        int $sizeMin = 3,
        int $sizeMax = 7,
        int $limit   = 200,
        int $offset  = 0
    ): array {
        $anchors = array_values(array_unique(array_map('intval', array_filter($anchorClusterIds, fn($v)=>$v>0))));
        if (!$anchors) return ['items'=>[], 'total_count'=>0];

        $anchorsCount = count($anchors);

        // Build :a1,:a2,...
        $aParams = [];
        foreach ($anchors as $i => $cid) { $aParams[":a".($i+1)] = $cid; }
        $in = implode(',', array_keys($aParams));

        $szMin = (int)$sizeMin; $szMax = (int)$sizeMax;
        $lim   = (int)$limit;   $off   = (int)$offset;

        // ITEMS (distinct names for inner/outer placeholders to avoid HY093)
        $sql = "
          SELECT p.id AS palette_id,
                 p.size,
                 GROUP_CONCAT(DISTINCT pm.member_cluster_id ORDER BY pm.member_cluster_id SEPARATOR ',') AS member_cluster_ids_csv
          FROM palettes p
          JOIN palette_members pm ON pm.palette_id = p.id
          WHERE p.tier   = :tier_out
            AND p.status = :status_out
            AND p.id IN (
              SELECT p2.id
              FROM palettes p2
              JOIN palette_members pm2 ON pm2.palette_id = p2.id
              WHERE p2.tier   = :tier_in
                AND p2.status = :status_in
                AND pm2.member_cluster_id IN ($in)
              GROUP BY p2.id
              HAVING COUNT(DISTINCT pm2.member_cluster_id) = :anchorsCount
            )
            AND p.size BETWEEN :szmin AND :szmax
          GROUP BY p.id, p.size
          ORDER BY p.size ASC, p.id ASC
          LIMIT $lim OFFSET $off
        ";
        $st = $this->pdo->prepare($sql);
        // outer
        $st->bindValue(':tier_out',   $tier);
        $st->bindValue(':status_out', $status);
        // inner
        $st->bindValue(':tier_in',    $tier);
        $st->bindValue(':status_in',  $status);
        // anchors
        foreach ($aParams as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_INT);
        // misc
        $st->bindValue(':anchorsCount', $anchorsCount, PDO::PARAM_INT);
        $st->bindValue(':szmin',        $szMin, PDO::PARAM_INT);
        $st->bindValue(':szmax',        $szMax, PDO::PARAM_INT);
        $st->execute();

        $rows  = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $csv  = (string)($r['member_cluster_ids_csv'] ?? '');
            $list = $csv !== '' ? array_map('intval', explode(',', $csv)) : [];
            $items[] = [
                'palette_id'         => (int)$r['palette_id'],
                'size'               => (int)$r['size'],
                'member_cluster_ids' => $list,
            ];
        }

        // COUNT
        $countSql = "
          SELECT COUNT(*) AS n
          FROM (
            SELECT p2.id
            FROM palettes p2
            JOIN palette_members pm2 ON pm2.palette_id = p2.id
            WHERE p2.tier   = :tier_in
              AND p2.status = :status_in
              AND p2.size BETWEEN :szmin AND :szmax
              AND pm2.member_cluster_id IN ($in)
            GROUP BY p2.id
            HAVING COUNT(DISTINCT pm2.member_cluster_id) = :anchorsCount
          ) x
        ";
        $ct = $this->pdo->prepare($countSql);
        $ct->bindValue(':tier_in',    $tier);
        $ct->bindValue(':status_in',  $status);
        foreach ($aParams as $k=>$v) $ct->bindValue($k, $v, PDO::PARAM_INT);
        $ct->bindValue(':anchorsCount', $anchorsCount, PDO::PARAM_INT);
        $ct->bindValue(':szmin',        $szMin, PDO::PARAM_INT);
        $ct->bindValue(':szmax',        $szMax, PDO::PARAM_INT);
        $ct->execute();
        $total = (int)($ct->fetchColumn() ?: 0);

        return ['items'=>$items, 'total_count'=>$total];
    }

    /** VISIBLE-ONLY strict include, constrained size (excludes hidden) */
    public function findIncludingAllClustersVisible(
        array $anchorClusterIds,
        string $tier = 'A',
        int $sizeMin = 1,
        int $sizeMax = 99,
        int $limit   = 200,
        int $offset  = 0
    ): array {
        $anchors = array_values(array_unique(array_map('intval', array_filter($anchorClusterIds, fn($v)=>$v>0))));
        if (!$anchors) return ['items'=>[], 'total_count'=>0];

        $anchorsCount = count($anchors);
        $aParams = [];
        foreach ($anchors as $i => $cid) { $aParams[":a".($i+1)] = $cid; }
        $in = implode(',', array_keys($aParams));

        $szMin = (int)$sizeMin; $szMax = (int)$sizeMax;
        $lim   = (int)$limit;   $off   = (int)$offset;

        $sql = "
          SELECT p.id AS palette_id,
                 p.size,
                 GROUP_CONCAT(DISTINCT pm.member_cluster_id ORDER BY pm.member_cluster_id SEPARATOR ',') AS member_cluster_ids_csv
          FROM palettes p
          JOIN palette_members pm ON pm.palette_id = p.id
          WHERE p.tier   = :tier_out
            AND p.status <> 'hidden'
            AND p.id IN (
              SELECT p2.id
              FROM palettes p2
              JOIN palette_members pm2 ON pm2.palette_id = p2.id
              WHERE p2.tier   = :tier_in
                AND p2.status <> 'hidden'
                AND pm2.member_cluster_id IN ($in)
              GROUP BY p2.id
              HAVING COUNT(DISTINCT pm2.member_cluster_id) = :anchorsCount
            )
            AND p.size BETWEEN :szmin AND :szmax
          GROUP BY p.id, p.size
          ORDER BY p.size ASC, p.id ASC
          LIMIT $lim OFFSET $off
        ";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':tier_out', $tier);
        $st->bindValue(':tier_in',  $tier);
        foreach ($aParams as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_INT);
        $st->bindValue(':anchorsCount', $anchorsCount, PDO::PARAM_INT);
        $st->bindValue(':szmin',        $szMin, PDO::PARAM_INT);
        $st->bindValue(':szmax',        $szMax, PDO::PARAM_INT);
        $st->execute();

        $rows  = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $r) {
            $csv  = (string)($r['member_cluster_ids_csv'] ?? '');
            $list = $csv !== '' ? array_map('intval', explode(',', $csv)) : [];
            $items[] = [
                'palette_id'         => (int)$r['palette_id'],
                'size'               => (int)$r['size'],
                'member_cluster_ids' => $list,
            ];
        }

        $countSql = "
          SELECT COUNT(*) AS n
          FROM (
            SELECT p2.id
            FROM palettes p2
            JOIN palette_members pm2 ON pm2.palette_id = p2.id
            WHERE p2.tier   = :tier_in
              AND p2.status <> 'hidden'
              AND p2.size BETWEEN :szmin AND :szmax
              AND pm2.member_cluster_id IN ($in)
            GROUP BY p2.id
            HAVING COUNT(DISTINCT pm2.member_cluster_id) = :anchorsCount
          ) x
        ";
        $ct = $this->pdo->prepare($countSql);
        $ct->bindValue(':tier_in', $tier);
        foreach ($aParams as $k=>$v) $ct->bindValue($k, $v, PDO::PARAM_INT);
        $ct->bindValue(':anchorsCount', $anchorsCount, PDO::PARAM_INT);
        $ct->bindValue(':szmin',        $szMin, PDO::PARAM_INT);
        $ct->bindValue(':szmax',        $szMax, PDO::PARAM_INT);
        $ct->execute();
        $total = (int)($ct->fetchColumn() ?: 0);

        return ['items'=>$items, 'total_count'=>$total];
    }

    /** VISIBLE-ONLY (HOT): include ALL anchors; any size (1..99); excludes hidden */
    public function findIncludingAllClustersVisibleAnySize(
        array $anchorClusterIds,
        string $tier = 'A',
        int $limit   = 200,
        int $offset  = 0
    ): array {
        return $this->findIncludingAllClustersVisible($anchorClusterIds, $tier, 1, 99, $limit, $offset);
    }

    /**
     * IDs-only selector: palettes that contain ≥1 member from each cluster-group.
     * K-of-N supported via $minGroupsHit.
     */
  public function findPaletteIdsByClusterGroups(
    array $clusterGroups,
    int $limit = 200,
    int $offset = 0,
    ?int $minGroupsHit = null
): array {
    // Sanitize → array of non-empty int[]
    if (!is_array($clusterGroups)) return ['palette_ids'=>[], 'total_count'=>0];
    $groups = [];
    foreach ($clusterGroups as $g) {
        if (!is_array($g)) continue;
        $g = array_values(array_unique(array_filter(array_map('intval', $g), fn($v)=>$v>0)));
        if ($g) $groups[] = $g;
    }
    if (!$groups) return ['palette_ids'=>[], 'total_count'=>0];

    $numGroups     = count($groups);
    $requireGroups = ($minGroupsHit !== null)
        ? max(1, min((int)$minGroupsHit, $numGroups))
        : $numGroups;

    $limit  = max(1, (int)$limit);
    $offset = max(0, (int)$offset);

    // Build CASE once, gather bind values once
    $caseParts = [];
    $bindVals  = [];
    foreach ($groups as $i => $g) {
        $ph = implode(',', array_fill(0, count($g), '?'));
        $caseParts[] = "WHEN pm.member_cluster_id IN ($ph) THEN " . ($i + 1);
        foreach ($g as $cid) $bindVals[] = (int)$cid;
    }
    $groupCase = "CASE " . implode(' ', $caseParts) . " ELSE NULL END";

    // Inner subquery (VISIBLE RULE HERE)
    $innerSql = "
      SELECT
        pm.palette_id,
        {$groupCase} AS grp
      FROM palette_members pm
      JOIN palettes p ON p.id = pm.palette_id
      WHERE p.status <> 'hidden'
    ";

    // COUNT
    $countSql = "
      SELECT COUNT(*) FROM (
        SELECT t.palette_id
        FROM ( $innerSql ) AS t
        WHERE t.grp IS NOT NULL
        GROUP BY t.palette_id
        HAVING COUNT(DISTINCT t.grp) >= ?
      ) AS x
    ";
    $st = $this->pdo->prepare($countSql);
    $bind = 1;
    foreach ($bindVals as $v) $st->bindValue($bind++, $v, PDO::PARAM_INT);
    $st->bindValue($bind++, $requireGroups, PDO::PARAM_INT);
    $st->execute();
    $total = (int)($st->fetchColumn() ?: 0);
    if ($total === 0) return ['palette_ids'=>[], 'total_count'=>0];

    // PAGE: IDs only
    $pageSql = "
      SELECT t.palette_id
      FROM ( $innerSql ) AS t
      WHERE t.grp IS NOT NULL
      GROUP BY t.palette_id
      HAVING COUNT(DISTINCT t.grp) >= ?
      ORDER BY t.palette_id DESC
      LIMIT ? OFFSET ?
    ";
    $st = $this->pdo->prepare($pageSql);
    $bind = 1;
    foreach ($bindVals as $v) $st->bindValue($bind++, $v, PDO::PARAM_INT);
    $st->bindValue($bind++, $requireGroups, PDO::PARAM_INT);
    $st->bindValue($bind++, $limit,  PDO::PARAM_INT);
    $st->bindValue($bind++, $offset, PDO::PARAM_INT);
    $st->execute();

    $ids = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (int)$row['palette_id'];
    }
    return ['palette_ids' => $ids, 'total_count' => $total];
}

/**
 * Visible palettes that include at least ONE member from EACH cluster group.
 * Example input: [[101,102],[205,206,207]]  ⇒  each returned palette has ≥1 from group1 AND ≥1 from group2.
 *
 * Returns rows like: [{palette_id:int, size:int}]
 */
public function findVisibleAnySizeByClusterGroups(array $clusterGroups, int $limit = 50, int $offset = 0): array
{
    // Normalize input to non-empty int[] groups
    $clusterGroups = array_values(array_filter($clusterGroups, function ($g) {
        if (!is_array($g)) return false;
        $g = array_values(array_unique(array_filter(array_map('intval', $g), fn($v)=>$v>0)));
        return count($g) > 0;
    }));
    if (!$clusterGroups) return [];

    // Build an EXISTS predicate per group against pm.member_cluster_id
    $preds  = [];
    $params = [];
    $pi = 0;
    foreach ($clusterGroups as $g) {
        $phs = [];
        foreach ($g as $cid) {
            $pi++;
            $params[":g{$pi}"] = (int)$cid;
            $phs[] = ":g{$pi}";
        }
        $preds[] = "EXISTS (
            SELECT 1
              FROM palette_members pm_g{$pi}
             WHERE pm_g{$pi}.palette_id = p.id
               AND pm_g{$pi}.member_cluster_id IN (" . implode(',', $phs) . ")
        )";
    }

    // "Visible" = not hidden (matches your other visible-only queries)
    $sql = "
        SELECT p.id AS palette_id, COUNT(DISTINCT pm.member_cluster_id) AS size
          FROM palettes p
          JOIN palette_members pm ON pm.palette_id = p.id
         WHERE p.status <> 'hidden'
           AND " . implode(' AND ', $preds) . "
         GROUP BY p.id
         ORDER BY size DESC, p.id ASC
         LIMIT :limit OFFSET :offset
    ";

    $st = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_INT);
    $st->bindValue(':limit',  max(1, (int)$limit),  PDO::PARAM_INT);
    $st->bindValue(':offset', max(0, (int)$offset), PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}




    /** Hydrate full members for a set of palette IDs (visible-only, any size) */
    public function hydrateVisibleAnySizeByIds(array $paletteIds, int $limit = 200, int $offset = 0): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $paletteIds), fn($v)=>$v>0)));
        if (!$ids) return ['items'=>[], 'total_count'=>0];

        $limit  = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        $ph = implode(',', array_fill(0, count($ids), '?'));

        // Total (visible-only among provided ids)
        $countSql = "SELECT COUNT(*) FROM palettes p WHERE p.status='active' AND p.id IN ($ph)";
        $st = $this->pdo->prepare($countSql);
        foreach ($ids as $i=>$v) $st->bindValue($i+1, $v, PDO::PARAM_INT);
        $st->execute();
        $total = (int)($st->fetchColumn() ?: 0);
        if ($total === 0) return ['items'=>[], 'total_count'=>0];

        // Page: full member sets (no WHERE shrink)
        $pageSql = "
          SELECT
            p.id AS palette_id,
            COUNT(DISTINCT pm.member_cluster_id) AS size,
            GROUP_CONCAT(DISTINCT pm.member_cluster_id ORDER BY pm.member_cluster_id SEPARATOR ',') AS member_clusters_csv
          FROM palettes p
          JOIN palette_members pm ON pm.palette_id = p.id
          WHERE p.status='active' AND p.id IN ($ph)
          GROUP BY p.id
          ORDER BY FIELD(p.id, $ph), p.id DESC
          LIMIT ? OFFSET ?
        ";
        $st = $this->pdo->prepare($pageSql);
        $bind = 1;
        foreach ($ids as $v) $st->bindValue($bind++, $v, PDO::PARAM_INT);
        foreach ($ids as $v) $st->bindValue($bind++, $v, PDO::PARAM_INT);
        $st->bindValue($bind++, $limit,  PDO::PARAM_INT);
        $st->bindValue($bind++, $offset, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['palette_id'];
            $size = (int)$row['size'];
            $csv = (string)($row['member_clusters_csv'] ?? '');
            $memberClusterIds = $csv !== '' ? array_values(array_map('intval', explode(',', $csv))) : [];
            $items[] = [
                'palette_id'         => $pid,
                'size'               => $size,
                'member_cluster_ids' => $memberClusterIds,
            ];
        }
        return ['items'=>$items,'total_count'=>$total];
    }

    /** Filter a list of cluster_ids down to those that appear in active palettes */
    public function clustersHavingPalettes(array $clusterIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clusterIds), fn($v) => $v > 0)));
        if (!$ids) return [];

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
          SELECT DISTINCT pm.member_cluster_id
          FROM palette_members pm
          JOIN palettes p ON p.id = pm.palette_id
          WHERE p.status = 'active'
            AND pm.member_cluster_id IN ($ph)
        ";

        $st = $this->pdo->prepare($sql);
        foreach ($ids as $i => $v) $st->bindValue($i + 1, $v, PDO::PARAM_INT);
        $st->execute();

        $out = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $out[] = (int)$row[0];
        }
        return $out;
    }

    /** Fetch nickname / terry_says / terry_fav for a set of palette IDs */
    public function getMetaForPaletteIds(array $paletteIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $paletteIds), fn($v)=>$v>0)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT id, nickname, terry_says, terry_fav FROM palettes WHERE id IN ($ph)";
        $st  = $this->pdo->prepare($sql);
        foreach ($ids as $i=>$v) $st->bindValue($i+1, $v, \PDO::PARAM_INT);
        $st->execute();

        $out = [];
        while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
            $pid = (int)$r['id'];
            $out[$pid] = [
                'nickname'   => $r['nickname'] ?? null,
                'terry_says' => $r['terry_says'] ?? null,
                'terry_fav'  => (int)($r['terry_fav'] ?? 0),
            ];
        }
        return $out;
    }

    /** Fetch tags per palette (PRIMARY/UNIQUE on (palette_id, tag) expected) */
    public function getTagsForPaletteIds(array $paletteIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $paletteIds), fn($v)=>$v>0)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT palette_id, tag FROM palette_tags WHERE palette_id IN ($ph) ORDER BY tag ASC";
        $st  = $this->pdo->prepare($sql);
        foreach ($ids as $i=>$v) $st->bindValue($i+1, $v, \PDO::PARAM_INT);
        $st->execute();

        $out = [];
        while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
            $pid = (int)$r['palette_id'];
            $tag = (string)$r['tag'];
            $out[$pid][] = $tag;
        }
        foreach ($ids as $pid) if (!isset($out[$pid])) $out[$pid] = [];
        return $out;
    }


    public function updatePaletteMeta(int $paletteId, ?string $nickname, ?string $terrySays, int $terryFav): bool
    {
        if ($paletteId <= 0) return false;

        // Normalize
        $nickname  = ($nickname !== null && $nickname !== '') ? trim($nickname) : null;
        $terrySays = ($terrySays !== null && $terrySays !== '') ? trim($terrySays) : null;
        $terryFav  = $terryFav ? 1 : 0;

        $sql = "UPDATE palettes
                  SET nickname = :nickname,
                      terry_says = :terry_says,
                      terry_fav = :terry_fav
                WHERE id = :id";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':nickname',   $nickname,  $nickname === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue(':terry_says', $terrySays, $terrySays === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue(':terry_fav',  $terryFav,  \PDO::PARAM_INT);
        $st->bindValue(':id',         $paletteId, \PDO::PARAM_INT);
        return $st->execute();
    }

    // In PdoPaletteRepository class:

    /** Return tags for a palette (sorted) */
    public function getTagsForPalette(int $paletteId): array
    {
        $st = $this->pdo->prepare("SELECT tag FROM palette_tags WHERE palette_id = ? ORDER BY tag ASC");
        $st->execute([$paletteId]);
        return array_map(static fn($r) => (string)$r['tag'], $st->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    /**
     * Replace the full tag set for a palette.
     * Transactional: inserts new, deletes missing.
     */
    public function replaceTagsForPalette(int $paletteId, array $tags): bool
    {
        $tags = array_values(array_unique(array_filter(array_map(static function($t){
            $t = trim((string)$t);
            return $t === '' ? null : preg_replace('/\s+/u', ' ', $t);
        }, $tags))));

        $this->pdo->beginTransaction();
        try {
            // current
            $cur = [];
            $st = $this->pdo->prepare("SELECT tag FROM palette_tags WHERE palette_id = ?");
            $st->execute([$paletteId]);
            while ($r = $st->fetch(PDO::FETCH_NUM)) $cur[(string)$r[0]] = true;

            $new = array_fill_keys($tags, true);

            // inserts
            if ($new) {
                $ins = $this->pdo->prepare("INSERT IGNORE INTO palette_tags (palette_id, tag) VALUES (?, ?)");
                foreach ($new as $t => $_) {
                    if (!isset($cur[$t])) $ins->execute([$paletteId, $t]);
                }
            }

            // deletes
            if ($cur) {
                $del = $this->pdo->prepare("DELETE FROM palette_tags WHERE palette_id = ? AND tag = ?");
                foreach ($cur as $t => $_) {
                    if (!isset($new[$t])) $del->execute([$paletteId, $t]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return false;
        }
    }


    /**
     * Search distinct tags (case-insensitive substring match) with counts.
     * Example: searchPaletteTags("brown") → [['tag'=>'brown','count'=>123], ...]
     */
    public function searchPaletteTags(string $query, int $limit = 25): array
    {
        $query = trim($query);
        $limit = max(1, min(100, $limit));

        if ($query === '') {
            $sql = "
              SELECT tag, COUNT(*) AS cnt
                FROM palette_tags
               GROUP BY tag
               ORDER BY cnt DESC, tag ASC
               LIMIT :limit
            ";
            $st = $this->pdo->prepare($sql);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            $like = '%' . $query . '%';
            $sql = "
              SELECT tag, COUNT(*) AS cnt
                FROM palette_tags
               WHERE tag LIKE :like
               GROUP BY tag
               ORDER BY cnt DESC, tag ASC
               LIMIT :limit
            ";
            $st = $this->pdo->prepare($sql);
            $st->bindValue(':like', $like, PDO::PARAM_STR);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn($r) => [
            'tag'   => (string)$r['tag'],
            'count' => (int)$r['cnt'],
        ], $rows);
    }




}
