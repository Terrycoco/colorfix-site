<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Driver;

use PDO;

/**
 * Contract for any Shipper that can hand a long-running route to a driver.
 *
 * The driving company is universal. It knows only that a route class can
 * rebuild itself in the driver process and execute driverRoute().
 */
interface DispatchDriverRouteContract
{
    public static function forDriver(
        PDO $pdo,
        string $projectRoot
    ): self;


    /**
     * Execute the specialist-authored delivery route.
     *
     * The method may make one trip or twenty trips. The driving company
     * does not interpret any of them. A successful route returns the final
     * external shipping receipt.
     */
    public function driverRoute(
        DispatchDriverJob $job,
        array $package
    ): array;
}
