<?php
declare(strict_types=1);

require __DIR__ . '/api/autoload.php';
require __DIR__ . '/api/db.php';

use App\REX\DTO\RexResolutionBehavior;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\PlaylistExperienceResolver;
use App\REX\Resolvers\RexResolverRegistry;
use App\REX\Resolvers\ViewerResolver;
use App\REX\Services\RexResolver;

function t_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, noarchive');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, noarchive"><title>ColorFix Link Not Found</title></head><body>Link not found.</body></html>';
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    t_not_found();
}

try {
    $repo = new PdoRexReservationRepository($pdo);

    $registry = new RexResolverRegistry();
    $registry->register(
        'playlist_experience',
        new PlaylistExperienceResolver($pdo)
    );
    $registry->register(
        'viewer',
        new ViewerResolver($pdo)
    );

    $resolver = new RexResolver($repo, $registry);

    $result = $resolver->resolveToken($token, [
        'entry_point' => 't',
        'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'return_to' => trim((string)($_GET['return_to'] ?? '')),
        'origin_playlist' => trim((string)($_GET['origin_playlist'] ?? '')),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
} catch (Throwable) {
    t_not_found();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, noarchive');

if ($result->behavior === RexResolutionBehavior::REDIRECT) {
    $target = trim((string)(
        $result->destination['url']
        ?? $result->destination['path']
        ?? ''
    ));

    if ($target === '') {
        t_not_found();
    }

    header('Location: ' . $target, true, 302);
    exit;
}

if (
    $result->behavior === RexResolutionBehavior::RENDER
    && $result->resolverKey === 'playlist_experience'
) {
    $indexPath = __DIR__ . '/index.html';
    $html = is_file($indexPath) ? (string)file_get_contents($indexPath) : '';
    if ($html === '') {
        t_not_found();
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

if (
    $result->behavior === RexResolutionBehavior::RENDER
    && $result->resolverKey === 'viewer'
) {
    $indexPath = __DIR__ . '/index.html';
    $html = is_file($indexPath) ? (string)file_get_contents($indexPath) : '';
    if ($html === '') {
        t_not_found();
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

t_not_found();
