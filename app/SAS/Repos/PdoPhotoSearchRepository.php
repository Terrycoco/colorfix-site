<?php
declare(strict_types=1);

namespace App\SAS\Repos;

use PDO;
use PDOStatement;

final class PdoPhotoSearchRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * One result per eligible photo. Search terms are ANDed; different terms
     * may match different palette colors on the same photo.
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
        $categories = $this->categoryLookup();

        $where = [
            'COALESCE(p.is_inactive, 0) = 0',
            // Exclude the standalone before tag, not "before-and-after".
            "(p.tags IS NULL OR p.tags NOT REGEXP '(^|[,;[:space:]])before([,;[:space:]]|$)')",
        ];
        $params = [];

        foreach ($words as $i => $word) {
            $normalized = $this->categoryKey($word);
            $matches = $categories[$normalized] ?? [];
            $parts = [];

            if ($matches !== []) {
                // Known category search: photo titles/tags and paint names must
                // NOT override the classification requirement.
                foreach ($matches as $j => $category) {
                    $key = ':category' . $i . '_' . $j;
                    $column = match ($category['type']) {
                        'hue' => 'hue_cats',
                        'neutral' => 'neutral_cats',
                        'lightness' => 'lightness_cats',
                        'chroma' => 'chroma_cats',
                    };
                    $condition = "FIND_IN_SET({$key}, v.{$column}) > 0";
                    if ($category['type'] === 'hue') {
                        // Reject this particular paint color if it also has
                        // any Neutral category; do not reject the whole photo.
                        $condition .= " AND NULLIF(TRIM(v.neutral_cats), '') IS NULL";
                    }
                    $parts[] = '(' . $condition . ')';
                    $params[$key] = $category['name'];
                }
            } else {
                // Free text still searches tags, photo titles, and paint names.
                $like = '%' . addcslashes($word, '\\%_') . '%';
                foreach (['photo_tags', 'photo_title', 'color_name'] as $field) {
                    $key = ':word' . $i . '_' . $field;
                    $parts[] = "v.{$field} LIKE {$key}";
                    $params[$key] = $like;
                }
            }

            $where[] = "EXISTS (
                SELECT 1
                  FROM vw_photo_palette_colors v
                 WHERE v.photo_id = p.photo_library_id
                   AND (" . implode(' OR ', $parts) . ")
            )";
        }

        $predicate = implode("\n AND ", $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM photo_library p WHERE {$predicate}");
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
            $item['palette_id'] = $item['palette_id'] !== null ? (int)$item['palette_id'] : null;
        }
        unset($item);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Read category vocabulary, not color/category assignments. Singular
     * inputs such as "green" match the stored name "Greens".
     *
     * @return array<string, list<array{name:string,type:string}>>
     */
    private function categoryLookup(): array
    {
        $rows = $this->pdo->query(
            "SELECT name, type FROM category_definitions
              WHERE type IN ('hue', 'neutral', 'lightness', 'chroma')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $lookup = [];
        foreach ($rows as $row) {
            $name = trim((string)$row['name']);
            $type = (string)$row['type'];
            $lookup[$this->categoryKey($name)][] = ['name' => $name, 'type' => $type];
        }
        return $lookup;
    }

    private function categoryKey(string $value): string
    {
        $value = strtolower(trim($value));
        // Category names are plural (Greens, Yellows, Whites, etc.).
        return str_ends_with($value, 's') ? substr($value, 0, -1) : $value;
    }

    /** @param array<string,string> $params */
    private function bindSearchValues(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
}
