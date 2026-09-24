<?php
declare(strict_types=1);

namespace App\PUB\Errors;

/**
 * STABLE PUB ERROR CODES
 *
 * Keep this list small and evidence-driven.
 * Unknown failures remain ordinary pub_failure/error records until
 * repeated real-world failures justify a dedicated code and policy.
 */
final class PubErrorCode
{
    public const PUB_FAILURE =
        'pub_failure';

    public const DISPATCH_AUTH_REVOKED =
        'dispatch_auth_revoked';


    private function __construct()
    {
    }
}
