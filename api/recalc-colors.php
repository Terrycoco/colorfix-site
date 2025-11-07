<?php
require_once 'db.php';
require_once __DIR__ . '/functions/RGBtoHCL.php';
require_once __DIR__ . '/functions/logMessage.php';
require_once __DIR__ . '/functions/getContrastColor.php';

function execSql(PDO $pdo, string $sql, string $label = '') {
  // Optional logging:
  // file_put_contents(__DIR__ . '/logs/recalc-sql.log', "---- {$label} ----\n{$sql}\n\n", FILE_APPEND);
  return $pdo->exec($sql);
}

ini_set('display_errors','1');
error_reporting(E_ALL);

function norm_hex(string $h): string {
  $h = strtoupper(trim($h));
  if (!preg_match('/^[0-9A-F]{6}$/',$h)) throw new RuntimeException("Bad hex: $h");
  return $h;
}

$mode         = isset($_GET['mode']) ? strtolower((string)$_GET['mode']) : '';
$singleId     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$idsParam     = isset($_GET['ids']) ? trim((string)$_GET['ids']) : '';
$hexParam     = isset($_GET['hexes']) ? trim((string)$_GET['hexes']) : '';
$chunk        = isset($_GET['chunk']) ? max(100, (int)$_GET['chunk']) : 2000;
$upsertEdges  = !empty($_GET['upsert_edges']);

$ids = [];
if ($idsParam !== '') {
  foreach (explode(',', $idsParam) as $v) {
    $v = trim($v);
    if ($v === '') continue;
    $ids[] = (int)$v;
  }
}
$hexes = [];
if ($hexParam !== '') {
  foreach (explode(',', $hexParam) as $v) {
    $v = trim($v);
    if ($v === '') continue;
    $hexes[] = norm_hex($v);
  }
}

