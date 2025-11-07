<?php
declare(strict_types=1);
error_reporting(E_ALL);

header("Access-Control-Allow-Methods: DELETE, OPTIONS, POST");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit(); }

$logFile = __DIR__ . '/category-errors.log';
function logError($msg) {
  global $logFile;
  error_log(date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, 3, $logFile);
}

require_once 'db.php';

function out($ok, $error = '', $extra = []) {
  echo json_encode(array_merge(['success'=>$ok,'error'=>$error], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

// Get id from JSON body or query (?id=123) to be robust across hosts
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true) ?: [];
$id   = $body['id'] ?? ($_GET['id'] ?? null);
if (!is_numeric($id)) { logError("❌ Missing/invalid ID. Body=$raw QS=" . json_encode($_GET)); http_response_code(400); out(false, 'Missing or invalid ID'); }
$id = (int)$id;

try {
  // 1) Pre-check usage count to avoid FK exception and return helpful info
  $check = $pdo->prepare("SELECT COUNT(*) FROM color_category WHERE category_id = ?");
  $check->execute([$id]);
  $in_use = (int)$check->fetchColumn();

  if ($in_use > 0) {
    logError("ℹ️ Blocked delete for ID=$id — in use by $in_use rows in color_category");
    http_response_code(409); // Conflict
    out(false, 'in_use', ['count' => $in_use, 'message' => "Category is used by $in_use colors"]);
  }

  // 2) Proceed to delete
  $stmt = $pdo->prepare("DELETE FROM category_definitions WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);

  if ($stmt->rowCount() < 1) {
    logError("⚠️ No rows deleted for ID=$id");
    out(false, 'No rows deleted');
  }

  out(true, '');
} catch (PDOException $e) {
  // FK safety net (in case schema changes / race)
  if (($e->errorInfo[1] ?? null) === 1451) {
    logError("❌ FK 1451 on delete ID=$id: ".$e->getMessage());
    http_response_code(409);
    out(false, 'in_use');
  }
  logError("❌ Exception deleting ID=$id: ".$e->getMessage());
  http_response_code(500);
  out(false, 'Server error');
}
