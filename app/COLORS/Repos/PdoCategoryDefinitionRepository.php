<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;

/** Category definitions remain the authoritative HCL rules. */
final class PdoCategoryDefinitionRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function activeByType(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, name, type, hue_min, hue_max, chroma_min, chroma_max, '
            . 'light_min, light_max, active, calc_only, sort_order '
            . 'FROM category_definitions WHERE active = 1 ORDER BY type, sort_order, name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['type']][] = $row;
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function visibleByTypes(array $types): array
    {
        $types = array_values(array_unique(array_intersect($types, ['hue', 'neutral', 'lightness', 'chroma'])));
        if ($types === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name, type, description, hue_min, hue_max, chroma_min, chroma_max, "
            . "light_min, light_max, wheel_text_color, sort_order FROM category_definitions "
            . "WHERE active = 1 AND calc_only = 0 AND type IN ({$placeholders}) "
            . 'ORDER BY type, sort_order, name'
        );
        $stmt->execute($types);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,int> */
    public function hueSinglesOrder(): array
    {
        $rows = $this->pdo->query(
            "SELECT combo_key, sort_order FROM hue_display WHERE combo_key NOT LIKE '%,%'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $key = trim((string) $row['combo_key']);
            if ($key !== '') {
                $map[$key] = (int) $row['sort_order'];
            }
        }
        return $map;
    }

    /** @return array<string,array{display_name:string,sort_order:int}> */
    public function hueDisplayMap(): array
    {
        $rows = $this->pdo->query('SELECT combo_key, display_name, sort_order FROM hue_display')
            ->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['combo_key']] = [
                'display_name' => (string) $row['display_name'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }
        return $map;
    }
}
