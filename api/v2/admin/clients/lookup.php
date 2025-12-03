<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoClientRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'GET only']);
        exit;
    }
    $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
    if ($email === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'email required']);
        exit;
    }
    $repo = new PdoClientRepository($pdo);
    $client = $repo->findByEmail($email);
    if (!$client) {
        echo json_encode(['ok' => true, 'client' => null]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'client' => [
            'id' => (int)$client['id'],
            'name' => $client['name'] ?? '',
            'email' => $client['email'] ?? '',
            'phone' => $client['phone'] ?? '',
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
