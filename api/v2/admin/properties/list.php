<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoPropertyRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    // Property is WHERE, not WHO. No client_id filter here.
    $rows = (new PdoPropertyRepository($pdo))->list([], 500);
    $items = array_map('workflow_property_payload', $rows);

    $query = strtolower(trim((string)($_GET['q'] ?? '')));
    if ($query !== '') {
        $items = array_values(array_filter($items, static function (array $item) use ($query): bool {
            $address = $item['address'] ?? [];
            return str_contains(strtolower(implode(' ', [
                $item['name'] ?? '',
                $address['street_1'] ?? '',
                $address['street_2'] ?? '',
                $address['city'] ?? '',
                $address['state'] ?? '',
                $address['postal_code'] ?? '',
            ])), $query);
        }));
    }

    workflow_respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