try {
  // Build target set
  execSql($pdo, "DROP TEMPORARY TABLE IF EXISTS tmp_targets", "drop tmp_targets");
  execSql($pdo, "CREATE TEMPORARY TABLE tmp_targets (hex6 CHAR(6) PRIMARY KEY) ENGINE=Memory", "create tmp_targets");

  if ($singleId !== null) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO tmp_targets (hex6) SELECT UPPER(hex6) FROM colors WHERE id=? AND hex6 IS NOT NULL");
    $stmt->execute([$singleId]);
  } elseif (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("INSERT IGNORE INTO tmp_targets (hex6) SELECT UPPER(hex6) FROM colors WHERE id IN ($ph) AND hex6 IS NOT NULL");
    $stmt->execute($ids);
  } elseif (!empty($hexes)) {
    $ins = $pdo->prepare("INSERT IGNORE INTO tmp_targets (hex6) VALUES (?)");
    foreach (array_unique($hexes) as $h) { $ins->execute([$h]); }
  } elseif ($mode === 'all') {
    execSql($pdo, "
      INSERT IGNORE INTO tmp_targets (hex6)
      SELECT DISTINCT UPPER(hex6)
      FROM colors
      WHERE hex6 IS NOT NULL
    ", "seed tmp_targets all");
  } else {
    execSql($pdo, "
      INSERT IGNORE INTO tmp_targets (hex6)
      SELECT DISTINCT UPPER(c.hex6)
      FROM colors c
      LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
      WHERE c.hex6 IS NOT NULL
        AND c.r IS NOT NULL AND c.g IS NOT NULL AND c.b IS NOT NULL
        AND (
          c.hcl_h IS NULL OR c.hcl_c IS NULL OR c.hcl_l IS NULL
          OR c.lab_l IS NULL OR c.lab_a IS NULL OR c.lab_b IS NULL
          OR c.hsl_h IS NULL OR c.hsl_s IS NULL OR c.hsl_l IS NULL
          OR c.contrast_text_color IS NULL
          OR ch.hex6 IS NULL
        )
    ", "seed tmp_targets missing");
  }

  // Count targets
  $totalTargets = (int)$pdo->query("SELECT COUNT(*) FROM tmp_targets")->fetchColumn();
  if ($totalTargets === 0) {
    $msg = "No colors selected for recalculation.";
    logMessage($msg);
    echo $msg . PHP_EOL;
    exit;
  }

  logMessage("Recalc start: targets=$totalTargets (mode=" . ($mode ?: 'missing') . ")");

  // Build page query with sprintf (no :lim)
  $selectPageSql = sprintf(
    "SELECT c.id, c.hex6, c.r, c.g, c.b
     FROM colors c
     JOIN tmp_targets t ON t.hex6 = c.hex6
     WHERE c.id > :after
     ORDER BY c.id
     LIMIT %d",
    (int)$chunk
  );
  $selectPage = $pdo->prepare($selectPageSql);

  $update = $pdo->prepare("
    UPDATE colors SET
      lab_l = :lab_l, lab_a = :lab_a, lab_b = :lab_b,
      hcl_l = :hcl_l, hcl_c = :hcl_c, hcl_h = :hcl_h,
      hsl_h = :hsl_h, hsl_s = :hsl_s, hsl_l = :hsl_l,
      contrast_text_color = :contrast
    WHERE id = :id
  ");

  $processed = 0;
  $lastId = 0;

  while (true) {
    $selectPage->bindValue(':after', (int)$lastId, PDO::PARAM_INT);
    $selectPage->execute();
    $rows = $selectPage->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;

    foreach ($rows as $row) {
      $lastId = (int)$row['id'];
      if ($row['r'] === null || $row['g'] === null || $row['b'] === null) continue;

      try {
        $lab = RGBtoHCL((int)$row['r'], (int)$row['g'], (int)$row['b']);
        $contrast = getContrastColor($lab['hcl_l']);

        $update->execute([
          ':lab_l' => $lab['lab_l'],
          ':lab_a' => $lab['lab_a'],
          ':lab_b' => $lab['lab_b'],
          ':hcl_l' => $lab['hcl_l'],
          ':hcl_c' => $lab['hcl_c'],
          ':hcl_h' => $lab['hcl_h'],
          ':hsl_h' => $lab['hsl_h'],
          ':hsl_s' => $lab['hsl_s'],
          ':hsl_l' => $lab['hsl_l'],
          ':contrast' => $contrast,
          ':id' => $row['id'],
        ]);

        $processed++;
      } catch (Throwable $e) {
        logMessage("Row id {$row['id']} failed: ".$e->getMessage());
      }
    }
  }

  logMessage("Recalc math complete: processed=$processed");

  // Cluster pipeline
  execSql($pdo, "
    INSERT IGNORE INTO clusters (h_r, c_r, l_r)
    SELECT DISTINCT
      ((FLOOR(c.hcl_h + 0.5) + 360) % 360) AS h_r,
      FLOOR(c.hcl_c + 0.5)                 AS c_r,
      FLOOR(c.hcl_l + 0.5)                 AS l_r
    FROM colors c
    JOIN tmp_targets t ON t.hex6 = c.hex6
  ", "ensure clusters");

  execSql($pdo, "
    INSERT INTO cluster_hex (hex6, cluster_id)
    SELECT c.hex6, cl.id
    FROM colors c
    JOIN tmp_targets t ON t.hex6 = c.hex6
    JOIN clusters cl
      ON cl.h_r = ((FLOOR(c.hcl_h + 0.5) + 360) % 360)
     AND cl.c_r = FLOOR(c.hcl_c + 0.5)
     AND cl.l_r = FLOOR(c.hcl_l + 0.5)
    ON DUPLICATE KEY UPDATE cluster_id = VALUES(cluster_id)
  ", "ensure cluster_hex");

  $hasClusterId = (bool)$pdo->query("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'colors'
      AND COLUMN_NAME  = 'cluster_id'
    LIMIT 1
  ")->fetchColumn();

  if ($hasClusterId) {
    $pdo->exec("
      UPDATE colors c
      JOIN cluster_hex ch ON ch.hex6 = c.hex6
      JOIN tmp_targets t  ON t.hex6  = c.hex6
      SET c.cluster_id = ch.cluster_id
    ");
  }

  if ($upsertEdges) {
    execSql($pdo, "
      INSERT IGNORE INTO cluster_friends (c_from, c_to)
      SELECT LEAST(ch1.cluster_id, ch2.cluster_id),
             GREATEST(ch1.cluster_id, ch2.cluster_id)
      FROM color_friends f
      JOIN tmp_targets t ON t.hex6 IN (UPPER(f.hex1), UPPER(f.hex2))
      JOIN cluster_hex ch1 ON ch1.hex6 = UPPER(f.hex1)
      JOIN cluster_hex ch2 ON ch2.hex6 = UPPER(f.hex2)
      WHERE ch1.cluster_id <> ch2.cluster_id
    ", "upsert cluster_friends");
  }

  $msg = "✅ Recalc done. processed=$processed, targets=$totalTargets";
  logMessage($msg);
  echo $msg . PHP_EOL;

} catch (Throwable $e) {
  logMessage("Fatal error: ".$e->getMessage());
  http_response_code(500);
  echo "Error: check log/colors-update.log" . PHP_EOL;
}
