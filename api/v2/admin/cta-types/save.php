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

$id = isset($payload['cta_type_id']) ? (int)$payload['cta_type_id'] : 0;
$actionKey = trim((string)($payload['action_key'] ?? ''));
$label = trim((string)($payload['label'] ?? ''));
$description = $payload['description'] ?? null;
$isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

if ($actionKey === '') {
    respond(['ok' => false, 'error' => 'action_key required'], 400);
}
if ($label === '') {
    respond(['ok' => false, 'error' => 'label required'], 400);
}

try {
    if ($id > 0) {
        $sql = <<<SQL
          UPDATE cta_types
          SET action_key = :action_key,
              label = :label,
              description = :description,
              is_active = :is_active
          WHERE cta_type_id = :cta_type_id
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'cta_type_id' => $id,
            'action_key' => $actionKey,
            'label' => $label,
            'description' => $description,
            'is_active' => $isActive,
        ]);
    } else {
        $sql = <<<SQL
          INSERT INTO cta_types (action_key, label, description, is_active)
          VALUES (:action_key, :label, :description, :is_active)
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'action_key' => $actionKey,
            'label' => $label,
            'description' => $description,
            'is_active' => $isActive,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true, 'cta_type_id' => $id]);
