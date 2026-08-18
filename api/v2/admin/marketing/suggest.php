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

use App\Marketing\MarketingService;

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

    $count = (int)(
        $payload['count'] ?? 0
    );

    $allowDuplicates =
        !empty(
            $payload['allow_duplicates']
        );

    $selectedTerms =
        $payload['selected_terms']
        ?? [];

    $templates =
        $payload['templates']
        ?? [];

    if ($count <= 0) {
        throw new InvalidArgumentException(
            'Positive suggestion count required.'
        );
    }

    if (!is_array($selectedTerms)) {
        throw new InvalidArgumentException(
            'selected_terms must be an object.'
        );
    }

    if (!is_array($templates)) {
        throw new InvalidArgumentException(
            'templates must be an array.'
        );
    }

    /*
     * Mark has no database work to do here.
     *
     * The requester already selected the terms and
     * templates from Marketing's library.
     *
     * Mark's only job now is to assemble suggestions.
     */
    $marketing = new MarketingService();

    $result = $marketing->generateSuggestions(
        $count,
        $selectedTerms,
        $templates,
        $allowDuplicates
    );

    workflow_respond([
        'ok' => true,
        'result' => $result,
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