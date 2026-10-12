<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;
use RuntimeException;
use Throwable;

/** Persists raw color-to-category assignments, without touching colors caches. */
final class PdoColorClassificationRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Atomically replace assignments for one color. Reuses an enclosing transaction if present.
     * The caller must verify that the color ID is valid.
     * @param list<int> $categoryIds
     */
    public function replaceForColor(int $colorId, array $categoryIds): void
    {
        if ($colorId <= 0) {
            throw new RuntimeException('Invalid color ID.');
        }
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        foreach ($categoryIds as $categoryId) {
            if ($categoryId <= 0) {
                throw new RuntimeException('Invalid category ID.');
            }
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = 'colors_assignments';
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $delete = $this->pdo->prepare('DELETE FROM color_category WHERE color_id = :id');
            $delete->execute([':id' => $colorId]);
            if ($categoryIds !== []) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO color_category (color_id, category_id) VALUES (:color_id, :category_id)'
                );
                foreach ($categoryIds as $categoryId) {
                    $insert->execute([':color_id' => $colorId, ':category_id' => $categoryId]);
                }
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
    }

    /**
     * Efficient batched writes, used ONLY inside the manager's full transaction.
     * @param array<int,list<int>> $assignments
     */
    public function replaceBatch(array $assignments): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('replaceBatch requires an enclosing transaction.');
        }
        $delete = $this->pdo->prepare('DELETE FROM color_category WHERE color_id = ?');
        $insert = $this->pdo->prepare('INSERT INTO color_category (color_id, category_id) VALUES (?, ?)');
        foreach ($assignments as $colorId => $categoryIds) {
            if ((int)$colorId <= 0) {
                throw new RuntimeException('Invalid color ID.');
            }
            $delete->execute([(int)$colorId]);
            foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
                if ($categoryId <= 0) {
                    throw new RuntimeException('Invalid category ID.');
                }
                $insert->execute([(int)$colorId, $categoryId]);
            }
        }
    }

    public function assignmentCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM color_category')->fetchColumn();
    }
}
