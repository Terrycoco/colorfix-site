<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../project-workflow/_helpers.php';
require_once __DIR__ . '/_worker_auth.php';

use App\PUB\Create\Video\VideoWorkerHealthService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $raw =
        file_get_contents(
            'php://input'
        );

    $payload = [];

    if (
        $raw !== false
        && trim($raw) !== ''
    ) {
        $decoded =
            json_decode(
                $raw,
                true
            );

        if (is_array($decoded)) {
            $payload =
                $decoded;
        }
    }

    $details = [
        'worker_name' =>
            trim(
                (string)(
                    $payload[
                        'worker_name'
                    ]
                    ?? ''
                )
            ) ?: null,

        'pid' =>
            isset(
                $payload['pid']
            )
                ? (int)$payload['pid']
                : null,

        'implementation' =>
            trim(
                (string)(
                    $payload[
                        'implementation'
                    ]
                    ?? ''
                )
            ) ?: null,
    ];

    $projectRoot =
        dirname(
            __DIR__,
            5
        );

    $health =
        new VideoWorkerHealthService(
            $projectRoot
        );

    $heartbeat =
        $health->recordHeartbeat(
            $details
        );

    workflow_respond([
        'ok' => true,
        'heartbeat' =>
            $heartbeat,
    ]);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' =>
            $e->getMessage(),
    ], 500);
}
