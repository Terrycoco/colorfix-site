<?php

declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\DTO\RexResolutionBehavior;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use PDO;
use Throwable;

final class RexPublicEntryEndpoint
{
    public static function handle(PDO $pdo, string $indexPath): void
    {
        $token = trim((string)($_GET['token'] ?? ''));

        if ($token === '') {
            self::notFound();
        }

        try {
            $repo = new PdoRexReservationRepository($pdo);
            $registry = RexResolverRegistryFactory::build($pdo);

            $resolver = new RexResolver(
                $repo,
                $registry
            );

            $result = $resolver->resolveToken($token, [
                'entry_point' => 't',
                'src' => $_GET['src'] ?? null,
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'return_to' => trim((string)($_GET['return_to'] ?? '')),
                'origin_playlist' => trim((string)($_GET['origin_playlist'] ?? '')),
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

        } catch (Throwable) {
            self::notFound();
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
                self::notFound();
            }

            header('Location: ' . $target, true, 302);
            exit;
        }

        /*
         * Any REX resolver that returns RENDER belongs to the public React
         * experience shell. The server entry point must not maintain its own
         * resolver-key allowlist.
         */
        if ($result->behavior === RexResolutionBehavior::RENDER) {
            $html = is_file($indexPath)
                ? (string)file_get_contents($indexPath)
                : '';

            if ($html === '') {
                self::notFound();
            }

            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        self::notFound();
    }

    private static function notFound(): never
    {
        http_response_code(404);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex, noarchive');

        echo '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex, noarchive">'
            . '<title>ColorFix Link Not Found</title></head>'
            . '<body>Link not found.</body></html>';

        exit;
    }
}
