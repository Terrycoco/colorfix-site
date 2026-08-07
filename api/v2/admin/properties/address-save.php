<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoAddressRepository;
use App\Repos\PdoPropertyRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : 0;
    $propertyRepo = new PdoPropertyRepository($pdo);
    $property = $propertyId > 0 ? $propertyRepo->findById($propertyId) : null;
    if (!$property) {
        workflow_respond(['ok' => false, 'error' => 'Property required'], 400);
    }

    $address = $data['address'] ?? [];
    if (!is_array($address)) {
        workflow_respond(['ok' => false, 'error' => 'Address payload required'], 400);
    }

    $payload = [
        'street_1' => workflow_optional_string($address['street_1'] ?? null),
        'street_2' => workflow_optional_string($address['street_2'] ?? null),
        'city' => workflow_optional_string($address['city'] ?? null),
        'state' => workflow_optional_string($address['state'] ?? null),
        'postal_code' => workflow_optional_string($address['postal_code'] ?? null),
        'country_code' => strtoupper(workflow_optional_string($address['country_code'] ?? null) ?? 'US'),
    ];

    foreach (['street_1', 'city', 'state', 'postal_code'] as $field) {
        if ($payload[$field] === null) {
            workflow_respond(['ok' => false, 'error' => "{$field} required"], 400);
        }
    }
    if (strlen($payload['country_code']) !== 2) {
        workflow_respond(['ok' => false, 'error' => 'country_code must be two characters'], 400);
    }

    $addressRepo = new PdoAddressRepository($pdo);
    $addressId = isset($property['address_id']) && $property['address_id'] !== null ? (int)$property['address_id'] : 0;
    if ($addressId > 0) {
        $addressRepo->update($addressId, $payload);
    } else {
        $addressId = $addressRepo->create($payload);
        $propertyRepo->update($propertyId, ['address_id' => $addressId]);
    }

    workflow_respond(['ok' => true, 'address_id' => $addressId]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
