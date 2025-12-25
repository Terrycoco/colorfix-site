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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
$tags = $input['tags'] ?? [];
if ($photoId <= 0) {
    respond(['ok' => false, 'error' => 'photo_id required'], 400);
}
if (!is_array($tags)) {
    respond(['ok' => false, 'error' => 'tags must be array'], 400);
}

$clean = [];
foreach ($tags as $tag) {
    if (!is_string($tag)) continue;
    $t = trim($tag);
    if ($t === '') continue;
    $t = preg_replace('/\s+/', ' ', $t);
    $t = substr($t, 0, 64);
    $clean[$t] = true;
}
$cleanTags = array_keys($clean);

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM photos_tags WHERE photo_id = :id")
        ->execute([':id' => $photoId]);
    if ($cleanTags) {
        $stmt = $pdo->prepare("INSERT INTO photos_tags (photo_id, tag) VALUES (:id, :tag)");
        foreach ($cleanTags as $tag) {
            $stmt->execute([':id' => $photoId, ':tag' => $tag]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true, 'photo_id' => $photoId, 'tags' => $cleanTags]);
