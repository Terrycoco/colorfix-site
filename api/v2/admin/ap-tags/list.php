<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$apId = isset($_GET['applied_palette_id']) ? (int)$_GET['applied_palette_id'] : 0;
if ($apId <= 0) {
    respond(['ok' => false, 'error' => 'applied_palette_id required'], 400);
}

$stmt = $pdo->prepare("SELECT tag FROM ap_tags WHERE applied_palette_id = :id ORDER BY tag");
$stmt->execute([':id' => $apId]);
$tags = array_map(fn($row) => (string)$row['tag'], $stmt->fetchAll(PDO::FETCH_ASSOC));

respond(['ok' => true, 'applied_palette_id' => $apId, 'tags' => $tags]);
