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
}
