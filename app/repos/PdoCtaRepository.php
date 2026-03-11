<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoCtaRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getByGroupId(int $ctaGroupId): array
    {
        $sql = <<<SQL
SELECT
  c.cta_id     AS cta_id,
  c.label,
  c.params,
  t.action_key
FROM cta_group_items gi
JOIN ctas c
  ON c.cta_id = gi.cta_id
JOIN cta_types t
  ON t.cta_type_id = c.cta_type_id
WHERE gi.cta_group_id = :group_id
  AND c.is_active = 1
  AND t.is_active = 1
ORDER BY gi.order_index ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'group_id' => $ctaGroupId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByIds(array $ctaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ctaIds))));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $orderBy = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<SQL
SELECT
  c.cta_id     AS cta_id,
  c.label,
  c.params,
  t.action_key
FROM ctas c
JOIN cta_types t
  ON t.cta_type_id = c.cta_type_id
WHERE c.cta_id IN ({$placeholders})
  AND c.is_active = 1
  AND t.is_active = 1
ORDER BY FIELD(c.cta_id, {$orderBy})
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($ids, $ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
