<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\Auth\YouTubeAuthService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * YOUTUBE OAUTH CALLBACK ENDPOINT
 *
 * Google returns to the root oauth-google.php door, which delegates here.
 * This endpoint validates the state created by YouTubeOAuthStartEndpoint,
 * exchanges the code, persists credentials, and returns to PUB Dispatch.
 */
final class YouTubeOAuthCallbackEndpoint
{
    private const STATE_SESSION_KEY = 'pub_youtube_oauth_state';
    private const RETURN_SESSION_KEY = 'pub_youtube_oauth_return';
    private const DEFAULT_RETURN = '/admin/pub?stage=dispatch';

    public static function handle(PDO $pdo): void
    {
        self::ensureSession();

        $returnPath = self::safeReturnPath(
            (string)(
                $_SESSION[self::RETURN_SESSION_KEY]
                ?? self::DEFAULT_RETURN
            )
        );

        try {
            $expectedState = trim(
                (string)(
                    $_SESSION[self::STATE_SESSION_KEY]
                    ?? ''
                )
            );

            $receivedState = trim(
                (string)(
                    $_GET['state']
                    ?? ''
                )
            );

            unset($_SESSION[self::STATE_SESSION_KEY]);
            unset($_SESSION[self::RETURN_SESSION_KEY]);

            if (
                $expectedState === ''
                || $receivedState === ''
                || !hash_equals($expectedState, $receivedState)
            ) {
                throw new RuntimeException(
                    'YouTube OAuth state validation failed.'
                );
            }

            $providerError = trim(
                (string)(
                    $_GET['error']
                    ?? ''
                )
            );

            if ($providerError !== '') {
                $description = trim(
                    (string)(
                        $_GET['error_description']
                        ?? ''
                    )
                );

                throw new RuntimeException(
                    $description !== ''
                        ? "Google authorization failed: {$description}"
                        : "Google authorization failed: {$providerError}"
                );
            }

            $code = trim(
                (string)(
                    $_GET['code']
                    ?? ''
                )
            );

            if ($code === '') {
                throw new RuntimeException(
                    'YouTube OAuth callback did not include an authorization code.'
                );
            }

            $auth = new YouTubeAuthService($pdo);
            $auth->handleCallback($code);

            self::redirectWithResult(
                $returnPath,
                'connected',
                'YouTube connected.'
            );

        } catch (Throwable $e) {
            try {
                (new YouTubeAuthService($pdo))
                    ->markAuthError($e->getMessage());
            } catch (Throwable) {
                // Preserve the original OAuth failure.
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
