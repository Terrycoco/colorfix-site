<?php
// /api/merge-color.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

require_once  'db.php'; // must set $pdo (ERRMODE_EXCEPTION)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const LOGF = __DIR__ . '/logs/merge-color.log';
function logj($lvl, $msg, $ctx=[]) {
  @error_log(json_encode(['ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES).PHP_EOL, 3, LOGF);
}
function read_json() {
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function norm_hex($h) {
  $h = strtoupper(trim((string)$h));
  return preg_match('/^[0-9A-F]{6}$/', $h) ? $h : null;
}
function one($pdo, $sql, $args=[]) { $st=$pdo->prepare($sql); $st->execute($args); return $st->fetchColumn(); }

// ---- input ----
$in = array_merge($_GET, read_json());
$old_hex   = isset($in['old_hex'])   ? norm_hex($in['old_hex'])   : null; // duplicate you want to retire
$new_hex   = isset($in['new_hex'])   ? norm_hex($in['new_hex'])   : null; // canonical
$old_id    = isset($in['old_id'])    ? (int)$in['old_id'] : null;
$new_id    = isset($in['new_id'])    ? (int)$in['new_id'] : null;
$brand     = isset($in['brand'])     ? trim((string)$in['brand']) : null; // optional hint
$reason    = isset($in['reason'])    ? trim((string)$in['reason']) : '';
$dry_run   = isset($in['dry_run'])   ? (int)$in['dry_run'] === 1 : false;
$soft_arch = isset($in['soft_archive']) ? (int)$in['soft_archive'] === 1 : true; // default soft

if (!$old_hex && !$old_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Provide old_hex or old_id']); exit; }
if (!$new_hex && !$new_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Provide new_hex or new_id']); exit; }
if ($old_hex && $new_hex && $old_hex === $new_hex) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'old_hex and new_hex are the same']); exit; }
if ($old_id && $new_id && $old_id === $new_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'old_id and new_id are the same']); exit; }

// Resolve missing id/hex ends where possible
if ($old_hex && !$old_id) $old_id = one($pdo, "SELECT id FROM colors WHERE UPPER(hex6)=?", [$old_hex]) ?: null;
if ($new_hex && !$new_id) $new_id = one($pdo, "SELECT id FROM colors WHERE UPPER(hex6)=?", [$new_hex]) ?: null;
if ($old_id && !$old_hex) $old_hex = strtoupper((string)one($pdo, "SELECT hex6 FROM colors WHERE id=?", [$old_id]));
if ($new_id && !$new_hex) $new_hex = strtoupper((string)one($pdo, "SELECT hex6 FROM colors WHERE id=?", [$new_id]));

if (!$old_hex || !$new_hex) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Could not resolve both old_hex and new_hex']); exit; }

// Sanity: ensure both exist (at least as hex in colors/cluster_hex)
$old_exists = one($pdo, "SELECT 1 FROM colors WHERE UPPER(hex6)=? LIMIT 1", [$old_hex])
           ?: one($pdo, "SELECT 1 FROM cluster_hex WHERE hex6=? LIMIT 1", [$old_hex]);
$new_exists = one($pdo, "SELECT 1 FROM colors WHERE UPPER(hex6)=? LIMIT 1", [$new_hex])
           ?: one($pdo, "SELECT 1 FROM cluster_hex WHERE hex6=? LIMIT 1", [$new_hex]);
if (!$old_exists) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'old_hex not found']); exit; }
if (!$new_exists) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'new_hex not found']); exit; }

