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
require_once __DIR__ . '/../../auth.php';

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

    $creatorKey = trim(
        (string)($payload['creator_key'] ?? '')
    );

    $compositionKey = trim(
        (string)($payload['composition_key'] ?? '')
    );

    $props = $payload['props'] ?? null;

    if ($creatorKey === '') {
        throw new InvalidArgumentException(
            'creator_key required.'
        );
    }

    if ($compositionKey === '') {
        throw new InvalidArgumentException(
            'composition_key required.'
        );
    }

    if (!is_array($props)) {
        throw new InvalidArgumentException(
            'props object required.'
        );
    }

    $repo = new PdoRenderJobRepository($pdo);

    $job = $repo->createJob(
        $creatorKey,
        $compositionKey,
        $props
    );

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