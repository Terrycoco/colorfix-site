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

function slugify(string $value): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : ('kicker-' . time());
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
$displayText = trim((string)($data['display_text'] ?? ''));
$slug = trim((string)($data['slug'] ?? ''));
$isActive = isset($data['is_active']) ? (int)!!$data['is_active'] : 1;
$sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

if ($displayText === '') {
    respond(['ok' => false, 'error' => 'display_text required'], 400);
}

if ($slug === '') {
    $slug = slugify($displayText);
}

try {
    if ($kickerId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE kickers
             SET slug = :slug,
                 display_text = :display_text,
                 is_active = :is_active,
                 sort_order = :sort_order
             WHERE kicker_id = :kicker_id"
        );
        $stmt->execute([
            ':slug' => $slug,
            ':display_text' => $displayText,
            ':is_active' => $isActive,
            ':sort_order' => $sortOrder,
            ':kicker_id' => $kickerId,
        ]);
        respond(['ok' => true, 'kicker_id' => $kickerId]);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO kickers (slug, display_text, is_active, sort_order)
         VALUES (:slug, :display_text, :is_active, :sort_order)"
    );
    $stmt->execute([
        ':slug' => $slug,
        ':display_text' => $displayText,
        ':is_active' => $isActive,
        ':sort_order' => $sortOrder,
    ]);
    respond(['ok' => true, 'kicker_id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
