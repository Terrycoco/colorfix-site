<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoLegacyProjectRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $slug = trim((string)($data['slug'] ?? ''));
    $title = trim((string)($data['title'] ?? ''));
    $projectType = trim((string)($data['project_type'] ?? ''));
    $status = trim((string)($data['status'] ?? 'draft'));
    $summary = isset($data['summary']) ? (string)$data['summary'] : null;
    $notes = isset($data['notes']) ? (string)$data['notes'] : null;
    $clientName = trim((string)($data['client_name'] ?? ''));

    if ($slug === '' || $title === '' || $projectType === '') {
        respond(['ok' => false, 'error' => 'slug, title, and project_type are required'], 400);
    }

    $repo = new PdoLegacyProjectRepository($pdo);
    $payload = [
        'slug' => $slug,
        'title' => $title,
        'project_type' => $projectType,
        'status' => $status,
        'summary' => $summary !== '' ? $summary : null,
        'notes' => $notes !== '' ? $notes : null,
        'client_name' => $clientName !== '' ? $clientName : null,
    ];

    if ($id > 0) {
        $repo->update($id, $payload);
        respond(['ok' => true, 'id' => $id]);
    }

    respond(['ok' => true, 'id' => $repo->insert($payload)]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
