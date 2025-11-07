<?php
declare(strict_types=1);

/**
 * POST /api/get-palette-details.php
 * Body: { "palette_id": 123 }
 *
 * Returns one representative swatch per cluster in the palette,
 * ordered by highest chroma first (hcl_c DESC, then hcl_l DESC, then id ASC).
 *
 * Tables/Views expected:
 *   - palette_members(palette_id, member_cluster_id, ...)
 *   - swatch_view(id, brand, name, hex6, r,g,b, hcl_h,hcl_c,hcl_l, chip_num, is_stain, cluster_id)
 */

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/db.php'; // must provide $pdo (PDO with ERRMODE_EXCEPTION)

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit();
}

try {
  $body = json_decode(file_get_contents('php://input') ?: 'null', true);
  if (!is_array($body)) $body = [];

  $palette_id = (int)($body['palette_id'] ?? 0);
  if ($palette_id <= 0) {
    respond(400, ['ok'=>false, 'error'=>'Missing or invalid palette_id']);
  }

  // 1) Get cluster_ids for this palette
  $stmt = $pdo->prepare("
    SELECT member_cluster_id AS cluster_id
      FROM palette_members
     WHERE palette_id = :pid
  ");
  $stmt->execute([':pid' => $palette_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

  if (!$rows) {
    respond(404, ['ok'=>false, 'error'=>'No members found for palette_id', 'palette_id'=>$palette_id]);
  }

  // Deduplicate, keep ints only
  $cluster_ids = [];
  foreach ($rows as $cid) {
    $cid = (int)$cid;
    if ($cid > 0) $cluster_ids[$cid] = true;
  }
  $cluster_ids = array_keys($cluster_ids);
  if (!$cluster_ids) {
    respond(404, ['ok'=>false, 'error'=>'No valid member_cluster_id values', 'palette_id'=>$palette_id]);
  }

  // 2) Pick one representative per cluster (prefer non-stain, else lowest id)
  $placeholders = implode(',', array_fill(0, count($cluster_ids), '?'));
  $repSql = "
    SELECT
      cluster_id,
      COALESCE(
        MIN(CASE WHEN is_stain = 0 THEN id END),
        MIN(id)
      ) AS rep_id
    FROM swatch_view
    WHERE cluster_id IN ($placeholders)
    GROUP BY cluster_id
  ";
  $repStmt = $pdo->prepare($repSql);
  $repStmt->execute($cluster_ids);
  $repMap = $repStmt->fetchAll(PDO::FETCH_KEY_PAIR); // cluster_id => rep_id

  if (!$repMap) {
    respond(404, ['ok'=>false, 'error'=>'No representative swatches found for these clusters', 'palette_id'=>$palette_id]);
  }

  $repIds = array_values(array_map('intval', array_values($repMap)));
  if (!$repIds) {
    respond(404, ['ok'=>false, 'error'=>'No representative ids resolved', 'palette_id'=>$palette_id]);
  }

  // 3) Return the representative rows ordered by highest chroma first
  $ph2 = implode(',', array_fill(0, count($repIds), '?'));
  $fields = "id, brand, name, hex6, r, g, b, hcl_h, hcl_c, hcl_l, chip_num, is_stain, cluster_id";
  $sql = "
    SELECT $fields
      FROM swatch_view
     WHERE id IN ($ph2)
     ORDER BY hcl_c DESC, hcl_l DESC, id ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($repIds);
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Optional: report any clusters we couldn’t return
  $returnedCids = array_flip(array_map(static fn($r)=>(int)$r['cluster_id'], $items));
  $missing = [];
  foreach ($cluster_ids as $cid) {
    if (!isset($returnedCids[(int)$cid])) $missing[] = (int)$cid;
  }

  $resp = [
    'ok' => true,
    'palette_id' => $palette_id,
    'count' => count($items),
    'items' => $items,
  ];
  if ($missing) $resp['missing_cluster_ids'] = $missing;

  respond(200, $resp);

} catch (Throwable $e) {
  respond(500, ['ok'=>false, 'error'=>'Server error', 'error_detail'=>$e->getMessage()]);
}
