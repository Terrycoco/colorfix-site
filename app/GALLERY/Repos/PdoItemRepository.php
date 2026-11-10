<?php
declare(strict_types=1);

namespace App\GALLERY\Repos;

use PDO;

/** Editorial Gallery items only; deleting an item never deletes its image asset. */
final class PdoItemRepository
{
    public function __construct(private PDO $pdo) {}

    public function deleteById(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM items WHERE id = ?');
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }
}
