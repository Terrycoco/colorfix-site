<?php
require_once __DIR__ . '/../db.php';

function logFilterError($msg) {
    $logFile = __DIR__ . '/../filter-errors.log';
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

/**
 * Builds a dynamic WHERE clause from searchFilters.
 * @param array $filters - e.g. ['brand' => ['bm', 'de'], 'interior' => 1]
 * @param string $sql - the original SQL string (for table checks)
 * @return array [final_sql, named_params_array]
 */
function buildWhereClauseFromFilters($filters, $sql) {
    global $pdo;

    $clauses = [];
    $params = [];

    // Peel off any trailing ORDER BY / LIMIT / GROUP BY clauses
    $pattern = '/\b(ORDER\s+BY|GROUP\s+BY|LIMIT)\b/i';
    $trailingSql = '';
    $split = preg_split($pattern, $sql, 2, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    if (count($split) > 1) {
        $mainSql = trim($split[0]);
        $trailingSql = trim($split[1] . (isset($split[2]) ? ' ' . $split[2] : ''));
    } else {
        $mainSql = $sql;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM filter_definitions");
        $stmt->execute();
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($definitions as $def) {
            $field = $def['fieldname'];
            $table = $def['tablename'];
            $qualified = "$table.$field";

            if (empty($filters[$field])) continue;
            if (stripos($mainSql, $table) === false) continue;

            $value = $filters[$field];

            if (is_array($value) && count($value) > 0) {
                $placeholders = [];
                foreach ($value as $i => $val) {
                    $ph = ":{$field}_{$i}";
                    $placeholders[] = $ph;
                    $params[substr($ph, 1)] = $val; // Strip colon for binding
                }
                $clauses[] = "$qualified IN (" . implode(", ", $placeholders) . ")";
            } else {
                $ph = ":$field";
                $clauses[] = "$qualified = $ph";
                $params[substr($ph, 1)] = $value; // Strip colon for binding
            }
        }

    } catch (PDOException $e) {
        logFilterError("PDOException in buildWhereClauseFromFilters: " . $e->getMessage());
    } catch (Exception $e) {
        logFilterError("General exception in buildWhereClauseFromFilters: " . $e->getMessage());
    }

    if (!empty($clauses)) {
        if (stripos($mainSql, 'WHERE') !== false) {
            $mainSql .= ' AND ' . implode(' AND ', $clauses);
        } else {
            $mainSql .= ' WHERE ' . implode(' AND ', $clauses);
        }
    }

    $finalSql = trim($mainSql . ' ' . $trailingSql);
    return [$finalSql, $params];
}
