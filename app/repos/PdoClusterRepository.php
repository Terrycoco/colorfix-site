<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoClusterRepository
{
    public function __construct(private PDO $pdo) {}

    /** anchors: id, hex6, cluster_id (coalesce colors.cluster_id, cluster_hex.cluster_id) */
    public function getAnchorsByColorIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
        if (!$ids) return [];
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT c.id, c.hex6, COALESCE(c.cluster_id, ch.cluster_id) AS cluster_id
                FROM colors c
                LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
                WHERE c.id IN ($ph)";
        $st = $this->pdo->prepare($sql);
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** union of friend clusters for any of the provided cluster_ids */
    public function getFriendClustersUnion(array $clusterIds): array
    {
        $clusterIds = array_values(array_filter(array_map('intval', $clusterIds), fn($v)=>$v>0));
        if (!$clusterIds) return [];
        $ph  = implode(',', array_fill(0, count($clusterIds), '?'));
        $sql = "SELECT friends AS friend_cluster_id
                FROM cluster_friends_union
                WHERE cluster_key IN ($ph)
                GROUP BY friend_cluster_id";
        $st = $this->pdo->prepare($sql);
        $st->execute($clusterIds);
        return array_map(fn($r)=>(int)$r['friend_cluster_id'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** expand friend clusters → swatches with exclusions and optional brand filter */

// inside App\Repos\PdoClusterRepository class:

/**
 * Expand friend cluster ids to swatches with optional brand + neutral filtering.
 * Always returns:
 *  - id, name, brand, code, hex6, cluster_id
 *  - hcl_l, hue_cats, neutral_cats
 *  - is_neutral (0/1)
 */
// inside App\Repos\PdoClusterRepository

/**
 * Expand friend cluster ids to swatches.
 * mode: 'colors' | 'neutrals' | 'all'
 * Always returns: id, name, brand, code, hex6, cluster_id, hcl_l, hue_cats, neutral_cats, is_neutral
 */
// inside class PdoClusterRepository

public function expandClustersToSwatches(
    array $clusterIds,
    array $excludeHexUpper = [],
    array $excludeClusterIds = [],
    array $brands = [],
    string $mode = 'colors'
): array {
    $clusterIds = array_values(array_unique(array_map('intval', $clusterIds)));
    if (!$clusterIds) return [];

    $mode = in_array($mode, ['colors','neutrals','all'], true) ? $mode : 'colors';

    // WHERE (positional params only)
    $where  = [];
    $params = [];

    // friend clusters IN (via cluster_hex)
    $phClusters = implode(',', array_fill(0, count($clusterIds), '?'));
    $where[] = "ch.cluster_id IN ($phClusters)";
    $params  = array_merge($params, $clusterIds);

    // exclude anchors' hexes
    if (!empty($excludeHexUpper)) {
        $excHex = array_values(array_unique(array_map(fn($h)=>strtoupper(ltrim((string)$h,'#')), $excludeHexUpper)));
        if ($excHex) {
            $ph = implode(',', array_fill(0, count($excHex), '?'));
            $where[] = "UPPER(sv.hex6) NOT IN ($ph)";
            $params  = array_merge($params, $excHex);
        }
    }

    // exclude anchors' clusters
    if (!empty($excludeClusterIds)) {
        $exc = array_values(array_unique(array_map('intval', $excludeClusterIds)));
        if ($exc) {
            $ph = implode(',', array_fill(0, count($exc), '?'));
            $where[] = "ch.cluster_id NOT IN ($ph)";
            $params  = array_merge($params, $exc);
        }
    }

    // brand filter (brand codes lowercased by controller)
    if (!empty($brands)) {
        $bb = array_values(array_unique(array_map(fn($b)=>strtolower(trim((string)$b)), $brands)));
        if ($bb) {
            $ph = implode(',', array_fill(0, count($bb), '?'));
            $where[] = "LOWER(sv.brand) IN ($ph)";
            $params  = array_merge($params, $bb);
        }
    }

    // neutrals by mode (v2: neutral iff neutral_cats non-empty)
    if ($mode === 'colors') {
        $where[] = "NULLIF(TRIM(sv.neutral_cats), '') IS NULL";
    } elseif ($mode === 'neutrals') {
        $where[] = "NULLIF(TRIM(sv.neutral_cats), '') IS NOT NULL";
    }

    // 🚫 exclude stains from palettes
    $where[] = "COALESCE(sv.is_stain, 0) = 0";

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // GROUP header/order snippets (no named params)
    if ($mode === 'neutrals') {
        $groupHeaderExpr = "COALESCE(sv.neutral_cats,'')";
        $groupOrderExpr  = "
          CASE TRIM(sv.neutral_cats)
            WHEN 'Whites'         THEN 0
            WHEN 'Whites/Beiges'  THEN 1
            WHEN 'Beiges'         THEN 2
            WHEN 'Beiges/Greiges' THEN 3
            WHEN 'Greiges'        THEN 4
            WHEN 'Greiges/Grays'  THEN 5
            WHEN 'Grays'          THEN 6
            WHEN 'Grays/Blacks'   THEN 7
            WHEN 'Browns'         THEN 8
            WHEN 'Blacks'         THEN 9
            ELSE 99
          END
        ";
    } else {
        $groupHeaderExpr = "sv.hue_cats";
        $groupOrderExpr  = "COALESCE(sv.hue_cat_order, 999)";
    }

    $sql = "
      SELECT
        sv.*,
        $groupHeaderExpr AS group_header,
        $groupOrderExpr  AS group_order
      FROM cluster_hex ch
      JOIN swatch_view sv ON sv.hex6 = ch.hex6
      $whereSql
      ORDER BY group_order ASC, sv.hcl_c DESC, sv.hcl_l ASC
      LIMIT 20000
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}



    
 




    /** Direct friends of a pivot cluster from cluster_friends (graph adjacency) */
    public function getClusterFriendsOfPivot(int $pivot): array
    {
        $sql = "
          SELECT DISTINCT
            CASE WHEN cf.c_from = :p THEN cf.c_to ELSE cf.c_from END AS friend
          FROM cluster_friends cf
          WHERE cf.c_from = :p OR cf.c_to = :p
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':p' => $pivot]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /** Induced edges among a set of cluster ids: returns [['u'=>int,'v'=>int], ...] with u<v */
    public function getInducedEdges(array $nodes): array
    {
            $nodes = array_values(array_filter(array_map('intval', $nodes), fn($v)=>$v>0));
            if (!$nodes) return [];

            $placeA = []; $placeB = []; $params = [];
            foreach ($nodes as $i => $nid) {
                $pa = ":a{$i}"; $pb = ":b{$i}";
                $placeA[] = $pa;  $params[$pa] = $nid;
                $placeB[] = $pb;  $params[$pb] = $nid;
            }

            $sql = "
            SELECT LEAST(c_from,c_to) AS u, GREATEST(c_from,c_to) AS v
            FROM cluster_friends
            WHERE c_from IN (".implode(',', $placeA).")
                AND c_to   IN (".implode(',', $placeB).")
            ";
            $st = $this->pdo->prepare($sql);
            $st->execute($params);

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $out  = [];
            foreach ($rows as $r) {
                $u = (int)$r['u']; $v = (int)$r['v'];
                if ($u>0 && $v>0 && $u<$v) $out[] = ['u'=>$u, 'v'=>$v];
            }
            return $out;
    }



    /** Ensure a (h_r,c_r,l_r) exists in clusters and return its id */
  public function ensureClusterId(int $h_r, int $c_r, int $l_r): int
{
    $ins = $this->pdo->prepare("
        INSERT IGNORE INTO clusters (h_r, c_r, l_r) VALUES (:h,:c,:l)
    ");
    $ins->execute([':h'=>$h_r, ':c'=>$c_r, ':l'=>$l_r]);

    $sel = $this->pdo->prepare("
        SELECT id FROM clusters WHERE h_r=:h AND c_r=:c AND l_r=:l LIMIT 1
    ");
    $sel->execute([':h'=>$h_r, ':c'=>$c_r, ':l'=>$l_r]);
    return (int)$sel->fetchColumn();
}


    /** Upsert cluster_hex for a hex6 */
    public function upsertClusterHex(string $hex6, int $clusterId): void
    {
        $hex6 = strtoupper(ltrim($hex6, '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) return;

        $st = $this->pdo->prepare("
            INSERT INTO cluster_hex (hex6, cluster_id)
            VALUES (:hex6, :cid)
            ON DUPLICATE KEY UPDATE cluster_id = VALUES(cluster_id)
        ");
        $st->execute([':hex6'=>$hex6, ':cid'=>$clusterId]);
    }

    /** Update colors.cluster_id from cluster_hex for a specific hex6 */
    public function applyClusterToColorsByHex(string $hex6): int
    {
        $hex6 = strtoupper(ltrim($hex6, '#'));
        $st = $this->pdo->prepare("
            UPDATE colors c
            JOIN cluster_hex ch ON ch.hex6 = c.hex6
            SET c.cluster_id = ch.cluster_id
            WHERE UPPER(c.hex6) = :hex6
        ");
        $st->execute([':hex6'=>$hex6]);
        return $st->rowCount();
    }

    /**
     * Assign cluster for a single color id using its HCL in `colors`.
     * Returns the cluster_id or null if HCL missing.
     */
    public function assignClusterForColorId(int $colorId): ?int
    {
        $row = $this->pdo->prepare("
            SELECT hex6, hcl_h, hcl_c, hcl_l
            FROM colors WHERE id = :id LIMIT 1
        ");
        $row->execute([':id'=>$colorId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($r['hcl_h'] === null || $r['hcl_c'] === null || $r['hcl_l'] === null) return null;
        if (empty($r['hex6'])) return null;

        [$h_r, $c_r, $l_r] = self::roundTriplet((float)$r['hcl_h'], (float)$r['hcl_c'], (float)$r['hcl_l']);
        $cid = $this->ensureClusterId($h_r, $c_r, $l_r);
        if ($cid <= 0) return null;

        $this->upsertClusterHex((string)$r['hex6'], $cid);
        $this->applyClusterToColorsByHex((string)$r['hex6']);
        return $cid;
    }

    /**
     * Bulk assign clusters for a list of color ids.
     * Fast path (single SQL per step) + returns summary counts.
     */
    public function assignClustersBulkByColorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v)=>$v>0)));
        if (!$ids) return ['ok'=>true,'updated'=>0,'ensured'=>0];

        $this->pdo->beginTransaction();
        try {
            // 1) Ensure all needed clusters (rounded HCL) exist
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $ens = $this->pdo->prepare("
                INSERT IGNORE INTO clusters (h_r, c_r, l_r)
                SELECT DISTINCT
                  ((FLOOR(c.hcl_h + 0.5) + 360) % 360) AS h_r,
                  FLOOR(c.hcl_c + 0.5)                 AS c_r,
                  FLOOR(c.hcl_l + 0.5)                 AS l_r
                FROM colors c
                WHERE c.id IN ($ph)
                  AND c.hcl_h IS NOT NULL
                  AND c.hcl_c IS NOT NULL
                  AND c.hcl_l IS NOT NULL
            ");
            $ens->execute($ids);
            $ensured = $ens->rowCount();

            // 2) Upsert cluster_hex for those colors
            $up = $this->pdo->prepare("
                INSERT INTO cluster_hex (hex6, cluster_id)
                SELECT c.hex6, cl.id
                FROM colors c
                JOIN clusters cl
                  ON cl.h_r = ((FLOOR(c.hcl_h + 0.5) + 360) % 360)
                 AND cl.c_r = FLOOR(c.hcl_c + 0.5)
                 AND cl.l_r = FLOOR(c.hcl_l + 0.5)
                WHERE c.id IN ($ph) AND c.hex6 IS NOT NULL
                ON DUPLICATE KEY UPDATE cluster_id = VALUES(cluster_id)
            ");
            $up->execute($ids);

            // 3) Apply cluster_id to colors
            $upd = $this->pdo->prepare("
                UPDATE colors c
                JOIN cluster_hex ch ON ch.hex6 = c.hex6
                SET c.cluster_id = ch.cluster_id
                WHERE c.id IN ($ph)
            ");
            $upd->execute($ids);
            $updated = $upd->rowCount();

            $this->pdo->commit();
            return ['ok'=>true,'updated'=>$updated,'ensured'=>$ensured];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok'=>false,'error'=>$e->getMessage()];
        }
    }

    /**
 * Lightness map for swatch cards (from swatch_view): id => hcl_l|null
 * Input: color IDs
 * Output: [color_id => float|null]
 */
public function getLightnessMapForColorIds(array $colorIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $colorIds), fn($v)=>$v>0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $this->pdo->prepare("SELECT id, hcl_l FROM swatch_view WHERE id IN ($ph)");
    $st->execute($ids);
    $map = [];
    while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
        $id = (int)$r['id'];
        $map[$id] = is_numeric($r['hcl_l']) ? (float)$r['hcl_l'] : null;
    }
    return $map;
}


/**
 * Friend edges for a set of seed cluster_ids (provenance support).
 * Returns rows like: ['cluster_id'=>int, 'friend_cluster_id'=>int] for every edge touching a seed.
 */
public function getFriendEdgesForSeeds(array $seedClusterIds): array
{
    $seeds = array_values(array_unique(array_filter(array_map('intval', $seedClusterIds), fn($v)=>$v>0)));
    if (!$seeds) return [];

    // Use UNION with positional placeholders (no repeated named params).
    $inA = implode(',', array_fill(0, count($seeds), '?'));
    $inB = implode(',', array_fill(0, count($seeds), '?'));

    $sql = "
        SELECT cf.c_from AS cluster_id, cf.c_to AS friend_cluster_id
        FROM cluster_friends cf
        WHERE cf.c_from IN ($inA)
        UNION ALL
        SELECT cf.c_to   AS cluster_id, cf.c_from AS friend_cluster_id
        FROM cluster_friends cf
        WHERE cf.c_to IN ($inB)
    ";

    $st = $this->pdo->prepare($sql);
    $params = array_merge($seeds, $seeds); // bind for both IN() lists
    $st->execute($params);

    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['cluster_id']        = (int)$r['cluster_id'];
        $r['friend_cluster_id'] = (int)$r['friend_cluster_id'];
    }
    unset($r);
    return $rows;
}

public function getColorWithCluster(int $colorId): ?array
{
    $st = $this->pdo->prepare("
        SELECT
          c.id,
          c.name,
          c.brand,
          UPPER(c.hex6)                           AS hex6,
          c.lab_l, c.lab_a, c.lab_b,
          COALESCE(c.cluster_id, ch.cluster_id)   AS cluster_id
        FROM colors c
        LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
        WHERE c.id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $colorId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}




/**
 * Map cluster_id => #RRGGBB (leading #, length 7).
 */
public function getRepHexForClusterIds(array $clusterIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $clusterIds), fn($v)=>$v>0)));
    if (!$ids) return [];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    // Adjust table/column names if your schema differs
    $sql = "SELECT id AS cluster_id, rep_hex FROM clusters WHERE id IN ($ph)";
    $st  = $this->pdo->prepare($sql);
    $st->execute($ids);

    $out = [];
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $cid = (int)($row['cluster_id'] ?? 0);
        $hex = strtoupper(trim((string)($row['rep_hex'] ?? '')));
        if ($cid > 0 && $hex !== '') {
            if ($hex[0] !== '#') $hex = '#'.$hex;
            if (strlen($hex) === 7) $out[$cid] = $hex;
        }
    }
    return $out;
}

// inside class PdoClusterRepository
private static function roundTriplet(float $h, float $c, float $l): array
{
    $h_i = (int)floor($h + 0.5);
    $h_r = ($h_i % 360 + 360) % 360; // wrap 0..359
    $c_r = (int)floor($c + 0.5);
    $l_r = (int)floor($l + 0.5);
    return [$h_r, $c_r, $l_r];
}


}
