<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectManager;
use App\Repos\PdoPropertyRepository;
use PDO;
use Throwable;

final class PropertyListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        try {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
                self::respond(['ok' => false, 'error' => 'GET only'], 405);
            }

            $rows = (new PdoPropertyRepository($pdo))->list([], 500);
            $projects = new ProjectManager($pdo);
            $query = strtolower(trim((string)($_GET['q'] ?? '')));
            $items = [];

            foreach ($rows as $row) {
                $row['project_count'] = $projects->countProjectsByPropertyId((int)$row['id']);
                $item = self::payload($row);
                $address = $item['address'] ?? [];
                $haystack = strtolower(implode(' ', [
                    $item['name'],
                    $address['street_1'] ?? '',
                    $address['street_2'] ?? '',
                    $address['city'] ?? '',
                    $address['state'] ?? '',
                    $address['postal_code'] ?? '',
                ]));
                if ($query === '' || str_contains($haystack, $query)) {
                    $items[] = $item;
                }
            }

            self::respond(['ok' => true, 'items' => $items]);
        } catch (Throwable $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function payload(array $row): array
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
