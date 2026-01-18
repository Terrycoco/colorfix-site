<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$sql = <<<SQL
  SELECT
    cta_type_id,
    action_key,
    label,
    description,
    is_active,
    created_at,
    updated_at
  FROM cta_types
  ORDER BY cta_type_id ASC
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

respond(['ok' => true, 'items' => $rows]);
