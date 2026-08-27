<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\Pinterest\PinterestConnectionService;
use App\PUB\Errors\PubErrorReporter;
use PDO;
use Throwable;

/**
 * PINTEREST OAUTH START ENDPOINT
 *
 * Browser-facing OAuth handoff.
 *
 * Owns only:
 *   - session/state creation
 *   - optional safe return path
 *   - redirect to Pinterest authorization
 *
 * Provider-specific OAuth URL construction lives in
 * PinterestConnectionService.
 */
final class PinterestOAuthStartEndpoint
{
    private const STATE_TTL_SECONDS = 900;

    public static function handle(
        PDO $pdo
    ): void {
        if (
            (
                $_SERVER['REQUEST_METHOD']
                ?? 'GET'
            ) !== 'GET'
        ) {
            http_response_code(405);
            echo 'GET only.';
            return;
        }


        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }


        $projectRoot =
            dirname(
                __DIR__,
                3
            );

        $errors =
            new PubErrorReporter(
                $projectRoot
                . '/app/PUB/Errors/pub_errors.log'
            );


        try {
            $state =
                bin2hex(
                    random_bytes(32)
                );

            $_SESSION[
                'pub_pinterest_oauth_state'
            ] =
                $state;

            $_SESSION[
                'pub_pinterest_oauth_state_created_at'
            ] =
                time();

$_SESSION[
    'pub_pinterest_oauth_return'
] =
    '/admin/pub?stage=dispatch';


            $service =
                new PinterestConnectionService(
                    $pdo
                );


            header(
                'Location: '
                . $service
                    ->authorizationUrl(
                        $state
                    ),
                true,
                302
            );

        } catch (Throwable $e) {
            $errors->report(
                $e,
                [
                    'stage' =>
                        'dispatch',

                    'code' =>
                        'pinterest_oauth_start_failure',
                ]
            );


            self::redirectBack(
                'error',
                $e->getMessage()
            );
        }
    }


    private static function safeReturnPath(
        mixed $value
    ): string {
        $path =
            trim(
                (string)$value
            );


        /*
         * Only allow local absolute paths.
         * Do not permit an OAuth flow to become an open redirect.
         */
        if (
            $path === ''
            || !str_starts_with(
                $path,
                '/'
            )
            || str_starts_with(
                $path,
                '//'
            )
        ) {
            return '/admin/pub?stage=dispatch';
        }


        return $path;
    }


    private static function redirectBack(
        string $status,
        string $message = ''
    ): void {
        $return =
            self::safeReturnPath(
                $_SESSION[
                    'pub_pinterest_oauth_return'
                ]
                ?? '/admin/pub?stage=dispatch'
            );


        $separator =
            str_contains(
                $return,
                '?'
            )
                ? '&'
                : '?';


        $query =
            http_build_query(
                [
                    'pinterest_auth' =>
                        $status,

                    'message' =>
                        $message,
                ]
            );


        header(
            'Location: '
            . $return
            . $separator
            . $query,
            true,
            302
        );
    }
}
