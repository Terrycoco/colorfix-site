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

use App\PUB\Create\Video\PdoVideoJobRepository;

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

    $pubAssetId =
        isset($payload['pub_asset_id'])
            && $payload['pub_asset_id'] !== null
                ? (int)$payload['pub_asset_id']
                : null;

    $creatorKey = trim(
        (string)($payload['creator_key'] ?? '')
    );

    $videoRecipeKey = trim(
        (string)($payload['video_recipe_key'] ?? '')
    );

    $props = $payload['props'] ?? null;

    if (
        $pubAssetId !== null
        && $pubAssetId <= 0
    ) {
        throw new InvalidArgumentException(
            'pub_asset_id must be positive when supplied.'
        );
    }

    if ($creatorKey === '') {
        throw new InvalidArgumentException(
            'creator_key required.'
        );
    }

    if ($videoRecipeKey === '') {
        throw new InvalidArgumentException(
            'video_recipe_key required.'
        );
    }

    if (!is_array($props)) {
        throw new InvalidArgumentException(
            'props object required.'
        );
    }

    $repo = new PdoVideoJobRepository($pdo);

    $job = $repo->createJob(
        $pubAssetId,
        $creatorKey,
        $videoRecipeKey,
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
