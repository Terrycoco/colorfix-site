<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\Marketing\PdoMarketingRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond([
            'ok' => false,
            'error' => 'GET only',
        ], 405);
    }

    $deliverableKey = trim(
        (string)($_GET['deliverable_key'] ?? '')
    );

    $rawTags = $_GET['tag'] ?? [];

    if (!is_array($rawTags)) {
        $rawTags = [$rawTags];
    }

    $tags = array_values(array_filter(
        array_map(
            static fn($tag): string =>
                trim((string)$tag),
            $rawTags
        ),
        static fn(string $tag): bool =>
            $tag !== ''
    ));

    $repo = new PdoMarketingRepository($pdo);

    $groups =
        $repo->getActiveTermGroupsWithTerms();

    $templates =
        $repo->getActiveTemplates(
            $deliverableKey !== ''
                ? $deliverableKey
                : null,
            $tags
        );

    workflow_respond([
        'ok' => true,
        'groups' => $groups,
        'templates' => $templates,
    ]);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}