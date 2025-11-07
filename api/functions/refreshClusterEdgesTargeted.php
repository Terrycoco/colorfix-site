<?php
/**
 * /functions/refreshClusterEdgesTargeted.php
 *
 * Targeted rebuild of cluster_friends for a specific set of clusters.
 * - Re-aggregates edges from color_friends → colors (by cluster_id)
 * - Deletes existing cluster_friends edges that touch the targeted clusters
 * - Reinserts only the recomputed edges for those clusters
 *
 * Tables/columns assumed:
 *   color_friends(hex1, hex2)
 *   colors(id, hex6, cluster_id)
 *   cluster_friends(c_from, c_to, weight, updated_at) with UNIQUE(c_from, c_to)
 */

declare(strict_types=1);

/**
 * Refresh only edges that touch the given cluster IDs.
 *
 * @param PDO   $pdo
 * @param int[] $clusterIds
 * @return array { ok, clusters_count, tmp_edges_count, deleted_edges, inserted_rows, elapsed_ms }
 */
function refreshClusterEdgesTargeted(PDO $pdo, array $clusterIds): array {
    $t0 = microtime(true);

    // Sanitize/normalize cluster list
    $clusterIds = array_values(array_unique(array_map('intval', $clusterIds)));
    $clusterIds = array_values(array_filter($clusterIds, fn($x) => $x > 0));

    if (count($clusterIds) === 0) {
        return [
            'ok' => true,
            'clusters_count'   => 0,
            'tmp_edges_count'  => 0,
            'deleted_edges'    => 0,
            'inserted_rows'    => 0,
            'elapsed_ms'       => (int)round((microtime(true) - $t0) * 1000),
            'note'             => 'no-op (empty cluster list)'
        ];
    }

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Use a transaction so delete/insert is atomic for the target set.
        $pdo->beginTransaction();

        // 1) Build a temp table of recomputed edges for the targeted clusters
        $place = implode(',', array_fill(0, count($clusterIds), '?'));

        // Use a temporary table so we can count rows easily and do a two-step replace.
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_cf_edges");
        $pdo->exec("
          CREATE TEMPORARY TABLE tmp_cf_edges (
            c_from INT NOT NULL,
            c_to   INT NOT NULL,
            weight INT NOT NULL,
            PRIMARY KEY (c_from, c_to)
          ) ENGINE=MEMORY
        ");

        $sqlInsertTmp = "
          INSERT INTO tmp_cf_edges (c_from, c_to, weight)
          SELECT
            LEAST(c1.cluster_id, c2.cluster_id) AS c_from,
            GREATEST(c1.cluster_id, c2.cluster_id) AS c_to,
            COUNT(*) AS weight
          FROM color_friends f
          JOIN colors c1 ON c1.hex6 = f.hex1
          JOIN colors c2 ON c2.hex6 = f.hex2
          WHERE c1.cluster_id IS NOT NULL
            AND c2.cluster_id IS NOT NULL
            AND c1.cluster_id <> c2.cluster_id
            AND (c1.cluster_id IN ($place) OR c2.cluster_id IN ($place))
          GROUP BY c_from, c_to
        ";
        $stTmp = $pdo->prepare($sqlInsertTmp);
        $k = 1;
        foreach ($clusterIds as $cid) { $stTmp->bindValue($k++, $cid, PDO::PARAM_INT); }
        foreach ($clusterIds as $cid) { $stTmp->bindValue($k++, $cid, PDO::PARAM_INT); }
        $stTmp->execute();

        $tmpCount = (int)$pdo->query("SELECT COUNT(*) FROM tmp_cf_edges")->fetchColumn();

        // 2) Delete existing edges in cluster_friends that touch the targeted clusters
        $sqlDel = "DELETE FROM cluster_friends WHERE c_from IN ($place) OR c_to IN ($place)";
        $stDel  = $pdo->prepare($sqlDel);
        $k = 1;
        foreach ($clusterIds as $cid) { $stDel->bindValue($k++, $cid, PDO::PARAM_INT); }
        foreach ($clusterIds as $cid) { $stDel->bindValue($k++, $cid, PDO::PARAM_INT); }
        $stDel->execute();
        $deleted = (int)$stDel->rowCount();

        // 3) Insert fresh edges from the temp table
        $sqlIns = "
          INSERT INTO cluster_friends (c_from, c_to, weight, updated_at)
          SELECT c_from, c_to, weight, NOW()
          FROM tmp_cf_edges
          ON DUPLICATE KEY UPDATE
            weight = VALUES(weight),
            updated_at = VALUES(updated_at)
        ";
        $insRows = $pdo->exec($sqlIns);

        $pdo->commit();

        return [
            'ok' => true,
            'clusters_count'   => count($clusterIds),
            'tmp_edges_count'  => $tmpCount,     // number of unique cluster edges recomputed
            'deleted_edges'    => $deleted,      // edges removed before reinserting
            'inserted_rows'    => $insRows,      // rows affected by the INSERT ... SELECT
            'elapsed_ms'       => (int)round((microtime(true) - $t0) * 1000),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok'    => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Convenience wrapper: take color IDs, resolve to cluster IDs, then refresh targeted edges.
 *
 * @param PDO   $pdo
 * @param int[] $colorIds
 * @return array
 */
function refreshClusterEdgesForColorIds(PDO $pdo, array $colorIds): array {
    $colorIds = array_values(array_unique(array_map('intval', $colorIds)));
    $colorIds = array_values(array_filter($colorIds, fn($x) => $x > 0));

    if (count($colorIds) === 0) {
        return [
            'ok' => true,
            'clusters_count'   => 0,
            'tmp_edges_count'  => 0,
            'deleted_edges'    => 0,
            'inserted_rows'    => 0,
            'elapsed_ms'       => 0,
            'note'             => 'no-op (empty color list)'
        ];
    }

    $ph = implode(',', array_fill(0, count($colorIds), '?'));
    $st = $pdo->prepare("SELECT DISTINCT cluster_id FROM colors WHERE id IN ($ph) AND cluster_id IS NOT NULL");
    foreach ($colorIds as $i => $id) {
        $st->bindValue($i + 1, $id, PDO::PARAM_INT);
    }
    $st->execute();
    $clusters = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));

    return refreshClusterEdgesTargeted($pdo, $clusters);
}
