<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/_worker_auth.php';

use App\Repos\PdoAssetCreatorRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

cf_require_worker_token();

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    $payload = is_array($payload) ? $payload : [];
    $requestedJobId = (int)($payload['asset_creator_job_id'] ?? $payload['job_id'] ?? 0);

    $pdo->beginTransaction();
    if ($requestedJobId > 0) {
        $stmt = $pdo->prepare(
            "SELECT asset_creator_job_id
               FROM asset_creator_jobs
              WHERE asset_creator_job_id = :id
                AND creator_key = 'youtube.playlist_video'
                AND status IN ('queued', 'failed')
              FOR UPDATE"
        );
        $stmt->execute([':id' => $requestedJobId]);
    } else {
        $stmt = $pdo->query(
            "SELECT asset_creator_job_id
               FROM asset_creator_jobs
              WHERE creator_key = 'youtube.playlist_video'
                AND status = 'queued'
              ORDER BY updated_at ASC, asset_creator_job_id ASC
              LIMIT 1
              FOR UPDATE"
        );
    }

    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) {
        $pdo->commit();
        respond(['ok' => true, 'claimed' => false, 'job' => null]);
    }

    $jobId = (int)$row['asset_creator_job_id'];
    $update = $pdo->prepare(
        "UPDATE asset_creator_jobs
            SET status = 'rendering',
                notes = :notes,
                updated_at = NOW()
          WHERE asset_creator_job_id = :id"
    );
    $update->execute([
        ':id' => $jobId,
        ':notes' => 'Claimed by Mac YouTube render worker at ' . gmdate('c'),
    ]);
    $pdo->commit();

    $repo = new PdoAssetCreatorRepository($pdo);
    $job = $repo->findJob($jobId);
    respond(['ok' => true, 'claimed' => true, 'job' => $job]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
