<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\AppTime;
use App\Repos\PdoClientRepository;
use App\Repos\PdoClientTypeRepository;
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
        [$parsedFirstName, $parsedLastName] = $this->splitNameParts($name);

        $existing = $this->repo->findByEmail($normalizedEmail);
        if ($existing) {
            $updates = [];
            $normalizedFirstName = $this->normalizeOptionalString($parsedFirstName);
            $normalizedLastName = $this->normalizeOptionalString($parsedLastName);
            if ($normalizedFirstName !== null && empty($existing['first_name'])) {
                $updates['first_name'] = $normalizedFirstName;
            }
            if ($normalizedLastName !== null && empty($existing['last_name'])) {
                $updates['last_name'] = $normalizedLastName;
            }
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
            'first_name' => $parsedFirstName ?? $this->firstNameFromInput($name),
            'last_name' => $parsedLastName,
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

        $rawName = $this->normalizeName($input['name'] ?? null);
        [$parsedFirstName, $parsedLastName] = $this->splitNameParts($rawName);
        $firstName = $this->normalizeOptionalString($input['first_name'] ?? null)
            ?? $parsedFirstName
            ?? $this->firstNameFromInput($rawName ?? '')
            ?? 'Unknown';
        $lastName = $this->normalizeOptionalString($input['last_name'] ?? null)
            ?? $parsedLastName
            ?? 'Unknown';
        $name = $this->buildDisplayName($firstName, $lastName)
            ?? $rawName
            ?? $this->fallbackNameFromEmail($email);
        $phone = $this->normalizeOptionalString($input['phone'] ?? null);
        $notes = $this->normalizeOptionalString($input['notes'] ?? null);
        $clientType = $this->normalizeClientType($input['client_type'] ?? null);
        $startedAt = $this->normalizeDateTimeOrNull($input['started_at'] ?? null) ?? AppTime::now();
        $permissionStatus = $this->normalizePermissionStatus($input['photo_permission_status'] ?? null);
        $permissionRequestedAt = $this->normalizeDateTimeOrNull($input['photo_permission_requested_at'] ?? null);
        $permissionGrantedAt = $this->normalizeDateTimeOrNull($input['photo_permission_granted_at'] ?? null);

        if ($permissionStatus === 'requested' && $permissionRequestedAt === null) {
            $permissionRequestedAt = AppTime::now();
        }
        if ($permissionStatus === 'granted' && $permissionGrantedAt === null) {
            $permissionGrantedAt = AppTime::now();
        }

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
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'notes' => $notes,
                'client_type_key' => $clientType,
                'started_at' => $startedAt,
                'photo_permission_status' => $permissionStatus,
                'photo_permission_requested_at' => $permissionRequestedAt,
                'photo_permission_granted_at' => $permissionGrantedAt,
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
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'notes' => $notes,
                'client_type_key' => $clientType,
                'started_at' => $startedAt,
                'photo_permission_status' => $permissionStatus,
                'photo_permission_requested_at' => $permissionRequestedAt,
                'photo_permission_granted_at' => $permissionGrantedAt,
            ]);
            $existing = $this->repo->findById((int)$existing['id']) ?? $existing;
            return $this->normalizeRow($existing);
        }

        $createdId = $this->repo->create([
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
            'client_type_key' => $clientType,
            'started_at' => $startedAt,
            'photo_permission_status' => $permissionStatus,
            'photo_permission_requested_at' => $permissionRequestedAt,
            'photo_permission_granted_at' => $permissionGrantedAt,
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

    private function normalizePermissionStatus(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['unknown', 'requested', 'granted', 'declined'], true)
            ? $normalized
            : 'unknown';
    }

    private function normalizeClientType(mixed $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return 'homeowner';
        }

        if (!$this->pdo) {
            return $normalized;
        }

        $repo = new PdoClientTypeRepository($this->pdo);
        if ($repo->isAllowedKey($normalized)) {
            return $normalized;
        }

        throw new InvalidArgumentException('client type is not allowed');
    }

    private function normalizeDateTimeOrNull(mixed $value): ?string
    {
        return AppTime::normalizeDateTimeString((string)$value);
    }

    private function fallbackNameFromEmail(string $email): string
    {
        $local = explode('@', $email)[0] ?? 'Client';
        $local = str_replace(['.', '_', '-'], ' ', $local);
        $local = trim($local);
        return $local !== '' ? ucwords($local) : 'Client';
    }

    private function firstNameFromInput(?string $name): ?string
    {
        $normalized = trim((string)$name);
        if ($normalized === '') return null;
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $first = trim((string)($parts[0] ?? ''));
        return $first !== '' ? $first : null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitNameParts(?string $name): array
    {
        $normalized = trim((string)$name);
        if ($normalized === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $first = trim((string)($parts[0] ?? ''));
        $last = count($parts) > 1 ? trim((string)$parts[count($parts) - 1]) : '';
        return [
            $first !== '' ? $first : null,
            $last !== '' ? $last : null,
        ];
    }

    private function buildDisplayName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(implode(' ', array_values(array_filter([$firstName, $lastName], static fn(?string $value): bool => $value !== null && $value !== ''))));
        return $name !== '' ? $name : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, name:string, email:string, phone:?string, notes:?string}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $this->buildDisplayName(
                isset($row['first_name']) ? (string)$row['first_name'] : null,
                isset($row['last_name']) ? (string)$row['last_name'] : null,
            ) ?? (string)($row['name'] ?? ''),
            'first_name' => isset($row['first_name']) ? (string)$row['first_name'] : null,
            'last_name' => isset($row['last_name']) ? (string)$row['last_name'] : null,
            'email' => (string)($row['email'] ?? ''),
            'phone' => isset($row['phone']) ? (string)$row['phone'] : null,
            'notes' => isset($row['notes']) ? (string)$row['notes'] : null,
            'client_type' => isset($row['client_type']) && $row['client_type'] !== null
                ? (string)$row['client_type']
                : (isset($row['client_type_key']) ? (string)$row['client_type_key'] : 'homeowner'),
            'started_at' => isset($row['started_at']) ? (string)$row['started_at'] : null,
            'photo_permission_status' => (string)($row['photo_permission_status'] ?? 'unknown'),
            'photo_permission_requested_at' => isset($row['photo_permission_requested_at']) ? (string)$row['photo_permission_requested_at'] : null,
            'photo_permission_granted_at' => isset($row['photo_permission_granted_at']) ? (string)$row['photo_permission_granted_at'] : null,
        ];
    }
}
