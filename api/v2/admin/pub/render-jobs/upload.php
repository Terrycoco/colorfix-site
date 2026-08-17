<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../project-workflow/_helpers.php';
require_once __DIR__ . '/_worker_auth.php';

use App\PUB\Create\Video\PdoRenderJobRepository;
use App\PUB\Create\Video\RenderJobFileService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $jobId = (int)(
        $_POST['pub_render_job_id']
        ?? 0
    );

    if ($jobId <= 0) {
        throw new InvalidArgumentException(
            'pub_render_job_id required.'
        );
    }

    if (empty($_FILES['file'])) {
        throw new InvalidArgumentException(
            'file required.'
        );
    }

    $repo = new PdoRenderJobRepository($pdo);

    $job = $repo->findById($jobId);

    if (!$job) {
        throw new InvalidArgumentException(
            'Render job not found.'
        );
    }

    if (!in_array(
        $job['status'],
        ['claimed', 'rendering'],
        true
    )) {
        throw new RuntimeException(
            'Render job is not accepting an upload.'
        );
    }

    $files = new RenderJobFileService();

    $stored = $files->store(
        $jobId,
        $_FILES['file']
    );

    workflow_respond([
        'ok' => true,
        'file' => $stored,
    ]);

} catch (
    InvalidArgumentException
    | RuntimeException $e
) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}