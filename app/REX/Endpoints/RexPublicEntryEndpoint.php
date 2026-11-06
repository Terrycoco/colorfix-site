<?php

declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use App\REX\Services\RexIndexingPolicy;
use PDO;
use Throwable;
use App\REX\Exceptions\RexNotFoundException;

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

            $indexable = self::isIndexablePlaylist($pdo, $result);
            $bootstrap = $indexable ? [
                'token' => $token,
                'payload' => RexResolveEndpoint::payload($result, $repo->findByToken($token)),
            ] : null;

        } catch (RexNotFoundException) {
            self::notFound();
        } catch (Throwable $error) {
            RexResolveEndpoint::logFailure($error);
            self::unavailable();
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: ' . ($indexable ? 'index, follow' : 'noindex, noarchive'));

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
            // Reuse the plan already resolved under the normal access rules.
            // Only eligible marketing playlists embed data; client resources keep
            // their existing API flow and exclusions.
            self::renderReactShell($indexPath, $result->shareMetadata, $indexable, $bootstrap, $result->destination['playback_plan'] ?? []);
        }

        self::notFound();
    }

    private static function isIndexablePlaylist(PDO $pdo, RexResolutionResult $result): bool
    {
        return RexIndexingPolicy::allows($pdo, $result);
    }

    private static function applyRobotsMetadata(string $html, bool $indexable): string
    {
        // Replace every inherited robots tag, regardless of attribute order, and
        // explicitly preserve exclusion even if a future shell defaults to indexing.
        $html = preg_replace(
            '/<meta\b(?=[^>]*\bname\s*=\s*["\']robots["\'])[^>]*>\s*/i',
            '',
            $html
        ) ?? $html;
        $tag = '<meta name="robots" content="'
            . ($indexable ? 'index, follow' : 'noindex, noarchive') . '">' . "\n";
        return preg_replace('/<\/head>/i', $tag . '</head>', $html, 1) ?? $html;
    }

    private static function renderReactShell(
        string $indexPath,
        RexShareMetadata $shareMetadata,
        bool $indexable = false,
        ?array $bootstrap = null,
        array $pageMetadata = []
    ): never {
        try {
            $html = is_file($indexPath)
                ? (string)file_get_contents($indexPath)
                : '';

            if ($html === '') {
                self::unavailable();
            }

            $html = self::applyRobotsMetadata($html, $indexable);

            $title = trim((string)($shareMetadata->title ?? ''));
            $description = trim((string)($shareMetadata->description ?? ''));
            $imageUrl = self::absoluteUrl($shareMetadata->imageUrl);
            $shareUrl = self::publicRexUrl();

            // The REX address identifies the experience; query parameters only carry
            // tracking/navigation state. Replace any inherited shell canonical.
            $html = preg_replace(
                '/<link\b(?=[^>]*\brel\s*=\s*["\']canonical["\'])[^>]*>\s*/i',
                '',
                $html
            ) ?? $html;

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

            $pageTitle = trim((string)($pageMetadata['page_title'] ?? ''));
            $pageTitle = $pageTitle !== '' ? $pageTitle : $title;
            $pageDescription = trim((string)($pageMetadata['meta_description'] ?? ''));
            $pageDescription = $pageDescription !== '' ? $pageDescription : $description;
            $tags = [];

            if ($pageTitle !== '') {
                $html = preg_replace_callback(
                    '/<title\b[^>]*>.*?<\/title>/is',
                    static fn () => '<title>' . self::escapeHtml($pageTitle) . '</title>',
                    $html,
                    1
                ) ?? $html;
            }

            if ($pageDescription !== '') {
                $html = preg_replace(
                    '/<meta\b(?=[^>]*\bname\s*=\s*["\']description["\'])[^>]*>\s*/i',
                    '',
                    $html
                ) ?? $html;
                $tags[] = '<meta name="description" content="' . self::escapeHtml($pageDescription) . '">';
            }

            if ($title !== '') {
                $escapedTitle = self::escapeHtml($title);

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
                $tags[] = '<link rel="canonical" href="' . self::escapeHtml($shareUrl) . '">';
                $tags[] = '<meta property="og:url" content="' . self::escapeHtml($shareUrl) . '">';
            }

            $tags[] = '<meta property="og:type" content="website">';

            if ($tags !== []) {
                $metaHtml = implode("\n", $tags) . "\n";
                $html = preg_replace_callback(
                    '/<\/head>/i',
                    static fn () => $metaHtml . '</head>',
                    $html,
                    1
                ) ?? $html;
            }

            if ($bootstrap !== null) {
                $json = json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                $html = str_replace('</head>', '<script type="application/json" id="colorfix-rex-bootstrap">' . $json . '</script></head>', $html);
            }
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        } catch (Throwable $error) {
            RexResolveEndpoint::logFailure($error);
            self::unavailable();
        }
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

    private static function publicRexUrl(): string
    {
        $token = trim((string)($_GET['token'] ?? ''));

        if ($token === '') {
            return '';
        }

        return self::requestOrigin() . '/t/' . rawurlencode($token);
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

    private static function unavailable(): never
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 60');
        header('X-Robots-Tag: noindex, noarchive');
        echo '<!doctype html><html><head><meta name="robots" content="noindex, noarchive">'
            . '<title>ColorFix temporarily unavailable</title></head><body>'
            . 'Unable to load this ColorFix link right now. Please try again.</body></html>';
        exit;
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
