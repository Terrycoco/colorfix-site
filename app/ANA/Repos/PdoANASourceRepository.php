<?php
declare(strict_types=1);

namespace App\ANA\Repos;

use App\ANA\Contracts\ANASourceRepositoryInterface;
use PDO;

final class PdoANASourceRepository implements ANASourceRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function isValidSource(string $src): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM src_params WHERE src = :src LIMIT 1');
        $stmt->execute([':src' => $src]);

        return (bool)$stmt->fetchColumn();
    }
}
