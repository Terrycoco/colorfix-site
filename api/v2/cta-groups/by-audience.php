<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$audienceRaw = trim((string)($_GET['audience'] ?? ''));
$allowed = ['any', 'hoa', 'homeowner', 'contractor', 'admin'];
$audienceLower = strtolower($audienceRaw);
$audience = in_array($audienceLower, $allowed, true) ? $audienceLower : '';

if ($audience === '') {
    respond(['ok' => false, 'error' => 'audience required'], 400);
}

$sql = <<<SQL
  SELECT id, `key`, label, description, audience
  FROM cta_groups
  WHERE LOWER(audience) IN (:audience_filter, :any_audience)
  ORDER BY (LOWER(audience) = :audience_match) DESC, id ASC
  LIMIT 1
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'audience_filter' => $audience,
    'audience_match' => $audience,
    'any_audience' => 'any',
]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    respond(['ok' => false, 'error' => 'Group not found'], 404);
}

respond(['ok' => true, 'group' => $row]);
