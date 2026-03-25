<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoClientRepository;
use InvalidArgumentException;
use PDO;

final class ClientService
{
    public function __construct(
        private PdoClientRepository $repo,
        private ?PDO $pdo = null
    ) {}

    /**
     * @return array{id:int, name:string, email:string, phone:?string, notes:?string}
     */
    public function findOrCreateByEmail(string $email, ?string $name = null, ?string $phone = null): array
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            throw new InvalidArgumentException('client email required');
        }

        $existing = $this->repo->findByEmail($normalizedEmail);
        if ($existing) {
            $updates = [];
            $normalizedName = $this->normalizeName($name);
            if ($normalizedName !== null && empty($existing['name'])) {
                $updates['name'] = $normalizedName;
            }
            if ($phone !== null && trim($phone) !== '' && empty($existing['phone'])) {
                $updates['phone'] = trim($phone);
            }
            if ($updates) {
                $this->repo->update((int)$existing['id'], $updates);
                $existing = $this->repo->findById((int)$existing['id']) ?? $existing;
            }
            return $this->normalizeRow($existing);
        }

        $id = $this->repo->create([
            'name' => $this->normalizeName($name) ?? $this->fallbackNameFromEmail($normalizedEmail),
            'email' => $normalizedEmail,
            'phone' => $phone !== null ? trim($phone) : null,
        ]);

        $created = $this->repo->findById($id);
        if (!$created) {
            throw new \RuntimeException('Failed to create client');
        }

        return $this->normalizeRow($created);
    }

    /**
     * @return array{id:int, name:string, email:string, phone:?string, notes:?string}
     */
    public function saveClient(?int $id, array $input): array
    {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if ($email === '') {
            throw new InvalidArgumentException('email required');
        }

        $name = $this->normalizeName($input['name'] ?? null) ?? $this->fallbackNameFromEmail($email);
        $phone = $this->normalizeOptionalString($input['phone'] ?? null);
        $notes = $this->normalizeOptionalString($input['notes'] ?? null);

        if (($id ?? 0) > 0) {
            $existing = $this->repo->findById((int)$id);
            if (!$existing) {
                throw new InvalidArgumentException('client not found');
            }

            $emailOwner = $this->repo->findByEmail($email);
            if ($emailOwner && (int)$emailOwner['id'] !== (int)$id) {
                throw new InvalidArgumentException('another client already uses that email');
            }

            $this->repo->update((int)$id, [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'notes' => $notes,
            ]);

            $updated = $this->repo->findById((int)$id);
            if (!$updated) {
                throw new \RuntimeException('Failed to reload client');
            }
            return $this->normalizeRow($updated);
        }

        $existing = $this->repo->findByEmail($email);
        if ($existing) {
            $this->repo->update((int)$existing['id'], [
                'name' => $name,
                'phone' => $phone,
                'notes' => $notes,
            ]);
            $existing = $this->repo->findById((int)$existing['id']) ?? $existing;
            return $this->normalizeRow($existing);
        }

        $createdId = $this->repo->create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
        ]);
        $created = $this->repo->findById($createdId);
        if (!$created) {
            throw new \RuntimeException('Failed to create client');
        }
        return $this->normalizeRow($created);
    }

    public function deleteClient(int $id): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('client id required');
        }

        $existing = $this->repo->findById($id);
        if (!$existing) {
            throw new InvalidArgumentException('client not found');
        }

        $impact = $this->repo->getUsageSummary($id);
        if (!$this->pdo) {
            $this->repo->clearPhotoLibraryClient($id);
            $this->repo->deleteAppliedPaletteShares($id);
            $this->repo->deleteAppliedPaletteLinks($id);
            $this->repo->delete($id);
            return $impact;
        }

        $this->pdo->beginTransaction();
        try {
            $this->repo->clearPhotoLibraryClient($id);
            $this->repo->deleteAppliedPaletteShares($id);
            $this->repo->deleteAppliedPaletteLinks($id);
            $this->repo->delete($id);
            $this->pdo->commit();
            return $impact;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function normalizeName(?string $name): ?string
    {
        $normalized = trim((string)$name);
        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string)$value);
        return $normalized === '' ? null : $normalized;
    }

    private function fallbackNameFromEmail(string $email): string
    {
        $local = explode('@', $email)[0] ?? 'Client';
        $local = str_replace(['.', '_', '-'], ' ', $local);
        $local = trim($local);
        return $local !== '' ? ucwords($local) : 'Client';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, name:string, email:string, phone:?string, notes:?string}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'phone' => isset($row['phone']) ? (string)$row['phone'] : null,
            'notes' => isset($row['notes']) ? (string)$row['notes'] : null,
        ];
    }
}
