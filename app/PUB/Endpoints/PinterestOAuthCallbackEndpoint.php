<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\Pinterest\PinterestConnectionService;
use App\PUB\Errors\PubErrorReporter;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PINTEREST OAUTH CALLBACK ENDPOINT
 *
 * Browser-facing OAuth return point.
 *
 * Owns:
 *   - callback state validation
 *   - callback age validation
 *   - provider denial/error handling
 *   - handing the authorization code to PinterestConnectionService
 *   - redirecting back to the PUB admin UI
 *
 * Token exchange and credential persistence remain inside PUB's
 * PinterestConnectionService.
 */
final class PinterestOAuthCallbackEndpoint
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

        $service =
            new PinterestConnectionService(
                $pdo
            );


        try {
            $providerError =
                trim(
                    (string)(
                        $_GET['error']
                        ?? ''
                    )
                );

            $providerDescription =
                trim(
                    (string)(
                        $_GET[
                            'error_description'
                        ]
                        ?? ''
                    )
                );


            if ($providerError !== '') {
                $message =
                    $providerDescription !== ''
                        ? $providerDescription
                        : $providerError;

                $service
                    ->markAuthError(
                        $message
                    );

                self::consumeState();

                self::redirectBack(
                    'denied',
                    $message
                );

                return;
            }


            $code =
                trim(
                    (string)(
                        $_GET['code']
                        ?? ''
                    )
                );

            $state =
                trim(
                    (string)(
                        $_GET['state']
                        ?? ''
                    )
                );

            $expected =
                trim(
                    (string)(
                        $_SESSION[
                            'pub_pinterest_oauth_state'
                        ]
                        ?? ''
                    )
                );

            $createdAt =
                (int)(
                    $_SESSION[
                        'pub_pinterest_oauth_state_created_at'
                    ]
                    ?? 0
                );


            self::consumeState();


            if ($code === '') {
                throw new RuntimeException(
                    'Pinterest OAuth callback did not include a code.'
                );
            }


            if (
                $state === ''
                || $expected === ''
                || !hash_equals(
                    $expected,
                    $state
                )
            ) {
                throw new RuntimeException(
                    'Pinterest OAuth state validation failed.'
                );
            }


            $age =
                $createdAt > 0
                    ? time() - $createdAt
                    : PHP_INT_MAX;


            if (
                $age < 0
                || $age >
                    self::STATE_TTL_SECONDS
            ) {
                throw new RuntimeException(
                    'Pinterest OAuth state expired.'
                );
            }


            $service
                ->handleCallback(
                    $code
                );


            self::redirectBack(
                'connected',
                'Pinterest connected.'
            );

        } catch (Throwable $e) {
            try {
                $service
                    ->markAuthError(
                        $e->getMessage()
                    );
            } catch (Throwable) {
                /*
                 * Preserve the original callback failure.
                 */
            }


            $errors->report(
                $e,
                [
                    'stage' =>
                        'dispatch',

                    'code' =>
                        'pinterest_oauth_callback_failure',
                ]
            );


            self::redirectBack(
                'error',
                $e->getMessage()
            );
        }
    }


    private static function consumeState(): void
    {
        unset(
            $_SESSION[
                'pub_pinterest_oauth_state'
            ],
            $_SESSION[
                'pub_pinterest_oauth_state_created_at'
            ]
        );
    }


    private static function safeReturnPath(
        mixed $value
    ): string {
        $path =
            trim(
                (string)$value
            );


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


    unset(
        $_SESSION[
            'pub_pinterest_oauth_return'
        ]
    );


    /*
     * A URL fragment (#dispatch) must come AFTER the query string.
     *
     * Correct:
     *   /admin/pub?pinterest_auth=connected#dispatch
     *
     * Wrong:
     *   /admin/pub#dispatch?pinterest_auth=connected
     */
    $parts =
        explode(
            '#',
            $return,
            2
        );

    $base =
        $parts[0];

    $fragment =
        $parts[1]
        ?? '';


    $separator =
        str_contains(
            $base,
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


    $location =
        $base
        . $separator
        . $query;


    if ($fragment !== '') {
        $location .=
            '#'
            . $fragment;
    }


    header(
        'Location: '
        . $location,
        true,
        302
    );

    exit;
}
}
