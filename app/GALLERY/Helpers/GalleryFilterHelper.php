<?php

declare(strict_types=1);

namespace App\GALLERY\Helpers;

use PDO;

/** Gallery-local adaptation of the original filter-helpers.php. */
final class GalleryFilterHelper
{
    /** @return array{0:string,1:array<string,mixed>} */
    public static function apply(PDO $pdo, array $filters, string $sql): array
    {
        $clauses = [];
        $params = [];

        // Preserve the original separation of WHERE from ordering/limit.
        $split = preg_split('/\b(ORDER\s+BY|GROUP\s+BY|LIMIT)\b/i', $sql, 2, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (count($split) > 1) {
            $mainSql = trim($split[0]);
            $trailingSql = trim($split[1] . (isset($split[2]) ? ' ' . $split[2] : ''));
        } else {
            $mainSql = $sql;
            $trailingSql = '';
        }

        $stmt = $pdo->query('SELECT * FROM filter_definitions');
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($definitions as $def) {
            $field = (string) $def['fieldname'];
            $table = (string) $def['tablename'];
            if (empty($filters[$field])) continue;

            // Gallery sources expose the same swatch columns. Keep legacy
            // filter_definitions while qualifying columns with the actual source.
            $source = $table;
            if ($table === 'swatch_view') {
                if (preg_match('/\b(?:FROM|JOIN)\s+`?vw_gallery`?\b/i', $mainSql)) {
                    $source = 'vw_gallery';
                } elseif (preg_match('/\b(?:FROM|JOIN)\s+`?mv_swatch_view`?\b/i', $mainSql)) {
                    $source = 'mv_swatch_view';
                }
            }
            if (!preg_match('/\b(?:FROM|JOIN)\s+`?' . preg_quote($source, '/') . '`?\b/i', $mainSql)) {
                continue;
            }

            $qualified = $source . '.' . $field;
            $value = $filters[$field];
            if (is_array($value) && count($value) > 0) {
                $placeholders = [];
                foreach ($value as $i => $val) {
                    $ph = ':' . $field . '_' . $i;
                    $placeholders[] = $ph;
                    $params[substr($ph, 1)] = $val;
                }
                $clauses[] = $qualified . ' IN (' . implode(', ', $placeholders) . ')';
            } else {
                $ph = ':' . $field;
                $clauses[] = $qualified . ' = ' . $ph;
                $params[$field] = $value;
            }
        }

        if ($clauses) {
            if (stripos($mainSql, 'WHERE') !== false) {
                $parts = preg_split('/\bWHERE\b/i', $mainSql, 2, PREG_SPLIT_DELIM_CAPTURE);
                if (count($parts) >= 2) {
                    $mainSql = rtrim($parts[0]) . ' WHERE (' . trim($parts[count($parts) - 1]) . ') AND ' . implode(' AND ', $clauses);
                } else {
                    $mainSql .= ' AND ' . implode(' AND ', $clauses);
                }
            } else {
                $mainSql .= ' WHERE ' . implode(' AND ', $clauses);
            }
        }
        return [trim($mainSql . ' ' . $trailingSql), $params];
    }
}
