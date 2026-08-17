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

    $jobId = (int)($payload['pub_render_job_id'] ?? 0);

    $status = trim(
        (string)($payload['status'] ?? '')
    );

    if ($jobId <= 0) {
        throw new InvalidArgumentException(
            'pub_render_job_id required.'
        );
    }

    if (!in_array($status, ['complete', 'failed'], true)) {
        throw new InvalidArgumentException(
            'status must be complete or failed.'
        );
    }

    $repo = new PdoRenderJobRepository($pdo);

    if ($status === 'complete') {
        $outputRelPath = trim(
            (string)($payload['output_rel_path'] ?? '')
        );

        $outputFileSizeBytes = isset(
            $payload['output_file_size_bytes']
        )
            ? (int)$payload['output_file_size_bytes']
            : null;

        if ($outputRelPath === '') {
            throw new InvalidArgumentException(
                'output_rel_path required for completed jobs.'
            );
        }

        $repo->completeJob(
            $jobId,
            $outputRelPath,
            $outputFileSizeBytes
        );
    } else {
        $errorMessage = trim(
            (string)($payload['error_message'] ?? '')
        );

        if ($errorMessage === '') {
            $errorMessage = 'Render failed.';
        }

        $repo->failJob(
            $jobId,
            $errorMessage
        );
    }

    $job = $repo->findById($jobId);

    workflow_respond([
        'ok' => true,
        'job' => $job,
    ]);

} catch (
    InvalidArgumentException
    | JsonException
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