<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\PhotoRenderingService;

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id required']);
        exit;
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    $palette = $paletteRepo->findById($id);
    if (!$palette) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Palette not found']);
        exit;
    }

    $photoRepo = new PdoPhotoRepository($pdo);
    $service = new PhotoRenderingService($photoRepo, $pdo);
    $render = $service->renderAppliedPalette($palette);

    $roleGroups = loadRoleGroups($pdo);
    $orderedEntries = orderEntries($palette->entries, $roleGroups);

    echo json_encode([
        'ok' => true,
        'render' => $render,
        'palette' => [
            'id' => $palette->id,
            'title' => $palette->title,
            'notes' => $palette->notes,
            'photo_id' => $palette->photoId,
            'asset_id' => $palette->assetId,
            'role_groups' => $roleGroups,
        ],
        'entries' => $orderedEntries,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function loadRoleGroups(PDO $pdo): array {
    $roles = $pdo->query("SELECT id, slug, display_name, sort_order FROM master_roles ORDER BY sort_order, id")
                 ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$roles) return [];

    $groupById = [];
    foreach ($roles as $role) {
        $groupById[$role['id']] = [
            'slug' => $role['slug'],
            'display_name' => $role['display_name'],
            'roles' => [],
        ];
    }

    $stmt = $pdo->query("SELECT m.mask_slug, m.role_id FROM master_role_masks m");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roleId = (int)$row['role_id'];
        if (isset($groupById[$roleId])) {
            $groupById[$roleId]['roles'][] = (string)$row['mask_slug'];
        }
    }

    return array_values($groupById);
}

function orderEntries(array $entries, array $roleGroups): array {
    $roleMap = [];
    foreach ($roleGroups as $idx => $group) {
        $label = $group['display_name'] ?? $group['slug'] ?? ('Group ' . ($idx + 1));
        $slug = $group['slug'] ?? ('group-' . $idx);
        foreach ($group['roles'] as $maskSlug) {
            $roleMap[strtolower($maskSlug)] = ['order' => $idx, 'label' => $label, 'slug' => $slug];
        }
    }
    $ordered = $entries;
    usort($ordered, function ($a, $b) use ($roleMap) {
        $maskA = strtolower($a['mask_role'] ?? '');
        $maskB = strtolower($b['mask_role'] ?? '');
        $orderA = $roleMap[$maskA]['order'] ?? 99;
        $orderB = $roleMap[$maskB]['order'] ?? 99;
        if ($orderA === $orderB) {
            return ($a['mask_role'] ?? '') <=> ($b['mask_role'] ?? '');
        }
        return $orderA <=> $orderB;
    });
    $lastSlug = null;
    foreach ($ordered as &$entry) {
        $mask = strtolower($entry['mask_role'] ?? '');
        if (isset($roleMap[$mask])) {
            $entry['group_slug'] = $roleMap[$mask]['slug'];
            $entry['group_label'] = $roleMap[$mask]['label'];
            $entry['group_header'] = ($roleMap[$mask]['slug'] !== $lastSlug);
            $lastSlug = $roleMap[$mask]['slug'];
        } else {
            $entry['group_slug'] = null;
            $entry['group_label'] = null;
            $entry['group_header'] = false;
        }
    }
    unset($entry);
    return $ordered;
}
