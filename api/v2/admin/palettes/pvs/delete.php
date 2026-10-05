<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required.']);
        return;
    }
    $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    $id = (int)($input['palette_viewer_id'] ?? 0);
    $projectId = (int)($input['project_id'] ?? 0);
    if ($id <= 0 || $projectId <= 0) { throw new InvalidArgumentException('PV and project are required.'); }
    $check = $pdo->prepare('SELECT 1 FROM palette_viewers pv WHERE pv.palette_viewer_id = ? AND (pv.project_id = ? OR pv.saved_palette_id IN (SELECT saved_palette_id FROM project_palettes WHERE project_id = ?))');
    $check->execute([$id, $projectId, $projectId]);
    if (!$check->fetchColumn()) { throw new InvalidArgumentException('This PV does not belong to this project.'); }
    $deleted = (new App\PALETTES\Managers\PVManager($pdo))->deletePV($id);
    if (!$deleted) { throw new RuntimeException('PV not found.'); }
    echo json_encode(['ok' => true]);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
