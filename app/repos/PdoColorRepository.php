<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\Color;
use PDO;
use App\Lib\ClusterQuantize;

final class PdoColorRepository implements ColorRepository
{
    public function __construct(private PDO $pdo) {}

public function getById(int $id): ?\App\Entities\Color
{
    $sql = "SELECT * FROM colors WHERE id = :id LIMIT 1";
    $st  = $this->pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;

    return new \App\Entities\Color($row); // full colors row, unchanged
}

// Add this method inside PdoColorRepository (next to getById)
public function getRowById(int $id): ?array
{
    $st = $this->pdo->prepare("SELECT * FROM colors WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}




public function listByBrandExcept(string $brand, int $excludeId, int $max = 500): array
{
    $max = max(1, (int)$max);

    $sql = "
        SELECT
            id,
            name,
            brand,
            code,
            chip_num,
            hex6,
            lab_l, lab_a, lab_b,
            cluster_id
        FROM colors
        WHERE brand = :brand
          AND id <> :exclude
          AND COALESCE(is_stain, 0) = 0
        LIMIT :lim
    ";
    $st = $this->pdo->prepare($sql);
    $st->bindValue(':brand', $brand, \PDO::PARAM_STR);
    $st->bindValue(':exclude', $excludeId, \PDO::PARAM_INT);
    $st->bindValue(':lim', $max, \PDO::PARAM_INT);
    $st->execute();

    $out = [];
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $out[] = new \App\entities\Color($row);
    }
    return $out;
}



/**
 * Return *one* usable color_id for a given cluster (must have LAB).
 * Deterministic but schema-agnostic: just take the lowest id that has LAB.
 */
public function getAnyColorIdForClusterWithLab(int $clusterId): ?int
{
    $clusterId = (int)$clusterId;
    if ($clusterId <= 0) return null;

    $sql = "
        SELECT c.id
        FROM colors c
        WHERE c.cluster_id = :cid
          AND c.lab_l IS NOT NULL
          AND c.lab_a IS NOT NULL
          AND c.lab_b IS NOT NULL
        ORDER BY c.id ASC
        LIMIT 1
    ";
    $st = $this->pdo->prepare($sql);
    $st->bindValue(':cid', $clusterId, \PDO::PARAM_INT);
    $st->execute();
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}


// INSERT: accept hex6 OR hex OR r,g,b; always store hex6 and r,g,b
public function insertColor(array $fields): int
   {
        // Accept these keys; derive hex6 if missing; clamp rgb if present
        // In insertColor(), extend the whitelist:
        $allowed = [
            'name','brand','code','chip_num',
            'hex6','hex','r','g','b',
            'lab_l','lab_a','lab_b',
            'hcl_l','hcl_c','hcl_h',
            'hsl_h','hsl_s','hsl_l',
            'contrast_text_color',
            // NEW: let the service set backups on insert
            'orig_lab_l','orig_hcl_l',
             'exterior','interior','is_inactive',  
        ];

        $data = array_intersect_key($fields, array_flip($allowed));

        // Required basics
        foreach (['name','brand'] as $req) {
            if (!isset($data[$req]) || $data[$req] === '') {
                throw new \InvalidArgumentException("insertColor: missing required field '$req'");
            }
        }

        // Normalize / Derive hex6
        $hex6 = $data['hex6'] ?? null;
        $hex  = $data['hex']  ?? null;

        if ($hex6 !== null && $hex6 !== '') {
            $hex6 = strtoupper(ltrim((string)$hex6, '#'));
        } elseif ($hex !== null && $hex !== '') {
            $hex6 = strtoupper(ltrim((string)$hex, '#'));
        } elseif (isset($data['r'],$data['g'],$data['b'])) {
            $r = max(0, min(255, (int)$data['r']));
            $g = max(0, min(255, (int)$data['g']));
            $b = max(0, min(255, (int)$data['b']));
            $hex6 = sprintf('%02X%02X%02X', $r, $g, $b);
            $data['r'] = $r; $data['g'] = $g; $data['b'] = $b;
        }

        if (!is_string($hex6) || !preg_match('/^[0-9A-F]{6}$/', $hex6)) {
            throw new \InvalidArgumentException("insertColor: hex/hex6 or r,g,b required");
        }
        $data['hex6'] = $hex6;

        // Ensure r,g,b stored (derive from hex6 if not provided)
        if (!isset($data['r'],$data['g'],$data['b'])) {
            $data['r'] = hexdec(substr($hex6, 0, 2));
            $data['g'] = hexdec(substr($hex6, 2, 2));
            $data['b'] = hexdec(substr($hex6, 4, 2));
        }

        // Build INSERT (drop helper-only keys)
        unset($data['hex']); // we only store hex6
        $cols = array_keys($data);
        $sql  = "INSERT INTO colors (" . implode(',', $cols) . ")
                VALUES (" . implode(',', array_map(fn($c)=>":$c", $cols)) . ")";
        $st   = $this->pdo->prepare($sql);

        foreach ($data as $k => $v) {
            // ints for r,g,b; strings elsewhere; floats ok as strings (MySQL coerces)
            if (in_array($k, ['r','g','b','exterior','interior','is_inactive'], true)) {
                $st->bindValue(":$k", (int)$v, \PDO::PARAM_INT);
            } else {
                $st->bindValue(":$k", $v, \PDO::PARAM_STR);
            }
        }

        $st->execute();
        return (int)$this->pdo->lastInsertId();
    }

// UPDATE: partial allowed; validate hex6 if provided; clamp r,g,b if provided
public function updateColor(int $id, array $fields): void
{
// In updateColor(), extend the whitelist:
$allowed = [
    'name','brand','code','chip_num',
    'hex6','r','g','b',
    'lab_l','lab_a','lab_b',
    'hcl_l','hcl_c','hcl_h',
    'hsl_h','hsl_s','hsl_l',
    'contrast_text_color',
    // NEW: backups (service sets them once when needed)
    'orig_lab_l','orig_hcl_l',
      'exterior','interior','is_inactive', 
];
    $data = array_intersect_key($fields, array_flip($allowed));
    if (!$data) throw new \InvalidArgumentException("updateColor: no updatable fields provided");

    if (isset($data['hex6'])) {
        $data['hex6'] = strtoupper(ltrim((string)$data['hex6'], '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $data['hex6'])) {
            throw new \InvalidArgumentException("updateColor: hex6 must be 6 hex chars");
        }
    }

    foreach (['r','g','b','exterior','interior','is_inactive'] as $k) {
        if (isset($data[$k]) && $data[$k] !== null && $data[$k] !== '') {
            $v = (int)$data[$k];
            $data[$k] = max(0, min(255, $v));
        }
    }

    $sets = [];
    foreach (array_keys($data) as $k) { $sets[] = "$k = :$k"; }
    $sql = "UPDATE colors SET " . implode(', ', $sets) . " WHERE id = :id";
    $st  = $this->pdo->prepare($sql);
    $st->bindValue(':id', $id, \PDO::PARAM_INT);

    foreach ($data as $k => $v) {
        $param = in_array($k, ['r','g','b','exterior','interior','is_inactive'], true)
            ? \PDO::PARAM_INT
            : \PDO::PARAM_STR;
        $st->bindValue(":$k", $v, $param);
    }

    $st->execute();
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

/** Returns: color_id, name, brand, hex6, lab_l, lab_a, lab_b, cluster_id, de76_sq */
public function nearestColorCandidates(array $opts): array
{
    $aL = (float)$opts['aL'];
    $aA = (float)$opts['aA'];
    $aB = (float)$opts['aB'];
    $anchorId      = (int)$opts['anchorId'];
    $anchorCluster = isset($opts['anchorCluster']) ? (int)$opts['anchorCluster'] : null;
    $excludeTwins  = array_key_exists('excludeTwins', $opts) ? (bool)$opts['excludeTwins'] : true;
    $brands        = array_values(array_unique(array_filter(array_map('trim', (array)($opts['brands'] ?? [])))));
    $preLimit      = max(1, (int)($opts['preLimit'] ?? 50));

    $params = [$aL, $aA, $aB, $anchorId];

    $brandSql = '';
    if (!empty($brands)) {
        $brandSql = ' AND c.brand IN (' . implode(',', array_fill(0, count($brands), '?')) . ') ';
    }

    $excludeTwinSql = '';
    if ($excludeTwins && $anchorCluster !== null) {
        $excludeTwinSql = ' AND COALESCE(c.cluster_id, ch.cluster_id) <> ? ';
    }

    $sql = "
        SELECT
          c.id                                         AS color_id,
          c.name,
          c.brand,
          UPPER(c.hex6)                                 AS hex6,
          c.lab_l, c.lab_a, c.lab_b,
          COALESCE(c.cluster_id, ch.cluster_id)         AS cluster_id,
          (
            (c.lab_l - ?) * (c.lab_l - ?) +
            (c.lab_a - ?) * (c.lab_a - ?) +
            (c.lab_b - ?) * (c.lab_b - ?)
          ) AS de76_sq
        FROM colors c
        LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
        WHERE c.id <> ?
          AND c.hex6 IS NOT NULL
          AND c.lab_l IS NOT NULL AND c.lab_a IS NOT NULL AND c.lab_b IS NOT NULL
          AND COALESCE(c.is_stain, 0) = 0
          $excludeTwinSql
          $brandSql
        ORDER BY de76_sq ASC
        LIMIT $preLimit
    ";

    // duplicate aL,aA,aB to match the 6 ? used in the de76_sq expression
    $params = [$aL, $aL, $aA, $aA, $aB, $aB, $anchorId];

    if ($excludeTwins && $anchorCluster !== null) {
        $params[] = $anchorCluster;
    }
    if (!empty($brands)) {
        foreach ($brands as $b) $params[] = $b;
    }

    $st = $this->pdo->prepare($sql);
    $st->execute($params);

    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    try {
        $aL0 = (float)$opts['aL'];
        $aA0 = (float)$opts['aA'];
        $aB0 = (float)$opts['aB'];

        foreach ($rows as &$r) {
            if (!isset($r['lab_l'],$r['lab_a'],$r['lab_b'])) {
                $r['__k1'] = PHP_FLOAT_MAX; $r['__k2'] = PHP_FLOAT_MAX; continue;
            }
            $L = (float)$r['lab_l'];
            $A = (float)$r['lab_a'];
            $B = (float)$r['lab_b'];

            [$k1, $k2] = \App\lib\NearWhiteComparator::combinedHueFirstKeyForWhiteSeed($aL0,$aA0,$aB0,$L,$A,$B);
            $r['__k1'] = (float)$k1;
            $r['__k2'] = (float)$k2;
        }
        unset($r);

        usort($rows, fn($x,$y) => ($x['__k1'] <=> $y['__k1']) ?: ($x['__k2'] <=> $y['__k2']));
        foreach ($rows as &$r) { unset($r['__k1'], $r['__k2']); }
        unset($r);
    } catch (\Throwable $e) {
        // fail-safe: keep original DE76 order on any error
    }

    return $rows;
}


public function listAllCandidates(?int $excludeId, ?int $excludeClusterId, array $brands = []): array
{
    $where  = [];
    $params = [];

    // must have usable data
    $where[] = "c.hex6 IS NOT NULL";
    $where[] = "c.lab_l IS NOT NULL AND c.lab_a IS NOT NULL AND c.lab_b IS NOT NULL";
    // exclude stains from the very start
    $where[] = "COALESCE(c.is_stain, 0) = 0";

    if ($excludeId !== null && $excludeId > 0) {
        $where[] = "c.id <> ?";
        $params[] = $excludeId;
    }

    if ($excludeClusterId !== null && $excludeClusterId > 0) {
        $where[] = "COALESCE(c.cluster_id, ch.cluster_id) <> ?";
        $params[] = $excludeClusterId;
    }

    if (!empty($brands)) {
        $brands = array_values(array_unique(array_filter(
            array_map(fn($b)=> strtolower(trim((string)$b)), $brands),
            fn($b)=> $b !== ''
        )));
        if ($brands) {
            $ph = implode(',', array_fill(0, count($brands), '?'));
            $where[] = "LOWER(c.brand) IN ($ph)";
            foreach ($brands as $b) $params[] = $b;
        }
    }

    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $sql = "
        SELECT
          c.id                         AS color_id,
          c.name,
          c.brand,
          UPPER(c.hex6)                AS hex6,
          c.lab_l, c.lab_a, c.lab_b,
          c.lrv,
          COALESCE(c.cluster_id, ch.cluster_id) AS cluster_id
        FROM colors c
        LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
        $whereSql
    ";

    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}




// Get one LRV
public function getLrvByColorId(int $id): ?float
{
    $st = $this->pdo->prepare("SELECT lrv FROM colors WHERE id = :id LIMIT 1");
    $st->execute([':id'=>$id]);
    $v = $st->fetchColumn();
    return is_numeric($v) ? (float)$v : null;
}

// Map id => lrv for a set
public function getLrvMap(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v)=>$v>0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $this->pdo->prepare("SELECT id, lrv FROM colors WHERE id IN ($ph)");
    $st->execute($ids);
    $out = [];
    while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
        $id = (int)($r['id'] ?? 0);
        $v  = $r['lrv'] ?? null;
        $out[$id] = is_numeric($v) ? (float)$v : null;
    }
    return $out;
}



// in App\repos\PdoColorRepository
public function getMatchableRows(?array $brands = null): array
{
    $where = "WHERE lab_l IS NOT NULL AND lab_a IS NOT NULL AND lab_b IS NOT NULL";
    $params = [];
    if ($brands && count($brands)) {
        $in = implode(',', array_fill(0, count($brands), '?'));
        $where .= " AND brand IN ($in)";
        $params = array_values($brands);
    }
    $sql = "
      SELECT
        id      AS color_id,
        brand,
        lab_l, lab_a, lab_b,
        cluster_id,
        name,
        hex6    AS hex
      FROM colors
      $where
    ";
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}


/**
     * Resolve color_ids to cluster_ids (deduped >0), falling back via cluster_hex if needed.
     * Returns: int[] of cluster_ids.
     */
    public function resolveClusterIdsForColorIds(array $colorIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($colorIds, fn($v)=>$v>0))));
        if (!$ids) return [];

        // Build named placeholders :c1,:c2,...
        $params = [];
        foreach ($ids as $i => $id) { $params[':c'.($i+1)] = $id; }
        $in = implode(',', array_keys($params));

        $sql = "
            SELECT c.id, COALESCE(c.cluster_id, ch.cluster_id) AS cluster_id
            FROM colors c
            LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
            WHERE c.id IN ($in)
        ";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $name => $val) $st->bindValue($name, $val, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $cid = (int)($r['cluster_id'] ?? 0);
            if ($cid > 0) $out[] = $cid;
        }
        return array_values(array_unique($out));
    }






}
