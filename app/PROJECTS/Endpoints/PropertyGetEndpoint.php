<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectManager;
use App\Repos\PdoPropertyRepository;
use PDO;
use Throwable;

final class PropertyGetEndpoint
{
    public static function handle(PDO $pdo): void
    {
        try {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
                self::respond(['ok' => false, 'error' => 'GET only'], 405);
            }
            $propertyId = (int)($_GET['id'] ?? 0);
            if ($propertyId <= 0) {
                self::respond(['ok' => false, 'error' => 'id required'], 400);
            }
            $property = (new PdoPropertyRepository($pdo))->findById($propertyId);
            if ($property === null) {
                self::respond(['ok' => false, 'error' => 'Property not found'], 404);
            }
            $manager = new ProjectManager($pdo);
            $projects = $manager->listProjectsByPropertyId($propertyId);
            $property['project_count'] = $manager->countProjectsByPropertyId($propertyId);
            self::respond([
                'ok' => true,
                'property' => self::propertyPayload($property),
                'projects' => array_map(self::projectPayload(...), $projects),
            ]);
        } catch (Throwable $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function projectPayload(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'project_name' => (string)($row['project_name'] ?? ''),
            'client_id' => isset($row['client_id']) ? (int)$row['client_id'] : null,
            'client_name' => (string)($row['client_name'] ?? ''),
            'property_id' => isset($row['property_id']) ? (int)$row['property_id'] : null,
            'property_name' => (string)($row['property_name'] ?? ''),
            'playlist_id' => isset($row['playlist_id']) ? (int)$row['playlist_id'] : null,
            'playlist_title' => (string)($row['playlist_title'] ?? ''),
        ];
    }

    private static function propertyPayload(array $row): array
    {
        $clientName = trim((string)($row['client_name'] ?? ''));
        if ($clientName === '') {
            $clientName = trim(implode(' ', array_filter([
                trim((string)($row['client_first_name'] ?? '')),
                trim((string)($row['client_last_name'] ?? '')),
            ]))) ?: trim((string)($row['client_email'] ?? ''));
        }
        $addressId = (int)($row['address_id'] ?? 0);
        $hasAddress = $addressId > 0
            || trim((string)($row['street_1'] ?? '')) !== ''
            || trim((string)($row['city'] ?? '')) !== '';

        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'client_id' => isset($row['client_id']) ? (int)$row['client_id'] : null,
            'client_name' => $clientName,
            'address_id' => $addressId > 0 ? $addressId : null,
            'address' => $hasAddress ? [
                'id' => $addressId > 0 ? $addressId : null,
                'street_1' => (string)($row['street_1'] ?? ''),
                'street_2' => (string)($row['street_2'] ?? ''),
                'city' => (string)($row['city'] ?? ''),
                'state' => (string)($row['state'] ?? ''),
                'postal_code' => (string)($row['postal_code'] ?? ''),
                'country_code' => (string)($row['country_code'] ?? 'US'),
            ] : null,
            'notes' => (string)($row['notes'] ?? ''),
            'project_count' => (int)($row['project_count'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
