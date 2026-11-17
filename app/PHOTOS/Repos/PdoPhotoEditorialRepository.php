<?php
declare(strict_types=1);

namespace App\PHOTOS\Repos;

use PDO;

/** Only the three PHOTOS-owned editorial fields; canonical identity is the photo ID. */
final class PdoPhotoEditorialRepository
{
    public function __construct(private PDO $pdo) {}

    public function find(int $photoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT photo_library_id, design_notes, refined_commentary, photo_description, created_at, updated_at '
            . 'FROM photo_editorial WHERE photo_library_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $photoId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Bulk read for authorized internal consumers; no extra query per slide. */
    public function findMany(array $photoIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $photoIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT photo_library_id, design_notes, refined_commentary, photo_description '
                . "FROM photo_editorial WHERE photo_library_id IN ($marks)"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int)$row['photo_library_id']] = $row;
            }
        }
        return $result;
    }

    /** Upsert only after a caller has verified the photo exists. */
    public function save(int $photoId, ?string $notes, ?string $commentary, ?string $description): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO photo_editorial (photo_library_id, design_notes, refined_commentary, photo_description) '
            . 'VALUES (:id, :notes, :commentary, :description) '
            . 'ON DUPLICATE KEY UPDATE design_notes = VALUES(design_notes), '
            . 'refined_commentary = VALUES(refined_commentary), photo_description = VALUES(photo_description)'
        );
        $stmt->execute([
            'id' => $photoId,
            'notes' => $notes,
            'commentary' => $commentary,
            'description' => $description,
        ]);
    }
}
