<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function loadCategories(PDO $pdo): array {
    $grouped = ['neutral' => [], 'hue' => [], 'lightness' => [], 'chroma' => []];

    $rows = $pdo->query("
        SELECT name, type
        FROM category_definitions
        WHERE COALESCE(calc_only, 0) = 0
          AND type IN ('neutral','lightness','chroma')
        ORDER BY type, name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seen = ['neutral' => [], 'lightness' => [], 'chroma' => []];
    foreach ($rows as $row) {
        $type = strtolower($row['type'] ?? '');
        if (!isset($grouped[$type])) continue;
        $name = trim((string)$row['name']);
        if ($name === '') continue;
        $key = strtolower(str_replace(' ', '', $name));
        if (isset($seen[$type][$key])) continue;
        $seen[$type][$key] = true;
        $grouped[$type][] = ['name' => $name];
    }

    $hueRows = $pdo->query("
        SELECT DISTINCT display_name
        FROM hue_display
        WHERE display_name IS NOT NULL AND display_name <> ''
        ORDER BY sort_order, display_name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($hueRows as $row) {
        $name = trim((string)$row['display_name']);
        if ($name === '') continue;
        $grouped['hue'][] = ['name' => $name];
    }

    return $grouped;
}

function loadSupercats(PDO $pdo): array {
    $supercats = $pdo->query("SELECT id, slug, display_name, notes, is_active FROM supercats ORDER BY display_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$supercats) return [];
    $ids = array_column($supercats, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $clauses = $pdo->prepare("
        SELECT sc.id,
               sc.supercat_id,
               sc.neutral_name,
               sc.hue_name,
               sc.hue_scope,
               sc.hue_min_name,
               sc.hue_max_name,
               sc.light_name,
               sc.light_scope,
               sc.light_min_name,
               sc.light_max_name,
               sc.chroma_name,
               sc.chroma_scope,
               sc.chroma_min_name,
               sc.chroma_max_name,
               sc.notes
        FROM supercat_clauses sc
        WHERE sc.supercat_id IN ($placeholders)
        ORDER BY sc.id ASC
    ");
    $clauses->execute($ids);
    $bySuper = [];
    foreach ($clauses->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int)$row['supercat_id'];
        $bySuper[$sid][] = [
            'id' => (int)$row['id'],
            'neutral_name' => $row['neutral_name'],
            'hue_name' => $row['hue_name'],
            'hue_scope' => $row['hue_scope'] ?: 'exact',
            'hue_min_name' => $row['hue_min_name'],
            'hue_max_name' => $row['hue_max_name'],
            'light_name' => $row['light_name'],
            'light_scope' => $row['light_scope'] ?: 'exact',
            'light_min_name' => $row['light_min_name'],
            'light_max_name' => $row['light_max_name'],
            'chroma_name' => $row['chroma_name'],
            'chroma_scope' => $row['chroma_scope'] ?: 'exact',
            'chroma_min_name' => $row['chroma_min_name'],
            'chroma_max_name' => $row['chroma_max_name'],
            'notes' => $row['notes'],
        ];
    }
    foreach ($supercats as &$sc) {
        $sc['id'] = (int)$sc['id'];
        $sc['is_active'] = (int)$sc['is_active'];
        $sc['clauses'] = $bySuper[$sc['id']] ?? [];
    }
    return $supercats;
}

function slugify(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');
    return $slug ?: ('supercat-' . time());
}

function requireId(array $input, string $field = 'id'): int {
    $id = isset($input[$field]) ? (int)$input[$field] : 0;
    if ($id <= 0) respond(['ok' => false, 'error' => "Missing $field"], 400);
    return $id;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $payload = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $payload = $decoded;
        }
    }

    $action = $payload['action'] ?? $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            respond([
                'ok' => true,
                'supercats' => loadSupercats($pdo),
                'categories' => loadCategories($pdo),
            ]);

        case 'create_supercat':
            $name = trim((string)($payload['display_name'] ?? ''));
            if ($name === '') respond(['ok' => false, 'error' => 'display_name required'], 400);
            $slug = trim((string)($payload['slug'] ?? '')) ?: slugify($name);
            $stmt = $pdo->prepare("INSERT INTO supercats (slug, display_name, notes, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$slug, $name, trim((string)($payload['notes'] ?? '')) ?: null]);
            respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

        case 'update_supercat':
            $id = requireId($payload);
            $name = trim((string)($payload['display_name'] ?? ''));
            if ($name === '') respond(['ok' => false, 'error' => 'display_name required'], 400);
            $slug = trim((string)($payload['slug'] ?? '')) ?: slugify($name);
            $notes = trim((string)($payload['notes'] ?? '')) ?: null;
            $active = isset($payload['is_active']) ? ((int)$payload['is_active'] ? 1 : 0) : 1;
            $stmt = $pdo->prepare("UPDATE supercats SET slug=?, display_name=?, notes=?, is_active=? WHERE id = ?");
            $stmt->execute([$slug, $name, $notes, $active, $id]);
            respond(['ok' => true]);

        case 'delete_supercat':
            $id = requireId($payload);
            $stmt = $pdo->prepare("DELETE FROM supercats WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            respond(['ok' => true]);

        case 'add_clause':
            $supercatId = requireId($payload, 'supercat_id');
            $stmt = $pdo->prepare("
                INSERT INTO supercat_clauses
                  (supercat_id, neutral_name,
                   hue_name, hue_scope, hue_min_name, hue_max_name,
                   light_name, light_scope, light_min_name, light_max_name,
                   chroma_name, chroma_scope, chroma_min_name, chroma_max_name,
                   notes)
                VALUES
                  (:sid, :n,
                   :h, :h_scope, :h_min, :h_max,
                   :l, :l_scope, :l_min, :l_max,
                   :c, :c_scope, :c_min, :c_max,
                   :notes)
            ");
            $stmt->execute([
                ':sid' => $supercatId,
                ':n' => clean_name($payload['neutral_name'] ?? null),
                ':h' => clean_name($payload['hue_name'] ?? null),
                ':h_scope' => scope_value($payload['hue_scope'] ?? 'exact'),
                ':h_min' => clean_name($payload['hue_min_name'] ?? null),
                ':h_max' => clean_name($payload['hue_max_name'] ?? null),
                ':l' => clean_name($payload['light_name'] ?? null),
                ':l_scope' => scope_value($payload['light_scope'] ?? 'exact'),
                ':l_min' => clean_name($payload['light_min_name'] ?? null),
                ':l_max' => clean_name($payload['light_max_name'] ?? null),
                ':c' => clean_name($payload['chroma_name'] ?? null),
                ':c_scope' => scope_value($payload['chroma_scope'] ?? 'exact'),
                ':c_min' => clean_name($payload['chroma_min_name'] ?? null),
                ':c_max' => clean_name($payload['chroma_max_name'] ?? null),
                ':notes' => trim((string)($payload['notes'] ?? '')) ?: null,
            ]);
            respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

        case 'update_clause':
            $id = requireId($payload);
            $stmt = $pdo->prepare("
                UPDATE supercat_clauses
                SET neutral_name = :n,
                    hue_name = :h,
                    hue_scope = :h_scope,
                    hue_min_name = :h_min,
                    hue_max_name = :h_max,
                    light_name = :l,
                    light_scope = :l_scope,
                    light_min_name = :l_min,
                    light_max_name = :l_max,
                    chroma_name = :c,
                    chroma_scope = :c_scope,
                    chroma_min_name = :c_min,
                    chroma_max_name = :c_max,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':n' => clean_name($payload['neutral_name'] ?? null),
                ':h' => clean_name($payload['hue_name'] ?? null),
                ':h_scope' => scope_value($payload['hue_scope'] ?? 'exact'),
                ':h_min' => clean_name($payload['hue_min_name'] ?? null),
                ':h_max' => clean_name($payload['hue_max_name'] ?? null),
                ':l' => clean_name($payload['light_name'] ?? null),
                ':l_scope' => scope_value($payload['light_scope'] ?? 'exact'),
                ':l_min' => clean_name($payload['light_min_name'] ?? null),
                ':l_max' => clean_name($payload['light_max_name'] ?? null),
                ':c' => clean_name($payload['chroma_name'] ?? null),
                ':c_scope' => scope_value($payload['chroma_scope'] ?? 'exact'),
                ':c_min' => clean_name($payload['chroma_min_name'] ?? null),
                ':c_max' => clean_name($payload['chroma_max_name'] ?? null),
                ':notes' => trim((string)($payload['notes'] ?? '')) ?: null,
            ]);
            respond(['ok' => true]);

        case 'delete_clause':
            $id = requireId($payload);
            $stmt = $pdo->prepare("DELETE FROM supercat_clauses WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
function clean_name($value): ?string {
    if (!isset($value)) return null;
    $val = trim((string)$value);
    return $val === '' ? null : $val;
}

function scope_value($value): string {
    $v = strtolower(trim((string)$value));
    return in_array($v, ['exact','min','max','between'], true) ? $v : 'exact';
}
