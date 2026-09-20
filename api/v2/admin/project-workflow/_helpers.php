<?php
declare(strict_types=1);

function workflow_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function workflow_optional_string(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : null;
}

function workflow_client_name(array $row): string
{
    $name = trim((string)($row['client_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $parts = array_filter([
        trim((string)($row['client_first_name'] ?? '')),
        trim((string)($row['client_last_name'] ?? '')),
    ]);
    $fromParts = trim(implode(' ', $parts));
    if ($fromParts !== '') {
        return $fromParts;
    }
    return trim((string)($row['client_email'] ?? ''));
}

function workflow_address_from_row(array $row): ?array
{
    $addressId = isset($row['address_id']) ? (int)$row['address_id'] : 0;
    $hasAddress = $addressId > 0
        || trim((string)($row['street_1'] ?? '')) !== ''
        || trim((string)($row['city'] ?? '')) !== '';

    if (!$hasAddress) {
        return null;
    }

    return [
        'id' => $addressId > 0 ? $addressId : null,
        'street_1' => (string)($row['street_1'] ?? ''),
        'street_2' => (string)($row['street_2'] ?? ''),
        'city' => (string)($row['city'] ?? ''),
        'state' => (string)($row['state'] ?? ''),
        'postal_code' => (string)($row['postal_code'] ?? ''),
        'country_code' => (string)($row['country_code'] ?? 'US'),
    ];
}

function workflow_property_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)($row['name'] ?? ''),
        'client_id' => isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null,
        'client_name' => workflow_client_name($row),
        'address_id' => isset($row['address_id']) && $row['address_id'] !== null ? (int)$row['address_id'] : null,
        'address' => workflow_address_from_row($row),
        'notes' => (string)($row['notes'] ?? ''),
        'project_count' => (int)($row['project_count'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}