// Create alias tables if missing
$pdo->exec("
CREATE TABLE IF NOT EXISTS hex_alias (
  old_hex CHAR(6) PRIMARY KEY,
  new_hex CHAR(6) NOT NULL,
  brand   VARCHAR(64) NULL,
  reason  VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS color_alias (
  old_id INT PRIMARY KEY,
  new_id INT NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// PREVIEW COUNTS
$preview = [
  'pairs_touching_old' => (int)one($pdo, "SELECT COUNT(*) FROM color_friends WHERE hex1=? OR hex2=?", [$old_hex,$old_hex]),
  'cc_rows_old'        => $old_id ? (int)one($pdo, "SELECT COUNT(*) FROM color_category WHERE color_id=?", [$old_id]) : 0,
  'cluster_mship_old'  => (int)one($pdo, "SELECT COUNT(*) FROM cluster_hex WHERE hex6=?", [$old_hex]),
];

if ($dry_run) {
  echo json_encode(['ok'=>true,'dry_run'=>true,'old_hex'=>$old_hex,'new_hex'=>$new_hex,'old_id'=>$old_id,'new_id'=>$new_id,'preview'=>$preview], JSON_UNESCAPED_SLASHES);
  exit;
}

// ---- do it ----
$pdo->beginTransaction();
try {
  // 1) Record aliases (so future ingest/capture resolves automatically)
  $st = $pdo->prepare("INSERT INTO hex_alias (old_hex,new_hex,brand,reason) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE new_hex=VALUES(new_hex), brand=VALUES(brand), reason=VALUES(reason), updated_at=NOW()");
  $st->execute([$old_hex,$new_hex,$brand,$reason]);
  if ($old_id && $new_id) {
    $st = $pdo->prepare("INSERT INTO color_alias (old_id,new_id,reason) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE new_id=VALUES(new_id), reason=VALUES(reason), updated_at=NOW()");
    $st->execute([$old_id,$new_id,$reason]);
  }

  // 2) Move pairs → canonical hex (preserve created_at; force re-cluster by nulling c1/c2)
  $moved = (int)$pdo->exec("
    INSERT IGNORE INTO color_friends (hex1,hex2,c1,c2,created_at)
    SELECT
      LEAST( CASE WHEN f.hex1=".$pdo->quote($old_hex)." THEN ".$pdo->quote($new_hex)." ELSE f.hex1 END,
             CASE WHEN f.hex2=".$pdo->quote($old_hex)." THEN ".$pdo->quote($new_hex)." ELSE f.hex2 END ),
      GREATEST( CASE WHEN f.hex1=".$pdo->quote($old_hex)." THEN ".$pdo->quote($new_hex)." ELSE f.hex1 END,
                CASE WHEN f.hex2=".$pdo->quote($old_hex)." THEN ".$pdo->quote($new_hex)." ELSE f.hex2 END ),
      NULL, NULL, f.created_at
    FROM color_friends f
    WHERE f.hex1=".$pdo->quote($old_hex)." OR f.hex2=".$pdo->quote($old_hex)."
  ");
  $deleted_old_pairs = (int)$pdo->exec("DELETE FROM color_friends WHERE hex1=".$pdo->quote($old_hex)." OR hex2=".$pdo->quote($old_hex));

  // 3) Move neutral categories (if ids known)
  $cc_copied = 0; $cc_deleted = 0;
  if ($old_id && $new_id) {
    $cc_copied = (int)$pdo->exec("
      INSERT IGNORE INTO color_category (color_id, category_id)
      SELECT ".$pdo->quote($new_id).", category_id
      FROM color_category WHERE color_id=".$pdo->quote($old_id)
    );
    $cc_deleted = (int)$pdo->exec("DELETE FROM color_category WHERE color_id=".$pdo->quote($old_id));
  }

  // 4) Remove old cluster membership (canonical will supply membership)
  $mship_deleted = (int)$pdo->exec("DELETE FROM cluster_hex WHERE hex6=".$pdo->quote($old_hex));

  // 5) Retire duplicate color row (optional soft archive)
  $archived = 0;
  if ($old_id) {
    if ($soft_arch) {
      // Try to soft-mark without breaking FKs; keep row but make it obvious
      $archived = (int)$pdo->exec("
        UPDATE colors
        SET name = CONCAT(name, ' (duplicate)'),
            updated_at = NOW()
        WHERE id = ".$pdo->quote($old_id)."
      ");
      // Do NOT null hex6 if NOT NULL. Leave it; alias table handles resolution.
    } else {
      $archived = (int)$pdo->exec("DELETE FROM colors WHERE id=".$pdo->quote($old_id));
    }
  }

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'old_hex' => $old_hex, 'new_hex' => $new_hex,
    'old_id'  => $old_id,  'new_id'  => $new_id,
    'moved_pairs' => $moved,
    'deleted_old_pairs' => $deleted_old_pairs,
    'categories_copied' => $cc_copied,
    'categories_deleted_from_old' => $cc_deleted,
    'cluster_membership_deleted' => $mship_deleted,
    'archived_rows' => $archived,
    'note' => 'Run cluster-edges-refresh to rebuild edges for moved pairs.'
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  logj('error','merge failed',['err'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
