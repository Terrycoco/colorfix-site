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

function normalize_activity_row(array $row): array
{
    $metadata = null;
    if (!empty($row['metadata_json'])) {
        $decoded = json_decode((string)$row['metadata_json'], true);
        if (is_array($decoded)) $metadata = $decoded;
    }

    return [
        'id' => (int)($row['client_activity_id'] ?? 0),
        'client_activity_id' => (int)($row['client_activity_id'] ?? 0),
        'client_id' => (int)($row['client_id'] ?? 0),
        'activity_type' => (string)($row['activity_type'] ?? ''),
        'summary' => (string)($row['summary'] ?? ''),
        'details' => (string)($row['details'] ?? ''),
        'occurred_at' => (string)($row['occurred_at'] ?? ''),
        'related_client_email_id' => isset($row['related_client_email_id']) ? (int)$row['related_client_email_id'] : null,
        'metadata' => $metadata,
        'email' => !empty($row['related_client_email_id']) ? [
            'direction' => (string)($row['email_direction'] ?? ''),
            'status' => (string)($row['email_status'] ?? ''),
            'subject' => (string)($row['email_subject'] ?? ''),
            'to_email' => (string)($row['email_to_email'] ?? ''),
            'cc_emails' => (string)($row['email_cc_emails'] ?? ''),
            'bcc_emails' => (string)($row['email_bcc_emails'] ?? ''),
            'text_body' => (string)($row['email_text_body'] ?? ''),
            'html_body' => (string)($row['email_html_body'] ?? ''),
            'sent_at' => isset($row['email_sent_at']) ? (string)$row['email_sent_at'] : null,
            'received_at' => isset($row['email_received_at']) ? (string)$row['email_received_at'] : null,
        ] : null,
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
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;

    $repo = new PdoClientActivityRepository($pdo);
    $items = array_map('normalize_activity_row', $repo->listByClient($clientId, $limit));

    respond(['ok' => true, 'items' => $items]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
