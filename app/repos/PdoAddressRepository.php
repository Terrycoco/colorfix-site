<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoAddressRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare("SELECT * FROM addresses ORDER BY updated_at DESC, id DESC LIMIT {$limit}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM addresses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO addresses (
                street_1,
                street_2,
                city,
                state,
                postal_code,
                country_code
            ) VALUES (
                :street_1,
                :street_2,
                :city,
                :state,
                :postal_code,
                :country_code
            )
        ");
        $stmt->execute([
            ':street_1' => $data['street_1'],
            ':street_2' => $data['street_2'] ?? null,
            ':city' => $data['city'],
            ':state' => $data['state'],
            ':postal_code' => $data['postal_code'],
            ':country_code' => $data['country_code'] ?? 'US',
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['street_1', 'street_2', 'city', 'state', 'postal_code', 'country_code'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }
        if (!$set) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE addresses SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }
}
