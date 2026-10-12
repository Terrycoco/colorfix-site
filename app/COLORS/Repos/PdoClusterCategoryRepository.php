<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;
use RuntimeException;

/** Rebuilds cluster category summaries from mv_color_categories, not colors' cached category columns. */
final class PdoClusterCategoryRepository
{
    private const FIELDS = ['hue_cats', 'neutral_cats', 'lightness_cats', 'chroma_cats'];
    private const MAX_LENGTH = 64; // Existing clusters column widths; no schema change.

    public function __construct(private PDO $pdo) {}

    /**
     * Caller owns transaction. Validates all cluster strings before issuing any UPDATE.
     * Colors with no MV record contribute no categories. Empty clusters get NULLs.
     * @return array{clusters:int,clusters_with_colors:int,clusters_with_categories:int}
     */
    public function refreshWithinTransaction(): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Cluster category refresh requires an active transaction.');
        }

        // Read every cluster, so an orphaned/empty cluster cannot retain stale categories.
        $clusters = [];
        $ids = $this->pdo->query('SELECT id FROM clusters ORDER BY id');
        while (($id = $ids->fetchColumn()) !== false) {
            $clusters[(int)$id] = ['colors' => 0, 'sets' => [[], [], [], []]];
        }
        $ids->closeCursor();

        $stmt = $this->pdo->query(<<<'SQL'
SELECT c.cluster_id,
       mv.hue_cats, mv.neutral_cats, mv.lightness_cats, mv.chroma_cats
  FROM colors c
  LEFT JOIN mv_color_categories mv ON mv.color_id = c.id
 WHERE c.cluster_id IS NOT NULL
 ORDER BY c.cluster_id, c.id
SQL);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['cluster_id'];
            if (!isset($clusters[$id])) {
                throw new RuntimeException("Color references missing cluster {$id}.");
            }
            $clusters[$id]['colors']++;
            foreach (self::FIELDS as $i => $field) {
                if ($row[$field] === null || $row[$field] === '') continue;
                foreach (explode(',', (string)$row[$field]) as $rawName) {
                    $name = trim($rawName);
                    if ($name !== '') $clusters[$id]['sets'][$i][$name] = true;
                }
            }
        }
        $stmt->closeCursor();

        $prepared = [];
        $withColors = 0;
        $withCategories = 0;
        foreach ($clusters as $id => $cluster) {
            if ($cluster['colors'] > 0) $withColors++;
            $values = [];
            foreach ($cluster['sets'] as $i => $set) {
                $names = array_keys($set);
                sort($names, SORT_STRING);
                $value = $names === [] ? null : implode(',', $names);
                if ($value !== null && strlen($value) > self::MAX_LENGTH) {
                    throw new RuntimeException("Cluster {$id} " . self::FIELDS[$i] . ' exceeds 64 bytes; no cluster rows updated.');
                }
                $values[] = $value;
            }
            if (count(array_filter($values, static fn($v) => $v !== null)) > 0) $withCategories++;
            $prepared[$id] = $values;
        }

        $update = $this->pdo->prepare(<<<'SQL'
UPDATE clusters
   SET hue_cats = ?, neutral_cats = ?, lightness_cats = ?, chroma_cats = ?
 WHERE id = ?
SQL);
        foreach ($prepared as $id => $values) {
            $update->execute([...$values, $id]);
        }
        $actual = (int)$this->pdo->query('SELECT COUNT(*) FROM clusters')->fetchColumn();
        if ($actual !== count($prepared)) {
            throw new RuntimeException('Cluster count changed during refresh; rolling back.');
        }
        return [
            'clusters' => $actual,
            'clusters_with_colors' => $withColors,
            'clusters_with_categories' => $withCategories,
        ];
    }
}
