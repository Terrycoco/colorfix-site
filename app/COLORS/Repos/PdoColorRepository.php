<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;

/** Read-only access to the source HCL values; classification never writes colors. */
final class PdoColorRepository
{
    public function __construct(private PDO $pdo) {}

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM colors')->fetchColumn();
    }

    /** @return list<array{id:mixed,hcl_h:mixed,hcl_c:mixed,hcl_l:mixed}> */
    public function batchAfterId(int $lastId, int $limit = 2000): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, hcl_h, hcl_c, hcl_l FROM colors WHERE id > :last_id ORDER BY id ASC LIMIT :lim'
        );
        $stmt->bindValue(':last_id', max(0, $lastId), PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min(10000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findHcl(int $colorId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, hcl_h, hcl_c, hcl_l FROM colors WHERE id = :id');
        $stmt->execute([':id' => $colorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
