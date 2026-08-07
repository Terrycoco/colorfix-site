<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoClientRepository;
use App\Repos\PdoProjectTypeRepository;
use App\Repos\PdoPropertyRepository;

function project_options_client_name(array $row): string
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $parts = array_filter([
        trim((string)($row['first_name'] ?? '')),
        trim((string)($row['last_name'] ?? '')),
    ]);
    $fromParts = trim(implode(' ', $parts));
    if ($fromParts !== '') {
        return $fromParts;
    }

    return trim((string)($row['email'] ?? ''));
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $properties = array_map(
        'workflow_property_payload',
        (new PdoPropertyRepository($pdo))->list([], 1000)
    );
    $projectTypes = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'slug' => (string)$row['slug'],
        'is_active' => (int)($row['is_active'] ?? 1),
    ], (new PdoProjectTypeRepository($pdo))->list(true));
    $clients = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => project_options_client_name($row),
            'email' => (string)($row['email'] ?? ''),
        ];
    }, (new PdoClientRepository($pdo))->listAll(1000));

    workflow_respond([
        'ok' => true,
        'properties' => $properties,
        'project_types' => $projectTypes,
        'clients' => $clients,
        'experiences' => [
            ['key' => 'public', 'label' => 'Public'],
            ['key' => 'concept', 'label' => 'Concept'],
            ['key' => 'client', 'label' => 'Client'],
            ['key' => 'painter', 'label' => 'Painter'],
        ],
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
