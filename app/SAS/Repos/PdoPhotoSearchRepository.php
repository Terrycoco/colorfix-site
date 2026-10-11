<?php
declare(strict_types=1);

namespace App\SAS\Repos;

use PDO;

final class PdoPhotoSearchRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Search the existing vw_photo_palette_colors view.
     *
     * Multiple words must each match somewhere in the photo's metadata or
     * palette colors. One row per photo, even when its palette has many colors.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function search(string $query, int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $offset = ($page - 1) * $perPage;

        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, 12);

        // Use photo_library for one canonical row per photo; the view supplies
        // its metadata and member-color search terms.
        $where = [
            'COALESCE(p.is_inactive, 0) = 0',
            // Tags are typically comma-separated. Exclude the distinct "before"
            // tag; do not accidentally exclude "before-and-after".
            "(p.tags IS NULL OR p.tags NOT REGEXP '(^|[,;[:space:]])before([,;[:space:]]|$)')",
        ];
        $params = [];

        foreach ($words as $i => $word) {
            // Different placeholders per LIKE occurrence: PDO native prepares
            // cannot portably reuse one named parameter multiple times.
            $like = '%' . addcslashes($word, '\\%_') . '%';
            $parts = [];
            foreach (['photo_tags', 'photo_title', 'color_name', 'color_families'] as $field) {
                $key = ':word' . $i . '_' . $field;
                $parts[] = "v.{$field} LIKE {$key}";
                $params[$key] = $like;
            }
            $where[] = "EXISTS (
                SELECT 1
                  FROM vw_photo_palette_colors v
                 WHERE v.photo_id = p.photo_library_id
                   AND (" . implode(' OR ', $parts) . ")
            )";
        }

        $predicate = implode("\n AND ", $where);

        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM photo_library p WHERE {$predicate}"
        );
        $this->bindSearchValues($count, $params);
        $count->execute();
        $total = (int)$count->fetchColumn();

        $statement = $this->pdo->prepare(
            "SELECT p.photo_library_id AS photo_id,
                    p.rel_path,
                    p.title AS photo_title,
                    p.tags AS photo_tags,
                    (SELECT MIN(vp.palette_id)
                       FROM vw_photo_palette_colors vp
                      WHERE vp.photo_id = p.photo_library_id) AS palette_id
               FROM photo_library p
              WHERE {$predicate}
              ORDER BY p.photo_library_id DESC
              LIMIT :limit OFFSET :offset"
        );
        $this->bindSearchValues($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['photo_id'] = (int)$item['photo_id'];
            $item['palette_id'] = $item['palette_id'] !== null
                ? (int)$item['palette_id'] : null;
        }
        unset($item);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,string> $params */
    private function bindSearchValues(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
}
