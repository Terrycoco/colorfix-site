<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
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

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $id = isset($payload['photo_library_id']) ? (int)$payload['photo_library_id'] : 0;
    if ($id <= 0) {
        respond(['ok' => false, 'error' => 'photo_library_id required'], 400);
    }

    $fields = [];
    if (array_key_exists('title', $payload)) $fields['title'] = trim((string)$payload['title']);
    if (array_key_exists('tags', $payload)) $fields['tags'] = trim((string)$payload['tags']);
    if (array_key_exists('alt_text', $payload)) $fields['alt_text'] = trim((string)$payload['alt_text']);
    if (array_key_exists('show_in_gallery', $payload)) $fields['show_in_gallery'] = !empty($payload['show_in_gallery']);
    if (array_key_exists('has_palette', $payload)) $fields['has_palette'] = !empty($payload['has_palette']);

    if (!$fields) {
        respond(['ok' => false, 'error' => 'No fields to update'], 400);
    }

    $setParts = [];
    $params = [':id' => $id];
    foreach ($fields as $key => $value) {
        $paramKey = ':' . $key;
        if (in_array($key, ['show_in_gallery', 'has_palette'], true)) {
            $params[$paramKey] = $value ? 1 : 0;
        } else {
            $params[$paramKey] = $value === '' ? null : $value;
        }
        $setParts[] = "{$key} = {$paramKey}";
    }

    $sql = "UPDATE photo_library SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE photo_library_id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
