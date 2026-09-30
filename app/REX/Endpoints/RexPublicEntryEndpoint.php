<?php

declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexShareMetadata;
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

        /*
         * Route REXes must enter through the public React REX shell.
         * RexPublicPage records the REX analytics event (including src)
         * before navigating to the resolved route. Redirecting here would
         * bypass that tracking entirely.
         */
        if (
            $result->behavior === RexResolutionBehavior::REDIRECT
            && $result->resolverKey === 'route'
        ) {
            self::renderReactShell($indexPath, $result->shareMetadata);
        }

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
            self::renderReactShell($indexPath, $result->shareMetadata);
        }

        self::notFound();
    }

    private static function renderReactShell(
        string $indexPath,
        RexShareMetadata $shareMetadata
    ): never {
        $html = is_file($indexPath)
            ? (string)file_get_contents($indexPath)
            : '';

        if ($html === '') {
            self::notFound();
        }

        $title = trim((string)($shareMetadata->title ?? ''));
        $description = trim((string)($shareMetadata->description ?? ''));
        $imageUrl = self::absoluteUrl($shareMetadata->imageUrl);
        $shareUrl = self::currentAbsoluteUrl();

        /*
         * The Vite shell contains site-wide social metadata. REX pages need
         * object-specific metadata, so remove the static OG/Twitter tags
         * before inserting the resolved REX metadata below.
         */
        $html = preg_replace(
            '/<meta\s+(?:property|name)=["\'](?:og:[^"\']+|twitter:[^"\']+)["\'][^>]*>\s*/i',
            '',
            $html
        ) ?? $html;

        $tags = [];

        if ($title !== '') {
            $escapedTitle = self::escapeHtml($title);

            $html = preg_replace(
                '/<title\b[^>]*>.*?<\/title>/is',
                '<title>' . $escapedTitle . '</title>',
                $html,
                1
            ) ?? $html;

            $tags[] = '<meta property="og:title" content="' . $escapedTitle . '">';
            $tags[] = '<meta name="twitter:title" content="' . $escapedTitle . '">';
        }

        if ($description !== '') {
            $escapedDescription = self::escapeHtml($description);
            $tags[] = '<meta property="og:description" content="' . $escapedDescription . '">';
            $tags[] = '<meta name="twitter:description" content="' . $escapedDescription . '">';
        }

        if ($imageUrl !== '') {
            $escapedImage = self::escapeHtml($imageUrl);
            $tags[] = '<meta property="og:image" content="' . $escapedImage . '">';
            $tags[] = '<meta name="twitter:image" content="' . $escapedImage . '">';
            $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        }

        if ($shareUrl !== '') {
            $tags[] = '<meta property="og:url" content="' . self::escapeHtml($shareUrl) . '">';
        }

        $tags[] = '<meta property="og:type" content="website">';

        if ($tags !== []) {
            $metaHtml = implode("\n", $tags) . "\n";
            $html = preg_replace(
                '/<\/head>/i',
                $metaHtml . '</head>',
                $html,
                1
            ) ?? $html;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private static function absoluteUrl(?string $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $value) === 1) {
            return $value;
        }

        if (!str_starts_with($value, '/')) {
            $value = '/' . $value;
        }

        return self::requestOrigin() . $value;
    }

    private static function currentAbsoluteUrl(): string
    {
        $requestUri = trim((string)($_SERVER['REQUEST_URI'] ?? ''));

        if ($requestUri === '') {
            return '';
        }

        return self::requestOrigin() . $requestUri;
    }

    private static function requestOrigin(): string
    {
        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = $forwardedProto !== ''
            ? strtolower(explode(',', $forwardedProto)[0])
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            return '';
        }

        return $scheme . '://' . $host;
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
