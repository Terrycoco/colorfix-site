<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\Auth\YouTubeAuthService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * YOUTUBE OAUTH START ENDPOINT
 *
 * Creates/stores OAuth state, remembers the safe local return path,
 * then sends the browser to Google's consent screen.
 */
final class YouTubeOAuthStartEndpoint
{
    private const STATE_SESSION_KEY = 'pub_youtube_oauth_state';
    private const RETURN_SESSION_KEY = 'pub_youtube_oauth_return';
    private const DEFAULT_RETURN = '/admin/pub?stage=dispatch';

    public static function handle(PDO $pdo): void
    {
        try {
            self::ensureSession();

            $returnPath = self::safeReturnPath(
                (string)($_GET['return'] ?? self::DEFAULT_RETURN)
            );

            $state = bin2hex(random_bytes(32));

            $_SESSION[self::STATE_SESSION_KEY] = $state;
            $_SESSION[self::RETURN_SESSION_KEY] = $returnPath;

            $auth = new YouTubeAuthService($pdo);
            $url = $auth->authorizationUrl($state);

            header('Location: ' . $url, true, 302);
            exit;

        } catch (Throwable $e) {
            $returnPath = self::DEFAULT_RETURN;

            if (session_status() === PHP_SESSION_ACTIVE) {
                $returnPath = self::safeReturnPath(
                    (string)(
                        $_SESSION[self::RETURN_SESSION_KEY]
                        ?? self::DEFAULT_RETURN
                    )
                );
            }

            self::redirectWithResult(
                $returnPath,
                'error',
                $e->getMessage()
            );
        }
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!session_start()) {
            throw new RuntimeException(
                'Could not start YouTube OAuth session.'
            );
        }
    }

    private static function safeReturnPath(string $value): string
    {
        $value = trim($value);

        if (
            $value === ''
            || !str_starts_with($value, '/admin/')
            || str_starts_with($value, '//')
        ) {
            return self::DEFAULT_RETURN;
        }

        return $value;
    }

    private static function redirectWithResult(
        string $returnPath,
        string $status,
        string $message
    ): never {
        $separator = str_contains($returnPath, '?') ? '&' : '?';

        $url = $returnPath
            . $separator
            . http_build_query(
                [
                    'youtube_auth' => $status,
                    'message' => $message,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        header('Location: ' . $url, true, 302);
        exit;
    }
}
