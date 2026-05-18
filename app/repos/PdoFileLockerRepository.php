<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoFileLockerRepository
{
    public function __construct(private PDO $pdo) {}

    public function findBySlot(string $slotKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_locker_entries WHERE slot_key = :slot_key LIMIT 1');
        $stmt->execute([':slot_key' => $slotKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(string $slotKey, array $payload): int
    {
        $existing = $this->findBySlot($slotKey);
        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE file_locker_entries
                    SET original_name = :original_name,
                        stored_name = :stored_name,
                        mime_type = :mime_type,
                        file_size = :file_size,
                        note = :note,
                        updated_at = NOW()
                  WHERE id = :id'
            );
            $stmt->execute([
                ':id' => (int)$existing['id'],
                ':original_name' => (string)$payload['original_name'],
                ':stored_name' => (string)$payload['stored_name'],
                ':mime_type' => $payload['mime_type'] ?? null,
                ':file_size' => (int)$payload['file_size'],
                ':note' => $payload['note'] ?? null,
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO file_locker_entries (
                slot_key, original_name, stored_name, mime_type, file_size, note
             ) VALUES (
                :slot_key, :original_name, :stored_name, :mime_type, :file_size, :note
             )'
        );
        $stmt->execute([
            ':slot_key' => $slotKey,
            ':original_name' => (string)$payload['original_name'],
            ':stored_name' => (string)$payload['stored_name'],
            ':mime_type' => $payload['mime_type'] ?? null,
            ':file_size' => (int)$payload['file_size'],
            ':note' => $payload['note'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteBySlot(string $slotKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM file_locker_entries WHERE slot_key = :slot_key');
        $stmt->execute([':slot_key' => $slotKey]);
    }
}
