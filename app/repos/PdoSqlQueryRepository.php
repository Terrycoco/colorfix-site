<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoSqlQueryRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getQueryRow(int $queryId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sql_queries WHERE query_id = ?");
        $stmt->execute([$queryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Includes global inserts (query_id = 17) and only active items.
     * Ordered by insert_position if present, then id as a stable tiebreaker.
     */
    public function getItemsFor(int $queryId): array
    {
        $sql = "
            SELECT *
            FROM items
            WHERE (query_id = :qid OR query_id = 17)
              AND is_active = 1
            ORDER BY
              COALESCE(insert_position, 999999) ASC,
              id ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['qid' => $queryId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            if (!isset($r['item_type']) || $r['item_type'] === null || $r['item_type'] === '') {
                $r['item_type'] = 'unknown';
            }
        }
        return $rows;
    }


public function runStoredQuery(int $queryId, array $params = [], array $filters = []): array
{
    // 1) load the SQL
    $row = $this->getQueryRow($queryId);
    if (!$row) throw new \RuntimeException("Query $queryId not found");
    $sql = (string)$row['query'];

    // 2) allowlist params you actually bind
    //    your URL: /results/18?name=%25viridian%25&code=%25viridian%25  → name/code LIKE patterns
    $allowed = [
        'name' => PDO::PARAM_STR,
        'code' => PDO::PARAM_STR,
        // extend later: 'brand' => PDO::PARAM_STR, etc.
    ];

    // 3) build binds
    $binds = [];
    foreach ($allowed as $k => $type) {
        if (array_key_exists($k, $params) && $params[$k] !== null) {
            $binds[$k] = [$params[$k], $type];
        }
    }

    // 4) prepare & bind
    $stmt = $this->pdo->prepare($sql);
    foreach ($binds as $k => [$val, $type]) {
        $stmt->bindValue(':' . $k, $val, $type);
    }

    // 5) execute and return rows
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}





}
