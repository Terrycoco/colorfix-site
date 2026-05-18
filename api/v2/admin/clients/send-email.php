<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoClientActivityRepository;
use App\Repos\PdoClientEmailRepository;
use App\Repos\PdoClientRepository;
use App\Repos\PdoEmailTemplateRepository;
use App\Services\ClientEmailService;

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

    $service = new ClientEmailService(
        new PdoClientRepository($pdo),
        new PdoClientEmailRepository($pdo),
        new PdoClientActivityRepository($pdo),
        new PdoEmailTemplateRepository($pdo)
    );

    $result = $service->sendClientEmail($payload);

    respond([
        'ok' => true,
        'sent_at' => $result['sent_at'],
        'permission_status' => $result['permission_status'],
        'client_email' => $result['client_email'],
    ]);
} catch (\InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
