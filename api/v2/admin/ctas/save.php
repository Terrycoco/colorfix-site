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

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$id = isset($payload['cta_id']) ? (int)$payload['cta_id'] : 0;
$ctaTypeId = isset($payload['cta_type_id']) ? (int)$payload['cta_type_id'] : 0;
$label = trim((string)($payload['label'] ?? ''));
$params = $payload['params'] ?? null;
$isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

if ($ctaTypeId <= 0) {
    respond(['ok' => false, 'error' => 'cta_type_id required'], 400);
}
if ($label === '') {
    respond(['ok' => false, 'error' => 'label required'], 400);
}

try {
    if ($id > 0) {
        $sql = <<<SQL
          UPDATE ctas
          SET cta_type_id = :cta_type_id,
              label = :label,
              params = :params,
              is_active = :is_active
          WHERE cta_id = :cta_id
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'cta_id' => $id,
            'cta_type_id' => $ctaTypeId,
            'label' => $label,
            'params' => $params,
            'is_active' => $isActive,
        ]);
    } else {
        $sql = <<<SQL
          INSERT INTO ctas (cta_type_id, label, params, is_active)
          VALUES (:cta_type_id, :label, :params, :is_active)
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'cta_type_id' => $ctaTypeId,
            'label' => $label,
            'params' => $params,
            'is_active' => $isActive,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true, 'cta_id' => $id]);
