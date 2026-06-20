<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoClientActivityRepository;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) $payload = $_POST;

    $activityId = isset($payload['activity_id']) ? (int)$payload['activity_id'] : 0;
    $clientId = isset($payload['client_id']) ? (int)$payload['client_id'] : 0;
    if ($activityId <= 0) {
        respond(['ok' => false, 'error' => 'activity_id required'], 400);
    }

    $repo = new PdoClientActivityRepository($pdo);
    $repo->markRead($activityId, $clientId > 0 ? $clientId : null);

    respond([
        'ok' => true,
        'unread_site_note_count' => $repo->countUnreadSiteNotes(),
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
