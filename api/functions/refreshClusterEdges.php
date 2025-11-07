<?php
/**
 * /functions/refreshClusterEdges.php
 *
 * Rebuild cluster_friends from color_friends.
 *
 * - Reads all pairs (hex1, hex2) from color_friends
 * - Joins each hex back to its cluster_id (via colors table)
 * - Aggregates by (cluster1, cluster2), counting how many distinct color-friend pairs support that edge
 * - Inserts or updates cluster_friends with the new weight
 *
 * @param PDO   $pdo
 * @param int   $batchPairs    Optional limit on how many color_friends pairs to process
 * @param int   $timeBudgetMs  Soft runtime budget (not enforced in this simple rebuild)
 * @return array Summary { ok, edges_inserted, edges_updated, elapsed_ms }
 */
function refreshClusterEdges(PDO $pdo, int $batchPairs = 0, int $timeBudgetMs = 0): array {
    $started = microtime(true);

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // We’ll rebuild the cluster_friends table in one shot.
        // If you want true incremental batching, that can be added later.
        $sql = "
          INSERT INTO cluster_friends (c_from, c_to, weight, updated_at)
          SELECT
            LEAST(c1.cluster_id, c2.cluster_id) AS c_from,
            GREATEST(c1.cluster_id, c2.cluster_id) AS c_to,
            COUNT(*) AS weight,
            NOW() AS updated_at
          FROM color_friends f
          JOIN colors c1 ON c1.hex6 = f.hex1
          JOIN colors c2 ON c2.hex6 = f.hex2
          WHERE c1.cluster_id IS NOT NULL
            AND c2.cluster_id IS NOT NULL
            AND c1.cluster_id <> c2.cluster_id
          GROUP BY c_from, c_to
          ON DUPLICATE KEY UPDATE
            weight = VALUES(weight),
            updated_at = VALUES(updated_at)
        ";

        $affected = $pdo->exec($sql);

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        return [
            'ok'             => true,
            'edges_affected' => $affected,
            'elapsed_ms'     => $elapsedMs
        ];
    } catch (Throwable $e) {
        return [
            'ok'    => false,
            'error' => $e->getMessage()
        ];
    }
}
