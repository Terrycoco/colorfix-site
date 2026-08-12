<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoProjectRepository;


try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $repo = new PdoProjectRepository($pdo);
    $rows = $repo->list([
        'experience_key' => trim((string)($_GET['experience_key'] ?? '')),
        'project_type_id' => isset($_GET['project_type_id']) ? (int)$_GET['project_type_id'] : 0,
        'property_id' => isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0,
    ], 500);

   $query = strtolower(trim((string)($_GET['q'] ?? '')));

    $items = array_map(
        static fn(array $row): array => workflow_project_payload($row),
        $rows
    );



    if ($query !== '') {
        $items = array_values(array_filter($items, static function (array $item) use ($query): bool {
            return str_contains(strtolower(implode(' ', [
                $item['name'] ?? '',
                $item['property_name'] ?? '',
                $item['client_name'] ?? '',
                $item['project_type_name'] ?? '',
            ])), $query);
        }));
    }

    workflow_respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
