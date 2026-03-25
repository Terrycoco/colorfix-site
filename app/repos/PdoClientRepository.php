<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoClientRepository
{
    public function __construct(private PDO $pdo) {}

    public function listAll(int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare($this->baseListSql() . "
              ORDER BY
                CASE WHEN COALESCE(clients.name, '') = '' THEN 1 ELSE 0 END,
                LOWER(COALESCE(clients.name, '')) ASC,
                LOWER(COALESCE(clients.email, '')) ASC
              LIMIT {$limit}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function search(string $query, int $limit = 100): array
    {
        $query = trim($query);
        $limit = max(1, min(500, $limit));

        if ($query === '') {
            return $this->listAll($limit);
        }

        $like = '%' . $query . '%';
        $stmt = $this->pdo->prepare($this->baseListSql() . "
              WHERE clients.name LIKE :query
                 OR clients.email LIKE :query
                 OR clients.phone LIKE :query
              ORDER BY
                CASE WHEN COALESCE(clients.name, '') = '' THEN 1 ELSE 0 END,
                LOWER(COALESCE(clients.name, '')) ASC,
                LOWER(COALESCE(clients.email, '')) ASC
              LIMIT {$limit}");
        $stmt->execute([
            ':query' => $like,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') return null;
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO clients (name, email, phone, notes, created_at)
            VALUES (:name, :email, :phone, :notes, NOW())
        ");
        $stmt->execute([
            ':name'  => $data['name'] ?? 'Client',
            ':email' => $data['email'] ?? sprintf('unknown-%s@invalid.local', uniqid()),
            ':phone' => $data['phone'] ?? null,
            ':notes' => $data['notes'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        if (!$fields) return;
        $allowed = ['name','email','phone','notes'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }
        if (!$set) return;
        $sql = "UPDATE clients SET ".implode(', ', $set).", updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function getUsageSummary(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM photo_library WHERE client_id = :id) AS photo_count,
                (SELECT COUNT(*) FROM client_applied_palettes WHERE client_id = :id) AS applied_palette_count,
                (SELECT COUNT(*) FROM applied_palette_shares WHERE client_id = :id) AS share_count"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'photo_count' => (int)($row['photo_count'] ?? 0),
            'applied_palette_count' => (int)($row['applied_palette_count'] ?? 0),
            'share_count' => (int)($row['share_count'] ?? 0),
        ];
    }

    public function clearPhotoLibraryClient(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE photo_library SET client_id = NULL, updated_at = NOW() WHERE client_id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function deleteAppliedPaletteLinks(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM client_applied_palettes WHERE client_id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function deleteAppliedPaletteShares(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM applied_palette_shares WHERE client_id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM clients WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    private function baseListSql(): string
    {
        return "SELECT
                    clients.id,
                    clients.name,
                    clients.email,
                    clients.phone,
                    clients.notes,
                    (SELECT COUNT(*) FROM photo_library WHERE client_id = clients.id) AS photo_count,
                    (SELECT COUNT(*) FROM client_applied_palettes WHERE client_id = clients.id) AS applied_palette_count,
                    (SELECT COUNT(*) FROM applied_palette_shares WHERE client_id = clients.id) AS share_count
                FROM clients";
    }
}
