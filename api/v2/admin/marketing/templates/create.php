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

use App\Marketing\PdoMarketingRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode(
        $raw !== false ? $raw : '',
        true
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException(
            'Valid JSON body required.'
        );
    }

    $deliverableKey = trim(
        (string)(
            $payload['deliverable_key']
            ?? ''
        )
    );

    $template = trim(
        (string)(
            $payload['template']
            ?? ''
        )
    );

    $tags = $payload['tags'] ?? [];

    if (!is_array($tags)) {
        $tags = [];
    }

    $sortOrder = (int)(
        $payload['sort_order'] ?? 0
    );

    if ($deliverableKey === '') {
        throw new InvalidArgumentException(
            'deliverable_key required.'
        );
    }

    if ($template === '') {
        throw new InvalidArgumentException(
            'template required.'
        );
    }

    $repo = new PdoMarketingRepository($pdo);

    $created = $repo->createTemplate(
        $deliverableKey,
        $template,
        $tags,
        $sortOrder
    );

    workflow_respond([
        'ok' => true,
        'template' => $created,
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
