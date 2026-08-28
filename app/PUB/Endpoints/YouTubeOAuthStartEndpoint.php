<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\Auth\YouTubeAuthService;
use App\PUB\Errors\PubErrorReporter;
use PDO;
use Throwable;

/**
 * YOUTUBE OAUTH START ENDPOINT
 *
 * Browser-facing OAuth handoff.
 *
 * Owns only:
 *   - session/state creation
 *   - redirect to Google authorization
 *   - return to PUB on failure
 *
 * Provider credentials and OAuth URL construction live in
 * Dispatch/Auth/YouTubeAuthService.
 */
final class YouTubeOAuthStartEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo 'GET only.';
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $projectRoot = dirname(__DIR__, 3);

        $errors = new PubErrorReporter(
            $projectRoot . '/app/PUB/Errors/pub_errors.log'
        );

        try {
            $state = bin2hex(random_bytes(32));

            $_SESSION['pub_youtube_oauth_state'] = $state;
            $_SESSION['pub_youtube_oauth_state_created_at'] = time();
            $_SESSION['pub_youtube_oauth_return'] =
                '/admin/pub?stage=dispatch';

            $service = new YouTubeAuthService($pdo);

            header(
                'Location: ' . $service->authorizationUrl($state),
                true,
                302
            );

            exit;

        } catch (Throwable $e) {
            $errors->report(
                $e,
                [
                    'stage' => 'dispatch',
                    'code' => 'youtube_oauth_start_failure',
                ]
            );

            self::redirectBack(
                'error',
                $e->getMessage()
            );
        }
    }

    private static function redirectBack(
        string $status,
        string $message = ''
    ): void {
        unset(
            $_SESSION['pub_youtube_oauth_state'],
            $_SESSION['pub_youtube_oauth_state_created_at']
        );

        $return = '/admin/pub?stage=dispatch';

        $query = http_build_query(
            [
                'youtube_auth' => $status,
                'message' => $message,
            ]
        );

        header(
            'Location: ' . $return . '&' . $query,
            true,
            302
        );

        exit;
    }
}
