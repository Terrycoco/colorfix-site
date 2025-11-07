<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$LOG = __DIR__ . '/assign.log';
file_put_contents($LOG, "[" . date('Y-m-d H:i:s') . "] assign-categories.php starting\n", FILE_APPEND);

file_put_contents($LOG, "[" . date('Y-m-d H:i:s') . "] 🏃 running populate script separately\n", FILE_APPEND);
shell_exec('php ' . escapeshellarg(__DIR__ . '/populate-color-category.php'));
file_put_contents($LOG, "[" . date('Y-m-d H:i:s') . "] ✅ populate script done — including finalize\n", FILE_APPEND);

require_once __DIR__ . '/finalize-category-summary.php';

/* --------- REFRESH clusters.neutral_cats & clusters.hue_cats (mode of members) --------- */
require_once 'db.php';

$sql = <<<SQL
UPDATE clusters cl
LEFT JOIN (
  /* mode of NEUTRAL categories per cluster (ties -> lexicographically smallest) */
  SELECT a.cluster_id, MIN(a.cat) AS neutral_cats
  FROM (
    SELECT ch.cluster_id, c.neutral_cats AS cat, COUNT(*) AS cnt
    FROM cluster_hex ch
    JOIN colors c ON c.hex6 = ch.hex6
    WHERE c.neutral_cats IS NOT NULL AND c.neutral_cats <> ''
    GROUP BY ch.cluster_id, c.neutral_cats
  ) a
  JOIN (
    SELECT cluster_id, MAX(cnt) AS maxcnt
    FROM (
      SELECT ch.cluster_id, c.neutral_cats AS cat, COUNT(*) AS cnt
      FROM cluster_hex ch
      JOIN colors c ON c.hex6 = ch.hex6
      WHERE c.neutral_cats IS NOT NULL AND c.neutral_cats <> ''
      GROUP BY ch.cluster_id, c.neutral_cats
    ) b
    GROUP BY cluster_id
  ) m ON m.cluster_id = a.cluster_id AND m.maxcnt = a.cnt
  GROUP BY a.cluster_id
) nm ON nm.cluster_id = cl.id

LEFT JOIN (
  /* mode of HUE categories per cluster (ties -> lexicographically smallest) */
  SELECT a.cluster_id, MIN(a.cat) AS hue_cats
  FROM (
    SELECT ch.cluster_id, c.hue_cats AS cat, COUNT(*) AS cnt
    FROM cluster_hex ch
    JOIN colors c ON c.hex6 = ch.hex6
    WHERE c.hue_cats IS NOT NULL AND c.hue_cats <> ''
    GROUP BY ch.cluster_id, c.hue_cats
  ) a
  JOIN (
    SELECT cluster_id, MAX(cnt) AS maxcnt
    FROM (
      SELECT ch.cluster_id, c.hue_cats AS cat, COUNT(*) AS cnt
      FROM cluster_hex ch
      JOIN colors c ON c.hex6 = ch.hex6
      WHERE c.hue_cats IS NOT NULL AND c.hue_cats <> ''
      GROUP BY ch.cluster_id, c.hue_cats
    ) b
    GROUP BY cluster_id
  ) m ON m.cluster_id = a.cluster_id AND m.maxcnt = a.cnt
  GROUP BY a.cluster_id
) hm ON hm.cluster_id = cl.id

SET
  cl.neutral_cats = COALESCE(nm.neutral_cats, ''),
  cl.hue_cats     = COALESCE(hm.hue_cats, '');
SQL;

$affected = 0;
$affectedLC = 0;

try {
  // Refresh hue/neutral on clusters
  $affected = $pdo->exec($sql);
  file_put_contents($LOG,
    "[" . date('Y-m-d H:i:s') . "] 🔄 clusters hue/neutral refreshed (rows affected: {$affected})\n",
    FILE_APPEND
  );

  /* --------- NEW: refresh clusters.lightness_cats & clusters.chroma_cats from category_definitions --------- */
  $sqlLC = <<<SQL
UPDATE clusters c
LEFT JOIN category_definitions dL
  ON dL.type = 'lightness'
 AND c.l_r BETWEEN dL.light_min AND dL.light_max
LEFT JOIN category_definitions dC
  ON dC.type = 'chroma'
 AND c.c_r BETWEEN dC.chroma_min AND dC.chroma_max
SET c.lightness_cats = COALESCE(dL.name, ''),
    c.chroma_cats    = COALESCE(dC.name, '');
SQL;

  $affectedLC = $pdo->exec($sqlLC);
  file_put_contents($LOG,
    "[" . date('Y-m-d H:i:s') . "] 🔄 clusters lightness/chroma refreshed (rows affected: {$affectedLC})\n",
    FILE_APPEND
  );

} catch (Throwable $e) {
  file_put_contents($LOG,
    "[" . date('Y-m-d H:i:s') . "] ❌ refresh failed: " . $e->getMessage() . "\n",
    FILE_APPEND
  );
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>'error', 'detail'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  exit;
}

/* Output success */
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'status'              => 'success',
  'clusters_updated_hn' => (int)$affected,
  'clusters_updated_lc' => (int)$affectedLC
], JSON_UNESCAPED_SLASHES);
