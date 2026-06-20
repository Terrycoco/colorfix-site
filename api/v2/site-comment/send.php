<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoClientActivityRepository;
use App\Repos\PdoClientEmailRepository;
use App\Repos\PdoClientRepository;
use App\Services\ClientService;
use App\Services\SiteCommentService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$honeypot = trim((string)($payload['website'] ?? ''));
if ($honeypot !== '') {
    respond(['ok' => true]);
}

$payload['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
$payload['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $clientRepo = new PdoClientRepository($pdo);
    $service = new SiteCommentService(
        new ClientService($clientRepo, $pdo),
        new PdoClientEmailRepository($pdo),
        new PdoClientActivityRepository($pdo)
    );
    $result = $service->receive($payload);

    respond([
        'ok' => true,
        'client_id' => (int)$result['client']['id'],
        'client_activity_id' => $result['client_activity_id'],
    ]);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
