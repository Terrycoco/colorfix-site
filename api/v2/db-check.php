<?php
header('Content-Type: application/json; charset=UTF-8');
ob_start();
$ok = true; $err = null;

try {
  require_once __DIR__ . '/../db.php'; // should define $pdo (PDO)
  $ok = isset($pdo) && ($pdo instanceof PDO);
} catch (Throwable $e) {
  $ok = false; $err = $e->getMessage();
}
while (ob_get_level()) ob_end_clean();
echo json_encode(['ok'=>$ok, 'where'=>'/api/v2/db-check.php', 'err'=>$err]);
