<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;

final class PdoHexSearchRepository
{
    public function __construct(private PDO $pdo) {}

    public function exact(string $hex): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id FROM colors c WHERE c.is_inactive = 0 AND UPPER(c.hex6) = ?'
            . ' ORDER BY c.id'
        );
        $stmt->execute([$hex]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Use the existing matching pipeline's Lab candidate selection, then refine with Delta E 2000. */
    public function candidates(string $hex, array $lab): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.lab_l, c.lab_a, c.lab_b, '
            . '((c.lab_l-?)*(c.lab_l-?) + (c.lab_a-?)*(c.lab_a-?) + (c.lab_b-?)*(c.lab_b-?)) AS candidate_distance '
            . 'FROM colors c WHERE c.is_inactive = 0 AND UPPER(c.hex6) <> ? '
            . 'AND c.lab_l IS NOT NULL AND c.lab_a IS NOT NULL AND c.lab_b IS NOT NULL'
            . ' ORDER BY candidate_distance, c.id LIMIT 1000'
        );
        $stmt->execute([$lab['L'], $lab['L'], $lab['a'], $lab['a'], $lab['b'], $lab['b'], $hex]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Hydrate only the ranked results through the canonical active swatch source. */
    public function swatches(array $ids): array
    {
        if (!$ids) return [];
        $stmt = $this->pdo->prepare('SELECT * FROM swatch_view WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['id']] = $row;
        return $rows;
    }
}
