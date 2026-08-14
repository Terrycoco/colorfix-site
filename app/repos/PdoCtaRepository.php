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
  c.`onclick`,
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

    public function findGroupByKey(string $key): ?array
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, `key`, label, description
               FROM cta_groups
              WHERE `key` = :key
              LIMIT 1'
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
  c.`onclick`,
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

    public function listGroupItems(int $ctaGroupId): array
    {
        $sql = <<<SQL
          SELECT
            cgi.cta_group_item_id,
            cgi.cta_group_id,
            cgi.cta_id,
            cgi.order_index,
            c.label,
            c.params,
            c.`onclick`,
            c.is_active,
            t.action_key AS type_action_key,
            t.label AS type_label
          FROM cta_group_items cgi
          JOIN ctas c ON c.cta_id = cgi.cta_id
          JOIN cta_types t ON t.cta_type_id = c.cta_type_id
          WHERE cgi.cta_group_id = :group_id
          ORDER BY cgi.order_index ASC, cgi.cta_group_item_id ASC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['group_id' => $ctaGroupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listAdmin(): array
    {
        $sql = <<<SQL
          SELECT
            c.cta_id,
            c.cta_type_id,
            c.label,
            c.params,
            c.`onclick`,
            c.is_active,
            c.created_at,
            c.updated_at,
            t.action_key AS type_action_key,
            t.label AS type_label
          FROM ctas c
          LEFT JOIN cta_types t ON t.cta_type_id = c.cta_type_id
          ORDER BY c.cta_id DESC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveAdmin(array $payload, PdoTrackingLookupRepository $trackingRepo): int
    {
        $id = isset($payload['cta_id']) ? (int)$payload['cta_id'] : 0;
        $ctaTypeId = isset($payload['cta_type_id']) ? (int)$payload['cta_type_id'] : 0;
        $label = trim((string)($payload['label'] ?? ''));
        $params = $payload['params'] ?? null;
        $onclick = strtolower(trim((string)($payload['onclick'] ?? '')));
        $isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

        if ($ctaTypeId <= 0) {
            throw new \InvalidArgumentException('cta_type_id required');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('label required');
        }
        if ($onclick !== '' && preg_match('/^[a-z0-9_-]{1,100}$/', $onclick) !== 1) {
            throw new \InvalidArgumentException('onclick must be a tracking event key');
        }
        if ($onclick !== '' && !$trackingRepo->isActiveEventKey($onclick)) {
            throw new \InvalidArgumentException('onclick event is not active');
        }

        if ($id > 0) {
            $sql = <<<SQL
              UPDATE ctas
              SET cta_type_id = :cta_type_id,
                  label = :label,
                  params = :params,
                  `onclick` = :onclick,
                  is_active = :is_active
              WHERE cta_id = :cta_id
            SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'cta_id' => $id,
                'cta_type_id' => $ctaTypeId,
                'label' => $label,
                'params' => $params,
                'onclick' => $onclick !== '' ? $onclick : null,
                'is_active' => $isActive,
            ]);
            return $id;
        }

        $sql = <<<SQL
          INSERT INTO ctas (cta_type_id, label, params, `onclick`, is_active)
          VALUES (:cta_type_id, :label, :params, :onclick, :is_active)
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'cta_type_id' => $ctaTypeId,
            'label' => $label,
            'params' => $params,
            'onclick' => $onclick !== '' ? $onclick : null,
            'is_active' => $isActive,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
