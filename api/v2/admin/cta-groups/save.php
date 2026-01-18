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

$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$key = trim((string)($payload['key'] ?? ''));
$label = trim((string)($payload['label'] ?? ''));
$description = $payload['description'] ?? null;
$audienceRaw = trim((string)($payload['audience'] ?? ''));
$allowedAudiences = ['any', 'hoa', 'homeowner', 'contractor', 'admin'];
$audience = in_array($audienceRaw, $allowedAudiences, true) ? $audienceRaw : 'homeowner';

if ($key === '') {
    respond(['ok' => false, 'error' => 'key required'], 400);
}
if ($label === '') {
    respond(['ok' => false, 'error' => 'label required'], 400);
}

try {
    if ($id > 0) {
        $sql = <<<SQL
          UPDATE cta_groups
          SET `key` = :key,
              label = :label,
              description = :description,
              audience = :audience
          WHERE id = :id
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'audience' => $audience,
        ]);
    } else {
        $sql = <<<SQL
          INSERT INTO cta_groups (`key`, label, description, audience)
          VALUES (:key, :label, :description, :audience)
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'audience' => $audience,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true, 'id' => $id]);
