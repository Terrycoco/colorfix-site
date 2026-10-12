<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;
use RuntimeException;
use Throwable;

/** Maintains the existing mv_color_categories table; never alters its schema. */
final class PdoMaterializedCategoriesRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Regenerate category lookup in a transaction. Readers see the old committed
     * rows until commit (InnoDB); a failed refresh rolls back. No TRUNCATE/DDL.
     * Call ONLY after the full raw assignment recalc succeeded.
     *
     * @return array{rows:int, expected:int}
     */
    public function refresh(): array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Standalone MV refresh requires its own transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $this->refreshWithinTransaction();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** The caller owns commit/rollback; MV and assignments change atomically. */
    public function refreshWithinTransaction(): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('MV refresh requires an active transaction.');
        }
        $expected = (int) $this->pdo->query(
            'SELECT COUNT(DISTINCT cc.color_id) FROM color_category cc '
            . 'JOIN category_definitions cd ON cd.id = cc.category_id'
        )->fetchColumn();
        if ($expected === 0) {
            throw new RuntimeException('Refusing to empty MV: no source classification rows.');
        }
        $this->pdo->exec('DELETE FROM mv_color_categories');
        $this->pdo->exec(<<<'SQL'
INSERT INTO mv_color_categories
    (color_id, hue_cats, neutral_cats, lightness_cats, chroma_cats)
SELECT cc.color_id,
       GROUP_CONCAT(DISTINCT CASE WHEN cd.type = 'hue' THEN cd.name END
                    ORDER BY cd.name SEPARATOR ','),
       GROUP_CONCAT(DISTINCT CASE WHEN cd.type = 'neutral' THEN cd.name END
                    ORDER BY cd.name SEPARATOR ','),
       GROUP_CONCAT(DISTINCT CASE WHEN cd.type = 'lightness' THEN cd.name END
                    ORDER BY cd.name SEPARATOR ','),
       GROUP_CONCAT(DISTINCT CASE WHEN cd.type = 'chroma' THEN cd.name END
                    ORDER BY cd.name SEPARATOR ',')
  FROM color_category cc
  JOIN category_definitions cd ON cd.id = cc.category_id
 GROUP BY cc.color_id
SQL);
        $actual = (int) $this->pdo->query('SELECT COUNT(*) FROM mv_color_categories')->fetchColumn();
        if ($actual !== $expected) {
            throw new RuntimeException("MV row validation failed: expected {$expected}, found {$actual}.");
        }
        return ['rows' => $actual, 'expected' => $expected];
    }

    public function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM mv_color_categories')->fetchColumn();
    }
}
