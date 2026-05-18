<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoClientTypeRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function listActive(): array
    {
        $sql = <<<SQL
            SELECT client_type_id, `key`, label, sort_order, is_active
            FROM client_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, label ASC, client_type_id ASC
        SQL;

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'client_type_id' => (int)$row['client_type_id'],
                'key' => (string)$row['key'],
                'label' => (string)$row['label'],
                'sort_order' => (int)$row['sort_order'],
                'is_active' => (int)$row['is_active'] === 1,
            ];
        }, $rows);
    }

    public function isAllowedKey(?string $key): bool
    {
        $normalized = strtolower(trim((string)$key));
        if ($normalized === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM client_types WHERE `key` = :key AND is_active = 1 LIMIT 1');
        $stmt->execute([':key' => $normalized]);
        return (bool)$stmt->fetchColumn();
    }

    public function create(string $label): array
    {
        $normalizedLabel = trim($label);
        if ($normalizedLabel === '') {
          throw new \InvalidArgumentException('Label required');
        }

        $key = $this->slugify($normalizedLabel);
        if ($key === '') {
          throw new \InvalidArgumentException('A valid key could not be generated from that label');
        }

        $existing = $this->findByKey($key);
        if ($existing) {
          throw new \InvalidArgumentException('That client type already exists');
        }

        $nextSort = (int)($this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM client_types')->fetchColumn() ?: 10);
        $stmt = $this->pdo->prepare(
            'INSERT INTO client_types (`key`, label, sort_order, is_active) VALUES (:key, :label, :sort_order, 1)'
        );
        $stmt->execute([
            ':key' => $key,
            ':label' => $normalizedLabel,
            ':sort_order' => $nextSort,
        ]);

        return $this->findByKey($key) ?? [
            'client_type_id' => (int)$this->pdo->lastInsertId(),
            'key' => $key,
            'label' => $normalizedLabel,
            'sort_order' => $nextSort,
            'is_active' => true,
        ];
    }

    public function getFallbackKey(array $preferredKeys = []): ?string
    {
        foreach ($preferredKeys as $preferredKey) {
            $normalized = strtolower(trim((string)$preferredKey));
            if ($normalized !== '' && $this->isAllowedKey($normalized)) {
                return $normalized;
            }
        }

        $items = $this->listActive();
        if (empty($items)) {
            return null;
        }

        return (string)$items[0]['key'];
    }

    private function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT client_type_id, `key`, label, sort_order, is_active FROM client_types WHERE `key` = :key LIMIT 1'
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'client_type_id' => (int)$row['client_type_id'],
            'key' => (string)$row['key'],
            'label' => (string)$row['label'],
            'sort_order' => (int)$row['sort_order'],
            'is_active' => (int)$row['is_active'] === 1,
        ];
    }

    private function slugify(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }
}

