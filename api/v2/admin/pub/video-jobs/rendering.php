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

use App\PUB\Create\Video\PdoVideoJobRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        throw new InvalidArgumentException('JSON body required.');
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        throw new InvalidArgumentException('Valid JSON body required.');
    }

    $jobId = (int)($payload['pub_video_job_id'] ?? 0);

    if ($jobId <= 0) {
        throw new InvalidArgumentException(
            'pub_video_job_id required.'
        );
    }

    $repo = new PdoVideoJobRepository($pdo);

    $repo->markRendering($jobId);

    $job = $repo->findById($jobId);

    workflow_respond([
        'ok' => true,
        'job' => $job,
    ]);

} catch (InvalidArgumentException | RuntimeException $e) {
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
