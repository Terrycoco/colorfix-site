<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryUsageService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $fromId = isset($payload['from_photo_library_id']) ? (int)$payload['from_photo_library_id'] : 0;
    $toId = isset($payload['to_photo_library_id']) ? (int)$payload['to_photo_library_id'] : 0;
    $apply = !empty($payload['apply']);

    if ($fromId <= 0 || $toId <= 0) {
        respond(['ok' => false, 'error' => 'from_photo_library_id and to_photo_library_id required'], 400);
    }
    if ($fromId === $toId) {
        respond(['ok' => false, 'error' => 'Source and target photo ids must be different'], 400);
    }

    $repo = new PdoPhotoLibraryRepository($pdo);
    $usageService = new PhotoLibraryUsageService($repo);

    $fromRow = $repo->findById($fromId);
    $toRow = $repo->findById($toId);
    if (!$fromRow) {
        respond(['ok' => false, 'error' => "Source photo #{$fromId} not found"], 404);
    }
    if (!$toRow) {
        respond(['ok' => false, 'error' => "Target photo #{$toId} not found"], 404);
    }

    $fromUsages = $usageService->getUsageSummary($fromId);
    $toUsages = $usageService->getUsageSummary($toId);

    $result = [
        'from_photo_library_id' => $fromId,
        'to_photo_library_id' => $toId,
        'from' => [
            'title' => $fromRow['title'] ?? '',
            'source_type' => $fromRow['source_type'] ?? '',
            'rel_path' => $fromRow['rel_path'] ?? '',
            'usage_count' => count($fromUsages),
            'usages' => $fromUsages,
        ],
        'to' => [
            'title' => $toRow['title'] ?? '',
            'source_type' => $toRow['source_type'] ?? '',
            'rel_path' => $toRow['rel_path'] ?? '',
            'usage_count' => count($toUsages),
            'usages' => $toUsages,
        ],
        'applied' => false,
        'retired_source_row' => false,
        'note' => 'This merge moves DB usages to the keeper row and retires the duplicate photo_library row. It does not delete files from disk.',
    ];

    if ($apply) {
        $pdo->beginTransaction();
        try {
            $repo->reassignAllUsages($fromId, $toId);
            $repo->update($fromId, ['is_inactive' => true]);
            $pdo->commit();
            $result['applied'] = true;
            $result['retired_source_row'] = true;
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $inner;
        }
    }

    respond([
        'ok' => true,
        'apply' => $apply,
        'result' => $result,
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
