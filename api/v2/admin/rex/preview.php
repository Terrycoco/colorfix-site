<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\PlaylistExperienceResolver;
use App\REX\Resolvers\RexResolverRegistry;
use App\REX\Resolvers\ViewerResolver;
use App\REX\Services\RexResolver;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $data = json_decode(
        file_get_contents('php://input') ?: '',
        true
    );

    if (!is_array($data)) {
        workflow_respond([
            'ok' => false,
            'error' => 'Invalid JSON',
        ], 400);
    }

    $resolverKey = trim((string)($data['resolver_key'] ?? ''));
    $resourceType = trim((string)($data['resource_type'] ?? ''));
    $resourceId = (int)($data['resource_id'] ?? 0);
    $context = is_array($data['context'] ?? null)
        ? $data['context']
        : [];

    if ($resolverKey === '') {
        throw new InvalidArgumentException('Resolver key required.');
    }

    if ($resourceType === '') {
        throw new InvalidArgumentException('Resource type required.');
    }

    if ($resourceId <= 0) {
        throw new InvalidArgumentException('Valid resource ID required.');
    }

    $registry = new RexResolverRegistry();

    $registry->register(
        'playlist_experience',
        new PlaylistExperienceResolver($pdo)
    );
    $registry->register(
        'viewer',
        new ViewerResolver($pdo)
    );

    $rexResolver = new RexResolver(
        new PdoRexReservationRepository($pdo),
        $registry
    );

    $descriptor = $rexResolver->previewDescribe(
        $resolverKey,
        $resourceType,
        $resourceId,
        $context
    );

    workflow_respond([
        'ok' => true,
        'descriptor' => [
            'title' => $descriptor->title,
            'fields' => $descriptor->fields,
        ],
    ]);

} catch (InvalidArgumentException | DomainException | RuntimeException $e) {
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
