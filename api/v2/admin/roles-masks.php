<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $input = $decoded;
    }
}
$action = $input['action'] ?? ($_GET['action'] ?? 'load');

try {
    switch ($action) {
        case 'load':
            loadRolesAndMasks($pdo);
            break;
        case 'create_role':
            createRole($pdo, $input);
            break;
        case 'update_role':
            updateRole($pdo, $input);
            break;
        case 'set_mask_role':
            setMaskRole($pdo, $input);
            break;
        case 'delete_mask':
            deleteMask($pdo, $input);
            break;
        default:
            respond(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

function loadRolesAndMasks(PDO $pdo): void {
    $roles = $pdo->query("SELECT id, slug, display_name, sort_order FROM master_roles ORDER BY sort_order, id")
                 ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->query("SELECT m.mask_slug, m.role_id, r.slug AS role_slug, r.display_name
                         FROM master_role_masks m
                         LEFT JOIN master_roles r ON r.id = m.role_id
                         ORDER BY m.mask_slug");
    $masks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    respond(['ok' => true, 'roles' => $roles, 'masks' => $masks]);
}

function createRole(PDO $pdo, array $input): void {
    $slug = trim((string)($input['slug'] ?? ''));
    $name = trim((string)($input['display_name'] ?? ''));
    $order = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
    if ($slug === '' || $name === '') {
        respond(['ok' => false, 'error' => 'Slug and display_name required'], 400);
    }

    $stmt = $pdo->prepare("INSERT INTO master_roles (slug, display_name, sort_order) VALUES (?, ?, ?)");
    $stmt->execute([$slug, $name, $order]);
    respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

function updateRole(PDO $pdo, array $input): void {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $slug = trim((string)($input['slug'] ?? ''));
    $name = trim((string)($input['display_name'] ?? ''));
    $order = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
    if ($id <= 0 || $slug === '' || $name === '') {
        respond(['ok' => false, 'error' => 'Invalid role payload'], 400);
    }

    $stmt = $pdo->prepare("UPDATE master_roles SET slug = ?, display_name = ?, sort_order = ? WHERE id = ?");
    $stmt->execute([$slug, $name, $order, $id]);
    respond(['ok' => true]);
}

function setMaskRole(PDO $pdo, array $input): void {
    $slug = trim((string)($input['mask_slug'] ?? ''));
    $roleId = isset($input['role_id']) && $input['role_id'] !== '' ? (int)$input['role_id'] : null;
    if ($slug === '') {
        respond(['ok' => false, 'error' => 'mask_slug required'], 400);
    }

    if ($roleId === null) {
        $stmt = $pdo->prepare("DELETE FROM master_role_masks WHERE mask_slug = ?");
        $stmt->execute([$slug]);
        respond(['ok' => true, 'deleted' => true]);
    }

    $stmt = $pdo->prepare("INSERT INTO master_role_masks (mask_slug, role_id)
                           VALUES (:slug, :role)
                           ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)");
    $stmt->execute([':slug' => $slug, ':role' => $roleId]);
    respond(['ok' => true]);
}

function deleteMask(PDO $pdo, array $input): void {
    $slug = trim((string)($input['mask_slug'] ?? ''));
    if ($slug === '') {
        respond(['ok' => false, 'error' => 'mask_slug required'], 400);
    }
    $stmt = $pdo->prepare("DELETE FROM master_role_masks WHERE mask_slug = ?");
    $stmt->execute([$slug]);
    respond(['ok' => true]);
}
