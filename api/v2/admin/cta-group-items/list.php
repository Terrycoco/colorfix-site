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

$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($groupId <= 0) {
    respond(['ok' => false, 'error' => 'group_id required'], 400);
}

$sql = <<<SQL
  SELECT
    cgi.cta_group_item_id,
    cgi.cta_group_id,
    cgi.cta_id,
    cgi.order_index,
    c.label,
    c.params,
    c.is_active,
    t.action_key AS type_action_key,
    t.label AS type_label
  FROM cta_group_items cgi
  JOIN ctas c ON c.cta_id = cgi.cta_id
  JOIN cta_types t ON t.cta_type_id = c.cta_type_id
  WHERE cgi.cta_group_id = :group_id
  ORDER BY cgi.order_index ASC, cgi.cta_group_item_id ASC
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute(['group_id' => $groupId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

respond(['ok' => true, 'items' => $rows]);
