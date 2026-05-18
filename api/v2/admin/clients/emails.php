<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoClientEmailRepository;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_client_email_row(array $row): array
{
    return [
        'id' => (int)($row['client_email_id'] ?? 0),
        'client_id' => (int)($row['client_id'] ?? 0),
        'direction' => (string)($row['direction'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'purpose' => isset($row['purpose']) ? (string)$row['purpose'] : null,
        'template_key' => isset($row['template_key']) ? (string)$row['template_key'] : null,
        'from_email' => (string)($row['from_email'] ?? ''),
        'to_email' => (string)($row['to_email'] ?? ''),
        'cc_emails' => isset($row['cc_emails']) ? (string)$row['cc_emails'] : '',
        'bcc_emails' => isset($row['bcc_emails']) ? (string)$row['bcc_emails'] : '',
        'subject' => (string)($row['subject'] ?? ''),
        'text_body' => (string)($row['text_body'] ?? ''),
        'html_body' => (string)($row['html_body'] ?? ''),
        'provider_message_id' => isset($row['provider_message_id']) ? (string)$row['provider_message_id'] : null,
        'in_reply_to_message_id' => isset($row['in_reply_to_message_id']) ? (string)$row['in_reply_to_message_id'] : null,
        'sent_at' => isset($row['sent_at']) ? (string)$row['sent_at'] : null,
        'received_at' => isset($row['received_at']) ? (string)$row['received_at'] : null,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    if ($clientId <= 0) {
        respond(['ok' => false, 'error' => 'client_id required'], 400);
    }
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $repo = new PdoClientEmailRepository($pdo);
    $items = array_map('normalize_client_email_row', $repo->listByClient($clientId, $limit));

    respond(['ok' => true, 'items' => $items]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
