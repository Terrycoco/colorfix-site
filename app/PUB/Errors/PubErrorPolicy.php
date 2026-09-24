<?php
declare(strict_types=1);

namespace App\PUB\Errors;

/**
 * CENTRAL PUB ERROR HANDLING POLICY
 *
 * The code identifies WHAT happened.
 * This class decides HOW PUB treats that known condition.
 */
final class PubErrorPolicy
{
    /**
     * @return array{
     *   severity: string,
     *   email: bool
     * }
     */
    public static function for(
        string $code
    ): array {
        return match ($code) {
            PubErrorCode::DISPATCH_AUTH_REVOKED => [
                'severity' =>
                    'critical',

                'email' =>
                    true,
            ],

            default => [
                'severity' =>
                    'error',

                'email' =>
                    false,
            ],
        };
    }


    private function __construct()
    {
    }
}
