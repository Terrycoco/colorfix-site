<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoAudienceTypeRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function listActive(): array
    {
        $sql = <<<SQL
            SELECT audience_type_id, `key`, label, sort_order, is_active
            FROM audience_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, label ASC, audience_type_id ASC
        SQL;

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'audience_type_id' => (int)$row['audience_type_id'],
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

        $stmt = $this->pdo->prepare('SELECT 1 FROM audience_types WHERE `key` = :key AND is_active = 1 LIMIT 1');
        $stmt->execute(['key' => $normalized]);
        return (bool)$stmt->fetchColumn();
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
}
