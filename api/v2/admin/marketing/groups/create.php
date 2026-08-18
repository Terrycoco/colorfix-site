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

    $groupToken = trim(
        (string)($payload['group_token'] ?? '')
    );

    $label = isset($payload['label'])
        ? trim((string)$payload['label'])
        : null;

    $sortOrder = (int)(
        $payload['sort_order'] ?? 0
    );

    if ($groupToken === '') {
        throw new InvalidArgumentException(
            'group_token required.'
        );
    }

    $repo = new PdoMarketingRepository($pdo);

    $group = $repo->createTermGroup(
        $groupToken,
        $label,
        $sortOrder
    );

    workflow_respond([
        'ok' => true,
        'group' => $group,
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