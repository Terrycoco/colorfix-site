<?php
declare(strict_types=1);

namespace App\POD\Endpoints;

use App\POD\Managers\PodManager;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Provides POD's initial HTML with server-side OG metadata; React still owns playback. */
final class PodPublicEntryEndpoint
{
    public static function handle(PDO $pdo, string $indexPath): void
    {
        try {
            $raw = $_GET['photos'] ?? null;
            if (!is_string($raw) || $raw === '') {
                throw new InvalidArgumentException('Missing photos parameter.');
            }
            $photoIds = [];
            foreach (explode(',', $raw) as $part) {
                if (!preg_match('/^[1-9][0-9]*$/D', $part)) {
                    throw new InvalidArgumentException('Invalid photo ID.');
                }
                $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('Invalid photo ID.');
                }
                $photoIds[$id] = $id;
            }
            // Reuse POD's existing validation, permission checks, and share image selection.
            $manager = new PodManager($pdo);
            $plan = $manager->buildPlaybackPlan(
                array_values($photoIds),
                self::booleanParam('before', true),
                self::booleanParam('captions', true),
                is_string($_GET['src'] ?? null) ? $_GET['src'] : null
            );

            $html = is_file($indexPath) ? file_get_contents($indexPath) : false;
            if (!is_string($html) || $html === '') {
                throw new \RuntimeException('React HTML shell is unavailable.');
            }

            $count = count($photoIds);
            $title = $count === 1
                ? 'ColorFix Design Photo | ColorFix by Terry'
                : 'ColorFix Design Showcase (' . $count . ' Photos) | ColorFix by Terry';
            $description = trim((string)($plan['share_description'] ?? ''));
            $image = self::absoluteUrl((string)($plan['share_image_url'] ?? ''));
            $shareUrl = self::absoluteUrl((string)($_SERVER['REQUEST_URI'] ?? ''));

            // Remove generic social tags from Vite's HTML before injecting request-specific ones.
            $html = preg_replace(
                '/<meta\s+(?:property|name)=["\'](?:og:[^"\']+|twitter:[^"\']+)["\'][^>]*>\s*/i',
                '',
                $html
            ) ?? $html;
            $escapedTitle = self::escape($title);
            $html = preg_replace('/<title\b[^>]*>.*?<\/title>/is',
                '<title>' . $escapedTitle . '</title>', $html, 1) ?? $html;
            $tags = [
                '<meta property="og:title" content="' . $escapedTitle . '">',
                '<meta name="twitter:title" content="' . $escapedTitle . '">',
                '<meta property="og:type" content="website">',
            ];
            if ($description !== '') {
                $escaped = self::escape($description);
                $tags[] = '<meta property="og:description" content="' . $escaped . '">';
                $tags[] = '<meta name="twitter:description" content="' . $escaped . '">';
            }
            if ($image !== '') {
                $escaped = self::escape($image);
                $tags[] = '<meta property="og:image" content="' . $escaped . '">';
                $tags[] = '<meta name="twitter:image" content="' . $escaped . '">';
                $tags[] = '<meta name="twitter:card" content="summary_large_image">';
            }
            if ($shareUrl !== '') {
                $tags[] = '<meta property="og:url" content="' . self::escape($shareUrl) . '">';
            }
            $html = preg_replace('/<\/head>/i', implode("\n", $tags) . "\n</head>", $html, 1) ?? $html;

            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Robots-Tag: noindex, noarchive');
            echo $html;
        } catch (InvalidArgumentException $e) {
            self::fail(400);
        } catch (\DomainException $e) {
            self::fail(404);
        } catch (Throwable $e) {
            // Do not leak internal server exceptions or private photo details publicly.
            error_log('POD public entry failed: ' . $e->getMessage());
            self::fail(500);
        }
    }

    private static function booleanParam(string $name, bool $default): bool
    {
        if (!array_key_exists($name, $_GET)) {
            return $default;
        }
        $raw = $_GET[$name];
        if ($raw !== '0' && $raw !== '1') {
            throw new InvalidArgumentException('Invalid boolean parameter.');
        }
        return $raw === '1';
    }

    private static function absoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('~^https?://~i', $path)) return $path;
        if ($path[0] !== '/') $path = '/' . $path;
        // Fixed canonical host prevents untrusted Host headers altering social preview URLs.
        return 'https://colorfix.terrymarr.com' . $path;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function fail(int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $status === 400 ? 'Invalid POD link.' : 'POD unavailable.';
    }
}
