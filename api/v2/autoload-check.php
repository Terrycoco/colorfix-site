<?php
header('Content-Type: application/json; charset=UTF-8');
ob_start();
$ok = true; $err = null;

try {
  require_once __DIR__ . '/../autoload.php';
} catch (Throwable $e) {
  $ok = false; $err = $e->getMessage();
}
while (ob_get_level()) ob_end_clean();
echo json_encode(['ok'=>$ok, 'where'=>'/api/v2/autoload-check.php', 'err'=>$err]);
