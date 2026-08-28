<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Driver;

use App\PUB\Repos\PdoPubAssetRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * THE ONE-SHOT DRIVER PROCESS.
 *
 * One Runner receives one DriverJob, performs exactly that specialist's
 * driverRoute(), reports the result to DispatchDesk, and exits.
 *
 * It knows nothing about Pinterest, YouTube, OAuth, boards, uploads, or
 * how many trips the route contains.
 */
final class DispatchDriverRunner
{
    private PdoPubAssetRepository $assets;
    private DispatchDesk $desk;


    public function __construct(
        private PDO $pdo,
        private string $projectRoot,
    ) {
        $this->assets =
            new PdoPubAssetRepository(
                $this->pdo
            );


        $this->desk =
            new DispatchDesk(
                $this->pdo,
                $this->projectRoot
            );
    }


    public function run(
        DispatchDriverJob $job
    ): array {
        try {
            $asset =
                $this->assets
                    ->getShippingById(
                        $job->pubAssetId()
                    );


            if ($asset === null) {
                throw new RuntimeException(
                    'Dispatch driver could not find its box at the Shipping dock.'
                );
            }


            $package =
                is_array(
                    $asset[
                        'package'
                    ]
                    ?? null
                )
                    ? $asset[
                        'package'
                    ]
                    : [];


            if ($package === []) {
                throw new RuntimeException(
                    'Dispatch driver received a shipping box without a sealed package.'
                );
            }


            $routeClass =
                $job->routeClass();


            if (!class_exists($routeClass)) {
                throw new RuntimeException(
                    "Dispatch driver route class '{$routeClass}' was not found."
                );
            }


            if (!is_subclass_of(
                $routeClass,
                DispatchDriverRouteContract::class
            )) {
                throw new RuntimeException(
                    "Dispatch driver route class '{$routeClass}' does not implement DispatchDriverRouteContract."
                );
            }


            /** @var DispatchDriverRouteContract $route */
            $route =
                $routeClass::forDriver(
                    $this->pdo,
                    $this->projectRoot
                );


            $receipt =
                $route->driverRoute(
                    $job,
                    $package
                );


            if ($receipt === []) {
                throw new RuntimeException(
                    'Dispatch driver route finished without a receipt.'
                );
            }


            $state =
                $this->desk
                    ->reportSuccess(
                        $job,
                        $receipt
                    );


            return [
                'ok' =>
                    true,

                'pub_asset_id' =>
                    $job->pubAssetId(),

                'state' =>
                    $state,
            ];

        } catch (Throwable $e) {
            try {
                $state =
                    $this->desk
                        ->reportFailure(
                            $job,
                            'dispatch_driver_failure',
                            $e->getMessage()
                        );

            } catch (Throwable $settleError) {
                error_log(
                    'Dispatch driver could not report failure for asset #'
                    . $job->pubAssetId()
                    . ': '
                    . $settleError->getMessage()
                );

                throw $e;
            }


            error_log(
                'Dispatch driver failed for asset #'
                . $job->pubAssetId()
                . ': '
                . $e->getMessage()
            );


            return [
                'ok' =>
                    false,

                'pub_asset_id' =>
                    $job->pubAssetId(),

                'error' =>
                    $e->getMessage(),

                'state' =>
                    $state,
            ];
        }
    }
}
