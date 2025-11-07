<?php
declare(strict_types=1);

// TEMP: show real errors while we fix 500s (remove later)
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use PDO;

function respond(int $code, array $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  /** @var PDO $pdo */
  $pdo = $GLOBALS['pdo'] ?? null;
  if (!$pdo instanceof PDO) throw new RuntimeException('DB not initialized');

  // Inputs
  $q         = trim((string)($_GET['q'] ?? ''));         // partial: asset_id prefix, style LIKE, tag LIKE
  $tagExact  = trim((string)($_GET['tag'] ?? ''));       // exact tag
  $tagsCsv   = trim((string)($_GET['tags'] ?? ''));      // comma-separated exact tags (OR)
  $tagsLike  = trim((string)($_GET['tags_like'] ?? '')); // comma-separated partial tags (OR)
  $verdict   = trim((string)($_GET['verdict'] ?? ''));
  $status    = trim((string)($_GET['status'] ?? ''));
  $limit     = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
  $offset    = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

  $w = [];
  $p = [];
  $join = "LEFT JOIN photos_tags t ON t.photo_id = p.id";

  if ($q !== '') {
    $qLower = strtolower($q);
    $w[] = "(p.asset_id LIKE :qAsset OR LOWER(p.style_primary) LIKE :qStyle OR LOWER(t.tag) LIKE :qTag)";
    $p[':qAsset'] = $q . '%';
    $p[':qStyle'] = '%' . $qLower . '%';
    $p[':qTag']   = '%' . $qLower . '%';
  }

  if ($tagExact !== '') {
    $w[] = "LOWER(t.tag) = :tagExact";
    $p[':tagExact'] = strtolower($tagExact);
  }

  if ($tagsCsv !== '') {
    $tags = array_values(array_filter(array_map('trim', explode(',', $tagsCsv))));
    if ($tags) {
      $ors = [];
      foreach ($tags as $i => $tg) {
        $k = ":tagE$i";
        $ors[] = "LOWER(t.tag) = $k";
        $p[$k] = strtolower($tg);
      }
      $w[] = '(' . implode(' OR ', $ors) . ')';
    }
  }

  if ($tagsLike !== '') {
    $tags = array_values(array_filter(array_map('trim', explode(',', $tagsLike))));
    if ($tags) {
      $ors = [];
      foreach ($tags as $i => $tg) {
        $k = ":tagL$i";
        $ors[] = "LOWER(t.tag) LIKE $k";
        $p[$k] = '%' . strtolower($tg) . '%';
      }
      $w[] = '(' . implode(' OR ', $ors) . ')';
    }
  }

  if ($verdict !== '') { $w[] = "p.verdict = :verdict"; $p[':verdict'] = $verdict; }
  if ($status  !== '') { $w[] = "p.status  = :status";  $p[':status']  = $status; }

  $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

  // 1) COUNT DISTINCT IDs
  $sqlCount = "SELECT COUNT(DISTINCT p.id) FROM photos p $join $where";
  $stmt = $pdo->prepare($sqlCount);
  foreach ($p as $k => $v) $stmt->bindValue($k, $v);
  $stmt->execute();
  $total = (int)$stmt->fetchColumn();

  if ($total === 0) respond(200, ['total'=>0, 'items'=>[], 'limit'=>$limit, 'offset'=>$offset]);

  // 2) Page of DISTINCT IDs (ordered)
  $sqlIds = "SELECT DISTINCT p.id FROM photos p $join $where ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
  $stmt = $pdo->prepare($sqlIds);
  foreach ($p as $k => $v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  if (!$ids) respond(200, ['total'=>$total, 'items'=>[], 'limit'=>$limit, 'offset'=>$offset]);

  // 3) Hydrate rows (no created_at/updated_at to avoid unknown-column issues)
  $in = implode(',', array_fill(0, count($ids), '?'));
  $sqlRows = "
    SELECT
      p.id, p.asset_id, p.width, p.height,
      p.style_primary, p.verdict, p.status, p.lighting, p.rights_status,
      COALESCE(v.variant_count,0) AS variants
    FROM photos p
    LEFT JOIN (
      SELECT photo_id, COUNT(*) AS variant_count
      FROM photos_variants
      GROUP BY photo_id
    ) v ON v.photo_id = p.id
    WHERE p.id IN ($in)
    ORDER BY p.id DESC
  ";
  $stmt = $pdo->prepare($sqlRows);
  foreach ($ids as $i => $id) $stmt->bindValue($i+1, (int)$id, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  respond(200, ['total'=>$total, 'items'=>$rows, 'limit'=>$limit, 'offset'=>$offset]);

} catch (Throwable $e) {
  respond(500, ['error' => $e->getMessage()]);
}
