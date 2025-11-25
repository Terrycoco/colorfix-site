<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoClientRepository
{
    public function __construct(private PDO $pdo) {}

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
}
