<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        respond(['ok' => false, 'error' => $method . ' only'], 405);
    }
}

function read_json_payload(): array {
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    return $data;
}

function milestone_int($value, int $default = 0): int {
    if ($value === null || $value === '') return $default;
    return max(0, (int)$value);
}

function normalize_category_order(PDO $pdo): void {
    $rows = $pdo->query('SELECT id FROM milestone_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare('UPDATE milestone_categories SET sort_order = :sort_order WHERE id = :id');
    $order = 1;
    foreach ($rows as $id) {
        $stmt->execute(['sort_order' => $order, 'id' => (int)$id]);
        $order++;
    }
}

function normalize_milestone_order(PDO $pdo, int $categoryId): void {
    $stmt = $pdo->prepare('SELECT id FROM milestones WHERE category_id = :category_id AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['category_id' => $categoryId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare('UPDATE milestones SET sort_order = :sort_order WHERE id = :id');
    $order = 1;
    foreach ($ids as $id) {
        $update->execute(['sort_order' => $order, 'id' => (int)$id]);
        $order++;
    }
}

function category_exists(PDO $pdo, int $categoryId, bool $includeDeleted = false): bool {
    $sql = 'SELECT COUNT(*) FROM milestone_categories WHERE id = :id';
    if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $categoryId]);
    return (int)$stmt->fetchColumn() > 0;
}

function get_milestone(PDO $pdo, int $milestoneId, bool $includeDeleted = false): ?array {
    $sql = 'SELECT * FROM milestones WHERE id = :id';
    if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $milestoneId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function move_active_milestones_for_insert(PDO $pdo, int $categoryId, int $position): void {
    $stmt = $pdo->prepare('UPDATE milestones SET sort_order = sort_order + 1 WHERE category_id = :category_id AND deleted_at IS NULL AND sort_order >= :position');
    $stmt->execute(['category_id' => $categoryId, 'position' => $position]);
}

function requested_position(PDO $pdo, int $categoryId, array $data, ?int $editingId = null): int {
    $mode = (string)($data['position_mode'] ?? 'end');
    $refId = milestone_int($data['position_ref_id'] ?? 0);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM milestones WHERE category_id = :category_id AND deleted_at IS NULL' . ($editingId ? ' AND id <> :editing_id' : ''));
    $params = ['category_id' => $categoryId];
    if ($editingId) $params['editing_id'] = $editingId;
    $countStmt->execute($params);
    $count = (int)$countStmt->fetchColumn();
    if ($mode === 'beginning') return 1;
    if (($mode === 'before' || $mode === 'after') && $refId > 0) {
        $stmt = $pdo->prepare('SELECT sort_order FROM milestones WHERE id = :id AND category_id = :category_id AND deleted_at IS NULL');
        $stmt->execute(['id' => $refId, 'category_id' => $categoryId]);
        $order = $stmt->fetchColumn();
        if ($order !== false) {
            return max(1, (int)$order + ($mode === 'after' ? 1 : 0));
        }
    }
    return $count + 1;
}

function milestone_list_payload(PDO $pdo): array {
    normalize_category_order($pdo);
    $catStmt = $pdo->query(
        "SELECT c.id, c.name, c.sort_order, c.preview_count, c.is_archived, c.created_at, c.updated_at, c.deleted_at,
                SUM(CASE WHEN m.deleted_at IS NULL AND m.achieved_at IS NOT NULL THEN 1 ELSE 0 END) AS achieved_count,
                SUM(CASE WHEN m.deleted_at IS NULL THEN 1 ELSE 0 END) AS active_count
         FROM milestone_categories c
         LEFT JOIN milestones m ON m.category_id = c.id
         WHERE c.deleted_at IS NULL
         GROUP BY c.id
         ORDER BY c.sort_order ASC, c.id ASC"
    );
    $categories = [];
    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $categories[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sort_order' => (int)$row['sort_order'],
            'preview_count' => max(1, (int)$row['preview_count']),
            'is_archived' => (int)$row['is_archived'] === 1,
            'achieved_count' => (int)$row['achieved_count'],
            'active_count' => (int)$row['active_count'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    $milestones = [];
    $stmt = $pdo->query(
        'SELECT id, category_id, title, notes, sort_order, achieved_at, created_at, updated_at
         FROM milestones
         WHERE deleted_at IS NULL
         ORDER BY category_id ASC, sort_order ASC, id ASC'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $milestones[] = [
            'id' => (int)$row['id'],
            'category_id' => (int)$row['category_id'],
            'title' => (string)$row['title'],
            'notes' => (string)($row['notes'] ?? ''),
            'sort_order' => (int)$row['sort_order'],
            'achieved_at' => $row['achieved_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    return ['ok' => true, 'categories' => $categories, 'milestones' => $milestones];
}
