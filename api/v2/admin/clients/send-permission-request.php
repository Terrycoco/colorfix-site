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

    $clientId = isset($payload['client_id']) ? (int)$payload['client_id'] : 0;
    if ($clientId <= 0) {
        respond(['ok' => false, 'error' => 'client_id required'], 400);
    }

    $repo = new PdoClientRepository($pdo);
    $client = $repo->findById($clientId);
    if (!$client) {
        respond(['ok' => false, 'error' => 'Client not found'], 404);
    }

    $toEmail = strtolower(trim((string)($client['email'] ?? '')));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'error' => 'Client needs a valid email'], 400);
    }

    $siteUrl = trim((string)($payload['site_url'] ?? 'https://colorfix.terrymarr.com'));
    $service = new ClientEmailService(
        $repo,
        new PdoClientEmailRepository($pdo),
        new PdoClientActivityRepository($pdo),
        new PdoEmailTemplateRepository($pdo)
    );
    $result = $service->sendClientEmail([
        'client_id' => $clientId,
        'to_email' => $toEmail,
        'template_key' => trim((string)($payload['template_key'] ?? 'permission-photo-request')),
        'subject' => (string)($payload['subject'] ?? ''),
        'message' => (string)($payload['message'] ?? ''),
        'html_body' => (string)($payload['html_body'] ?? ''),
        'site_url' => $siteUrl,
        'purpose' => 'photo_permission_request',
        'activity_type' => 'permission_request_sent',
        'activity_summary' => 'Photo permission request sent',
        'mark_permission_requested' => true,
    ]);

    respond([
        'ok' => true,
        'requested_at' => $result['sent_at'],
        'permission_status' => 'requested',
        'client_email' => $result['client_email'],
    ]);
} catch (\InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
