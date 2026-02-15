<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$kickerId = isset($data['kicker_id']) ? (int)$data['kicker_id'] : 0;
if ($kickerId <= 0) {
    respond(['ok' => false, 'error' => 'kicker_id required'], 400);
}

try {
    $usageSql = <<<SQL
        SELECT
            (SELECT COUNT(*) FROM saved_palettes sp WHERE sp.kicker_id = :id1) AS saved_count,
            (SELECT COUNT(*) FROM applied_palettes ap WHERE ap.kicker_id = :id2) AS applied_count,
            (SELECT COUNT(*) FROM playlist_instances pi WHERE pi.kicker_id = :id3) AS playlist_instance_count
    SQL;
    $usageStmt = $pdo->prepare($usageSql);
    $usageStmt->execute([
        ':id1' => $kickerId,
        ':id2' => $kickerId,
        ':id3' => $kickerId,
    ]);
    $usage = $usageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("DELETE FROM kickers WHERE kicker_id = :id");
    $stmt->execute([':id' => $kickerId]);

    respond([
        'ok' => true,
        'kicker_id' => $kickerId,
        'usage' => [
            'saved_count' => (int)($usage['saved_count'] ?? 0),
            'applied_count' => (int)($usage['applied_count'] ?? 0),
            'playlist_instance_count' => (int)($usage['playlist_instance_count'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
