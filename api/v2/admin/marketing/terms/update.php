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

    $termId = (int)($payload['marketing_term_id'] ?? 0);
    $term = trim((string)($payload['term'] ?? ''));

    $singular = isset($payload['singular_term'])
        ? trim((string)$payload['singular_term'])
        : null;

    $plural = isset($payload['plural_term'])
        ? trim((string)$payload['plural_term'])
        : null;

    if ($termId <= 0) {
        throw new InvalidArgumentException('Valid marketing term ID required.');
    }

    if ($term === '') {
        throw new InvalidArgumentException('Term required.');
    }

    $repo = new PdoMarketingRepository($pdo);

    workflow_respond([
        'ok' => true,
        'term' => $repo->updateTerm(
            $termId,
            $term,
            $singular,
            $plural
        ),
    ]);

} catch (InvalidArgumentException | RuntimeException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}