<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoViewerRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a viewer if missing; otherwise update last_seen_at and increment visit_count.
     */
    public function upsertViewer(string $viewerId): void
    {
        $sql = "
            INSERT INTO viewers (viewer_id, created_at, last_seen_at, visit_count)
            VALUES (:vid, NOW(), NOW(), 1)
            ON DUPLICATE KEY UPDATE
              last_seen_at = NOW(),
              visit_count = visit_count + 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':vid' => $viewerId]);
    }

    /**
     * Fetch a viewer by id or return null.
     */
    public function findById(string $viewerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM viewers WHERE viewer_id = :vid LIMIT 1");
        $stmt->execute([':vid' => $viewerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
