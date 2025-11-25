<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_key(?string $value): string {
    return strtolower(trim((string)$value));
}

function normalize_match(?string $value): string {
    return strtolower(str_replace(' ', '', trim((string)$value)));
}

function scope_value(?string $value): string {
    $v = strtolower(trim((string)$value));
    return in_array($v, ['exact','min','max','between'], true) ? $v : 'exact';
}

function buildCategoryMeta(PDO $pdo, array $types): array {
    $meta = [];
    foreach ($types as $type) {
        $meta[$type] = ['entries' => [], 'index' => []];
    }
    $rows = $pdo->query("SELECT id, name, type, COALESCE(sort_order, 0) AS sort_order FROM category_definitions WHERE COALESCE(calc_only,0)=0")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $temp = [];
    foreach ($rows as $row) {
        $type = strtolower($row['type'] ?? '');
        if (!isset($meta[$type])) continue;
        $norm = normalize_key($row['name']);
        if ($norm === '') continue;
        if (!isset($temp[$type][$norm])) {
            $temp[$type][$norm] = [
                'name' => $row['name'],
                'norm' => $norm,
                'order' => (int)($row['sort_order'] ?? 0),
                'ids' => [],
                'match' => normalize_match($row['name']),
            ];
        }
        $temp[$type][$norm]['ids'][] = (int)$row['id'];
    }
    foreach ($temp as $type => $entries) {
        $list = array_values($entries);
        usort($list, function($a, $b) {
            return ($a['order'] <=> $b['order']) ?: strcmp($a['name'], $b['name']);
        });
        $index = [];
        foreach ($list as $idx => $entry) {
            $index[$entry['norm']] = $idx;
        }
        $meta[$type] = [
            'entries' => $list,
            'index' => $index,
        ];
    }
    return $meta;
}

function buildHueMeta(PDO $pdo): array {
    $rows = $pdo->query("SELECT display_name, sort_order FROM hue_display WHERE display_name IS NOT NULL AND display_name <> '' ORDER BY sort_order, display_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $entries = [];
    $index = [];
    foreach ($rows as $row) {
        $name = trim((string)$row['display_name']);
        if ($name === '') continue;
        $norm = normalize_key($name);
        if (isset($index[$norm])) continue;
        $entries[] = [
            'name' => $name,
            'norm' => $norm,
            'order' => (int)($row['sort_order'] ?? 0),
            'match' => normalize_match($name),
        ];
        $index[$norm] = count($entries) - 1;
    }
    return ['entries' => $entries, 'index' => $index];
}

function find_index(array $index, array $candidates): ?int {
    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') continue;
        $norm = normalize_key($candidate);
        if ($norm !== '' && array_key_exists($norm, $index)) return $index[$norm];
    }
    return null;
}

function entries_from_scope(array $meta, string $scope, ?string $exact, ?string $min, ?string $max, bool $wrap = false): array {
    $entries = $meta['entries'] ?? [];
    $index = $meta['index'] ?? [];
    if (!$entries) return [];
    $scope = scope_value($scope);
    if ($scope === 'exact') {
        $idx = find_index($index, [$exact]);
        return $idx === null ? [] : [$entries[$idx]];
    }
    if ($scope === 'min') {
        $idx = find_index($index, [$min, $exact, $max]);
        if ($idx === null) return [];
        return array_slice($entries, $idx);
    }
    if ($scope === 'max') {
        $idx = find_index($index, [$max, $exact, $min]);
        if ($idx === null) return [];
        return array_slice($entries, 0, $idx + 1);
    }
    if ($scope === 'between') {
        $start = find_index($index, [$min]);
        $end = find_index($index, [$max]);
        if ($start === null || $end === null) return [];
        if ($start <= $end) {
            return array_slice($entries, $start, $end - $start + 1);
        }
        if ($wrap) {
            return array_merge(array_slice($entries, $start), array_slice($entries, 0, $end + 1));
        }
        // non-wrap but inverted range, swap to keep inclusive selection
        return array_slice($entries, $end, $start - $end + 1);
    }
    return [];
}

