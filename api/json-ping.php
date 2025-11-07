<?php
// first byte must be "<" (no BOM), and do NOT put declare(strict_types) here.
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
  'ok' => true,
  'php_version' => PHP_VERSION,
  'sapi' => php_sapi_name(),
  'time' => date('c'),
]);
