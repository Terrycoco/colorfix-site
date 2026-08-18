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

    $groupId = (int)(
        $payload['marketing_term_group_id']
        ?? 0
    );

    $term = trim(
        (string)($payload['term'] ?? '')
    );

    $singularTerm = isset(
        $payload['singular_term']
    )
        ? trim(
            (string)$payload['singular_term']
        )
        : null;

    $pluralTerm = isset(
        $payload['plural_term']
    )
        ? trim(
            (string)$payload['plural_term']
        )
        : null;

    $sortOrder = (int)(
        $payload['sort_order'] ?? 0
    );

    if ($groupId <= 0) {
        throw new InvalidArgumentException(
            'Valid marketing term group ID required.'
        );
    }

    if ($term === '') {
        throw new InvalidArgumentException(
            'term required.'
        );
    }

    $repo = new PdoMarketingRepository($pdo);

    $created = $repo->createTerm(
        $groupId,
        $term,
        $singularTerm,
        $pluralTerm,
        $sortOrder
    );

    workflow_respond([
        'ok' => true,
        'term' => $created,
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