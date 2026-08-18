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
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $payload = json_decode(
        (string)file_get_contents('php://input'),
        true
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException('Valid JSON body required.');
    }

    $groupId = (int)($payload['marketing_term_group_id'] ?? 0);
    $label = trim((string)($payload['label'] ?? ''));

    if ($groupId <= 0) {
        throw new InvalidArgumentException('Valid marketing term group ID required.');
    }

    if ($label === '') {
        throw new InvalidArgumentException('Group name required.');
    }

    $repo = new PdoMarketingRepository($pdo);

    workflow_respond([
        'ok' => true,
        'group' => $repo->updateTermGroup($groupId, $label),
    ]);

} catch (InvalidArgumentException | RuntimeException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}