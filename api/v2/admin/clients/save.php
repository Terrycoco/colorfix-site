<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoClientRepository;
use App\Services\ClientService;

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
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $id = isset($payload['id']) ? (int)$payload['id'] : null;

    $service = new ClientService(new PdoClientRepository($pdo), $pdo);
    $client = $service->saveClient($id, [
        'name' => $payload['name'] ?? '',
        'email' => $payload['email'] ?? '',
        'phone' => $payload['phone'] ?? '',
        'notes' => $payload['notes'] ?? '',
    ]);

    respond([
        'ok' => true,
        'client' => $client,
    ]);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
