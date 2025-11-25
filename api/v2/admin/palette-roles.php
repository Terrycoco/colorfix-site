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

function load_roles(PDO $pdo, int $paletteId): array {
    $stmt = $pdo->prepare("
        SELECT mr.slug,
               c.id   AS color_id,
               c.name,
               c.brand,
               c.code,
               c.hex6
        FROM palette_role_members prm
        JOIN master_roles mr ON mr.id = prm.role_id
        LEFT JOIN colors c ON c.id = prm.color_id
        WHERE prm.palette_id = ?
    ");
    $stmt->execute([$paletteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $slug = strtolower((string)$row['slug']);
        $out[$slug] = [
            'color_id' => isset($row['color_id']) ? (int)$row['color_id'] : null,
            'name'     => $row['name'],
            'brand'    => $row['brand'],
            'code'     => $row['code'],
            'hex6'     => strtoupper((string)$row['hex6']),
        ];
    }
    return $out;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $paletteId = (int)($_GET['palette_id'] ?? 0);
        if ($paletteId <= 0) respond(['ok' => false, 'error' => 'palette_id required'], 400);
        respond(['ok' => true, 'roles' => load_roles($pdo, $paletteId)]);
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) respond(['ok' => false, 'error' => 'Invalid JSON'], 400);

    $paletteId = (int)($payload['palette_id'] ?? 0);
    if ($paletteId <= 0) respond(['ok' => false, 'error' => 'palette_id required'], 400);

    $rolesInput = $payload['roles'] ?? null;
    if (!is_array($rolesInput) || !$rolesInput) {
        respond(['ok' => false, 'error' => 'roles payload required'], 400);
    }

    $roleStmt = $pdo->query("SELECT slug, id FROM master_roles WHERE slug IN ('body','trim','accent')");
    $roleMap = $roleStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $pdo->beginTransaction();
    try {
        foreach ($rolesInput as $slug => $value) {
            $slugNorm = strtolower(trim((string)$slug));
            if (!isset($roleMap[$slugNorm])) continue;
            $roleId = (int)$roleMap[$slugNorm];
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                $del = $pdo->prepare("DELETE FROM palette_role_members WHERE palette_id = ? AND role_id = ?");
                $del->execute([$paletteId, $roleId]);
                continue;
            }
            $colorId = (int)$value;
            if ($colorId <= 0) continue;
            $ins = $pdo->prepare("
                INSERT INTO palette_role_members (palette_id, role_id, color_id, priority)
                VALUES (:pid, :rid, :cid, 0)
                ON DUPLICATE KEY UPDATE color_id = VALUES(color_id)
            ");
                $ins->execute([':pid' => $paletteId, ':rid' => $roleId, ':cid' => $colorId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    respond(['ok' => true, 'roles' => load_roles($pdo, $paletteId)]);

} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