try {
    $supercats = $pdo->query("
        SELECT sc.id AS supercat_id,
               sc.is_active,
               sc.display_name,
               cl.id AS clause_id,
               cl.neutral_name,
               cl.hue_name,
               cl.hue_scope,
               cl.hue_min_name,
               cl.hue_max_name,
               cl.light_name,
               cl.light_scope,
               cl.light_min_name,
               cl.light_max_name,
               cl.chroma_name,
               cl.chroma_scope,
               cl.chroma_min_name,
               cl.chroma_max_name
        FROM supercats sc
        LEFT JOIN supercat_clauses cl ON cl.supercat_id = sc.id
        ORDER BY sc.display_name, cl.id
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$supercats) {
        $pdo->exec("TRUNCATE TABLE color_supercats");
        respond(['ok' => true, 'processed' => 0, 'clauses' => 0]);
    }

    $catMeta = buildCategoryMeta($pdo, ['lightness', 'chroma']);
    $hueMeta = buildHueMeta($pdo);

    $pdo->exec("TRUNCATE TABLE color_supercats");

    $currentSuper = null;
    $colorsForSuper = [];
    $clausesProcessed = 0;
    $superProcessed = 0;

    $insertStmt = $pdo->prepare("INSERT IGNORE INTO color_supercats (color_id, supercat_id) VALUES (:color_id, :supercat_id)");

    foreach ($supercats as $row) {
        $sid = (int)$row['supercat_id'];
        if ($row['is_active'] == 0) continue;

        if ($currentSuper !== null && $sid !== $currentSuper) {
            if ($colorsForSuper) {
                foreach ($colorsForSuper as $colorId => $_) {
                    $insertStmt->execute([':color_id' => $colorId, ':supercat_id' => $currentSuper]);
                }
            }
            $superProcessed++;
            $colorsForSuper = [];
        }
        $currentSuper = $sid;

        $clauseConditions = [];
        $params = [];

        if ($row['neutral_name']) {
            $clauseConditions[] = "FIND_IN_SET(:neutral{$clausesProcessed}, REPLACE(REPLACE(LOWER(c.neutral_cats),' ',''), '/', ',')) > 0";
            $params[":neutral{$clausesProcessed}"] = normalize_match($row['neutral_name']);
        }

        $hueEntries = entries_from_scope(
            $hueMeta,
            $row['hue_scope'] ?? 'exact',
            $row['hue_name'],
            $row['hue_min_name'],
            $row['hue_max_name'],
            true
        );
        if ($hueEntries) {
            $pieces = [];
            foreach ($hueEntries as $idx => $entry) {
                $ph = ":hue{$clausesProcessed}_{$idx}";
                $pieces[] = "FIND_IN_SET($ph, REPLACE(REPLACE(LOWER(c.hue_cats),' ',''), '/', ',')) > 0";
                $params[$ph] = $entry['match'];
            }
            $clauseConditions[] = '(' . implode(' OR ', $pieces) . ')';
        }

        $lightEntries = entries_from_scope(
            $catMeta['lightness'] ?? ['entries'=>[], 'index'=>[]],
            $row['light_scope'] ?? 'exact',
            $row['light_name'],
            $row['light_min_name'],
            $row['light_max_name']
        );
        if ($lightEntries) {
            $ids = [];
            foreach ($lightEntries as $entry) {
                $ids = array_merge($ids, $entry['ids']);
            }
            $ids = array_values(array_unique($ids));
            if ($ids) {
                $placeholders = [];
                foreach ($ids as $idx => $val) {
                    $ph = ":light{$clausesProcessed}_{$idx}";
                    $placeholders[] = $ph;
                    $params[$ph] = $val;
                }
                $clauseConditions[] = "c.light_cat_id IN (" . implode(',', $placeholders) . ")";
            }
        }

        $chromaEntries = entries_from_scope(
            $catMeta['chroma'] ?? ['entries'=>[], 'index'=>[]],
            $row['chroma_scope'] ?? 'exact',
            $row['chroma_name'],
            $row['chroma_min_name'],
            $row['chroma_max_name']
        );
        if ($chromaEntries) {
            $ids = [];
            foreach ($chromaEntries as $entry) {
                $ids = array_merge($ids, $entry['ids']);
            }
            $ids = array_values(array_unique($ids));
            if ($ids) {
                $placeholders = [];
                foreach ($ids as $idx => $val) {
                    $ph = ":chroma{$clausesProcessed}_{$idx}";
                    $placeholders[] = $ph;
                    $params[$ph] = $val;
                }
                $clauseConditions[] = "c.chroma_cat_id IN (" . implode(',', $placeholders) . ")";
            }
        }

        if (!$clauseConditions) continue;

        $sql = "SELECT c.id FROM colors c WHERE " . implode(' AND ', $clauseConditions);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        foreach ($ids as $cid) {
            $colorsForSuper[(int)$cid] = true;
        }
        $clausesProcessed++;
    }

    if ($currentSuper !== null) {
        if ($colorsForSuper) {
            foreach ($colorsForSuper as $colorId => $_) {
                $insertStmt->execute([':color_id' => $colorId, ':supercat_id' => $currentSuper]);
            }
        }
        $superProcessed++;
    }

    respond([
        'ok' => true,
        'supercats_processed' => $superProcessed,
        'clauses_processed' => $clausesProcessed,
    ]);

} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
