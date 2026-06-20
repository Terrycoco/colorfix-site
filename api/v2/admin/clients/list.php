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

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $repo = new PdoClientRepository($pdo);
    $rows = $q !== '' ? $repo->search($q, $limit) : $repo->listAll($limit);

    $items = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'first_name' => isset($row['first_name']) ? (string)$row['first_name'] : '',
            'last_name' => isset($row['last_name']) ? (string)$row['last_name'] : '',
            'email' => (string)($row['email'] ?? ''),
            'phone' => isset($row['phone']) ? (string)$row['phone'] : '',
            'unread_site_note_count' => (int)($row['unread_site_note_count'] ?? 0),
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
