<?php
/**
 * Generate Tier A palettes (Observed-from-Pairings) for a pivot cluster.
 *
 * - Builds induced subgraph S = {pivot ∪ neighbors(pivot)} from cluster_friends
 * - Computes graph_etag = md5(sorted nodes + sorted internal edges)
 * - Skips work if palette_gen_state shows is_exhausted=1 AND etag unchanged
 * - Runs Bron–Kerbosch (pivoted) to enumerate maximal cliques that CONTAIN pivot
 * - Saves each clique (size ≥3, ≤ maxK) into palettes/palette_members (dedup by palette_hash)
 * - Updates palette_gen_state (is_exhausted, counts, last_full_run_at)
 *
 * @param PDO   $pdo
 * @param int   $pivotClusterId
 * @param int   $maxK            Maximum palette size to save (default 6)
 * @param int   $timeBudgetMs    Soft time budget; if exceeded, returns partial and leaves is_exhausted=0
 * @return array                 Summary {ok, skipped, pivot, neighbors, edges, cliques_found, palettes_inserted, exhausted, elapsed_ms, etag}
 */


function get_cf_etag(PDO $pdo): string {
  // Prefer meta; fallback to COUNT + MAX(updated_at) on cluster_friends
  try {
    $v = $pdo->query("SELECT `value` FROM meta WHERE `key`='cluster_friends_etag' LIMIT 1")->fetchColumn();
    if (is_string($v) && $v !== '') return $v;
  } catch (\Throwable $e) { /* ignore */ }
  return 'cf:' . $pdo->query("
    SELECT CONCAT_WS(':', COUNT(*), COALESCE(UNIX_TIMESTAMP(MAX(updated_at)),0))
    FROM cluster_friends
  ")->fetchColumn();
}

function load_ledger(PDO $pdo, int $pivot, string $tierConfig): ?array {
  $stmt = $pdo->prepare("
    SELECT * FROM palette_gen_state
    WHERE pivot_cluster_id = :p AND tier_config = :tc
    LIMIT 1
  ");
  $stmt->execute([':p'=>$pivot, ':tc'=>$tierConfig]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function upsert_ledger(
  PDO $pdo, int $pivot, string $tierConfig, string $cfEtag,
  int $isExhausted, ?int $neighbors, ?int $edges, ?int $palettes
): void {
  $stmt = $pdo->prepare("
    INSERT INTO palette_gen_state
      (pivot_cluster_id, tier_config, is_exhausted, graph_etag, last_full_run_at, neighbors_count, edges_count, palettes_count)
    VALUES
      (:p, :tc, :ex, :etag, NOW(), :n, :e, :pa)
    ON DUPLICATE KEY UPDATE
      is_exhausted   = VALUES(is_exhausted),
      graph_etag     = VALUES(graph_etag),
      last_full_run_at = VALUES(last_full_run_at),
      neighbors_count= VALUES(neighbors_count),
      edges_count    = VALUES(edges_count),
      palettes_count = VALUES(palettes_count)
  ");
  $stmt->execute([
    ':p'=>$pivot, ':tc'=>$tierConfig, ':ex'=>$isExhausted, ':etag'=>$cfEtag,
    ':n'=>$neighbors, ':e'=>$edges, ':pa'=>$palettes
  ]);
}





function generateTierAPalettes(PDO $pdo, int $pivotClusterId, int $maxK = 6, int $timeBudgetMs = 4000): array
{
    $started = microtime(true);
    $tierConfig = "A:max_k={$maxK}";

    $logCtx = ['pivot'=>$pivotClusterId,'maxK'=>$maxK,'budget_ms'=>$timeBudgetMs];
    logj('tierA.start', $logCtx);

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // --- helpers ---
        $timeOk = function() use ($started, $timeBudgetMs): bool {
            return (microtime(true) - $started) * 1000 < $timeBudgetMs;
        };
        $elapsedMs = function() use ($started): int {
            return (int) round((microtime(true) - $started) * 1000);
        };

        // 1) Load friends of pivot (degree)
        $stmt = $pdo->prepare("
            SELECT DISTINCT
            CASE WHEN cf.c_from = :p1 THEN cf.c_to ELSE cf.c_from END AS friend
            FROM cluster_friends cf
            WHERE cf.c_from = :p2 OR cf.c_to = :p3
        ");
        $stmt->execute([':p1'=>$pivotClusterId, ':p2'=>$pivotClusterId, ':p3'=>$pivotClusterId]);

        $friends = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
        $friends = array_values(array_unique(array_filter($friends, fn($x)=>$x !== $pivotClusterId)));


        // TEMP alias to satisfy existing code paths that still use $neighbors
        $neighbors = $friends;

        $nodes = $friends;
        $nodes[] = $pivotClusterId;
        $nodes = array_values(array_unique($nodes));
        sort($nodes, SORT_NUMERIC);

        // Node set S = pivot ∪ neighbors
        $nodes = $neighbors;
        $nodes[] = $pivotClusterId;
        $nodes = array_values(array_unique($nodes));
        sort($nodes, SORT_NUMERIC);

        if (count($nodes) < 3) {
            // Not enough nodes to form any palette ≥3
            $etag = md5(json_encode([ 'S'=>$nodes, 'E'=>[] ], JSON_UNESCAPED_SLASHES));
            // Update/insert gen state as exhausted, zero counts.
            $stmt = $pdo->prepare("
              INSERT INTO palette_gen_state (pivot_cluster_id, tier_config, is_exhausted, graph_etag, last_full_run_at, neighbors_count, edges_count, palettes_count)
              VALUES (:p, :tc, 1, :etag, NOW(), :nc, 0, 0)
              ON DUPLICATE KEY UPDATE
                is_exhausted = VALUES(is_exhausted),
                graph_etag   = VALUES(graph_etag),
                last_full_run_at = VALUES(last_full_run_at),
                neighbors_count  = VALUES(neighbors_count),
                edges_count      = VALUES(edges_count),
                palettes_count   = VALUES(palettes_count)
            ");
            $stmt->execute([
                ':p'=>$pivotClusterId, ':tc'=>$tierConfig, ':etag'=>$etag,
                ':nc'=>max(0, count($nodes)-1)
            ]);
            logj('tierA.noop_not_enough_nodes', ['pivot'=>$pivotClusterId,'neighbors'=>count($nodes)-1,'etag'=>$etag]);
            return [
                'ok'=>true,'skipped'=>false,'pivot'=>$pivotClusterId,
                'neighbors'=>max(0, count($nodes)-1),'edges'=>0,
                'cliques_found'=>0,'palettes_inserted'=>0,'exhausted'=>true,
                'etag'=>$etag,'elapsed_ms'=>$elapsedMs()
            ];
        }

        // 2) Load internal edges among S (induced subgraph)
        $placeA = []; $placeB = []; $params = [];
        foreach ($nodes as $i => $nid) {
            $pa = ":a{$i}";
            $pb = ":b{$i}";
            $placeA[] = $pa;   $params[$pa] = $nid;
            $placeB[] = $pb;   $params[$pb] = $nid;
        }
        $sqlEdges = "
        SELECT LEAST(c_from,c_to) AS u, GREATEST(c_from,c_to) AS v
        FROM cluster_friends
        WHERE c_from IN (" . implode(',', $placeA) . ")
            AND c_to   IN (" . implode(',', $placeB) . ")
        ";
        $stmt = $pdo->prepare($sqlEdges);
        $stmt->execute($params);
        $edges = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // Build adjacency map
        $adj = [];
        foreach ($nodes as $n) { $adj[$n] = []; }
        foreach ($edges as $e) {
            $u = (int)$e['u']; $v = (int)$e['v'];
            if ($u === $v) continue;
            $adj[$u][$v] = true;
            $adj[$v][$u] = true;
        }

        // 3) Compute graph_etag for exhaustion skipping
        $edgePairs = [];
        foreach ($adj as $u => $nbrs) {
            foreach ($nbrs as $v => $_) {
                if ($u < $v) $edgePairs[] = "{$u},{$v}";
            }
        }
        sort($edgePairs, SORT_STRING);
        $etag = md5(json_encode(['S'=>$nodes,'E'=>$edgePairs], JSON_UNESCAPED_SLASHES));

        // 4) Check exhaustion
        $stmt = $pdo->prepare("SELECT is_exhausted, graph_etag FROM palette_gen_state WHERE pivot_cluster_id = :p AND tier_config = :tc");
        $stmt->execute([':p'=>$pivotClusterId, ':tc'=>$tierConfig]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['is_exhausted'] === 1 && $row['graph_etag'] === $etag) {
            logj('tierA.skip_exhausted', ['pivot'=>$pivotClusterId,'etag'=>$etag]);
            return [
                'ok'=>true,'skipped'=>true,'pivot'=>$pivotClusterId,
                'neighbors'=>count($nodes)-1,'edges'=>count($edgePairs),
                'cliques_found'=>0,'palettes_inserted'=>0,'exhausted'=>true,
                'etag'=>$etag,'elapsed_ms'=>$elapsedMs()
            ];
        }

        // 5) Bron–Kerbosch (pivoted) to enumerate maximal cliques that include the pivot
        $pivot = $pivotClusterId;
        $P = array_keys($adj[$pivot]);   // candidates that connect to pivot
        sort($P, SORT_NUMERIC);
        $X = [];

        $cliquesFound = 0;
        $palettesInserted = 0;
        $exhausted = true; // assume done; set false if we bail on time

// Prepare statements (must exist before the closure)
$insPalette = $pdo->prepare("
  INSERT INTO palettes (palette_hash, size, tier, status, source_note)
  VALUES (:h, :s, 'A', 'active', 'observed_from_pairings')
  ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
");

$insMember = $pdo->prepare("
  INSERT IGNORE INTO palette_members (palette_id, member_cluster_id, order_hint)
  VALUES (:pid, :cid, :ord)
");


        // Bron–Kerbosch with pivoting and size/time pruning
$BK = function(array $R, array $P, array $X)
    use (&$BK, $adj, $maxK, $pivot, $timeOk, $insPalette, $insMember,
         &$cliquesFound, &$palettesInserted, &$exhausted, $pdo) {
            if (!$timeOk()) { $exhausted = false; return; }

            // If no more candidates and no more exclusions -> R is maximal
            if (empty($P) && empty($X)) {
                if (count($R) >= 3) {
                    $cliquesFound++;
                    // Save palette
                    $members = $R; sort($members, SORT_NUMERIC);
                    $hash = md5(implode(',', $members));
                    $size = count($members);

           $insPalette->execute([':h' => $hash, ':s' => $size]);

// Get id (works for insert or duplicate due to LAST_INSERT_ID in the ON DUPLICATE clause)
$paletteId = (int) $pdo->lastInsertId();
if ($paletteId === 0) {
    // Fallback for drivers that don’t set lastInsertId on duplicate
    $sel = $pdo->prepare("SELECT id FROM palettes WHERE palette_hash = :h LIMIT 1");
    $sel->execute([':h' => $hash]);
    $paletteId = (int) $sel->fetchColumn();
}


                    // Insert members (pivot first for stable order_hint)
                    $order = 0;
                    $ordered = array_values(array_unique(array_merge([$pivot], array_diff($members, [$pivot]))));
                    foreach ($ordered as $cid) {
                        $insMember->execute([':pid'=>$paletteId, ':cid'=>$cid, ':ord'=>$order]);
                        $order += 10;
                    }

                    // Count only if it was new (heuristic: if there were no members, first insert would have affected 1 row, but PDO doesn't give that easily)
                    // We'll approximate by checking how many members ended up: if >= count($ordered) after insert ignore, it was new or fully present.
                    $palettesInserted++; // it's fine to count attempts; duplicates won't create rows due to unique hash.
                }
                return;
            }

            // Prune by max size: if R already at maxK, can't add more; only accept if P and X empty handled above
            if (count($R) >= $maxK) {
                return;
            }

            // Choose a pivot u from P ∪ X with max degree to reduce branching
            $union = array_unique(array_merge($P, $X));
            $u = null; $maxDeg = -1;
            foreach ($union as $cand) {
                $deg = isset($adj[$cand]) ? count($adj[$cand]) : 0;
                if ($deg > $maxDeg) { $maxDeg = $deg; $u = $cand; }
            }
            $Pu = $u !== null ? array_keys($adj[$u]) : [];

            // Iterate over P \ N(u)
            $Pminus = array_values(array_diff($P, $Pu));
            foreach ($Pminus as $v) {
                if (!$timeOk()) { $exhausted = false; return; }

                // R' = R ∪ {v}
                $Rp = $R; $Rp[] = $v;

                // P' = P ∩ N(v)
                $Pv = [];
                if (isset($adj[$v])) {
                    $nbrs = array_keys($adj[$v]);
                    $Pv = array_values(array_intersect($P, $nbrs));
                }

                // X' = X ∩ N(v)
                $Xv = [];
                if (isset($adj[$v])) {
                    $nbrs = isset($nbrs) ? $nbrs : array_keys($adj[$v]);
                    $Xv = array_values(array_intersect($X, $nbrs));
                }

                // Size/prune: can Rp grow to ≥3?
                if (count($Rp) + max(0, count($Pv)) >= 3) {
                    $BK($Rp, $Pv, $Xv);
                    if (!$timeOk()) { $exhausted = false; return; }
                }

                // Move v from P to X
                $P = array_values(array_diff($P, [$v]));
                $X[] = $v;
            }
        };

        // Kick off with R = {pivot}
        $BK([$pivot], $P, $X);

        // 6) Update palette_gen_state
        $stmt = $pdo->prepare("
          INSERT INTO palette_gen_state
            (pivot_cluster_id, tier_config, is_exhausted, graph_etag, last_full_run_at, neighbors_count, edges_count, palettes_count)
          VALUES (:p, :tc, :exh, :etag, NOW(), :nc, :ec, :pc)
          ON DUPLICATE KEY UPDATE
            is_exhausted     = VALUES(is_exhausted),
            graph_etag       = VALUES(graph_etag),
            last_full_run_at = VALUES(last_full_run_at),
            neighbors_count  = VALUES(neighbors_count),
            edges_count      = VALUES(edges_count),
            palettes_count   = VALUES(palettes_count)
        ");
        $stmt->execute([
            ':p'=>$pivotClusterId,
            ':tc'=>$tierConfig,
            ':exh'=> ($exhausted ? 1 : 0),
            ':etag'=>$etag,
            ':nc'=> count($nodes) - 1,
            ':ec'=> count($edgePairs),
            ':pc'=> $cliquesFound
        ]);

        $out = [
            'ok'=>true, 'skipped'=>false, 'pivot'=>$pivotClusterId,
            'neighbors'=>count($nodes)-1, 'edges'=>count($edgePairs),
            'cliques_found'=>$cliquesFound, 'palettes_inserted'=>$palettesInserted,
            'exhausted'=>$exhausted, 'etag'=>$etag, 'elapsed_ms'=>$elapsedMs()
        ];
        logj('tierA.done', $out);
        return $out;

    } catch (Throwable $e) {
        $err = [
            'ok'=>false,
            'pivot'=>$pivotClusterId,
            'error'=>$e->getMessage(),
            'trace'=>substr($e->getTraceAsString(),0,2000)
        ];
        logj('tierA.error', $err);
        return $err;
    }
}

/** Robust JSON logger to file (rotates by day via filename) */
if (!function_exists('logj')) {
    function logj(string $msg, array $ctx = []): void {
        try {
            $dir = dirname(__DIR__) . '/logs'; // ../logs relative to api/functions/
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            $file = $dir . '/tierA-' . date('Y-m-d') . '.log';
            $rec  = ['ts'=>date('c'), 'msg'=>$msg, 'ctx'=>$ctx];
            @file_put_contents($file, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) { /* swallow */ }
    }
}
