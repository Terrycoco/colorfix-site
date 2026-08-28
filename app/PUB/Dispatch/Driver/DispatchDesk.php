<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Driver;

use App\PUB\Dispatch\DispatchManager;
use PDO;

/**
 * DISPATCH DESK
 *
 * Universal return desk for every one-shot driver.
 *
 * Drivers never update PUB lifecycle state themselves. They report the
 * outcome here. The Desk wakes a fresh DispatchManager and hands the
 * Manager the asset ID plus receipt/error.
 */
final class DispatchDesk
{
    public function __construct(
        private PDO $pdo,
        private string $projectRoot,
    ) {}


    public function reportSuccess(
        DispatchDriverJob $job,
        array $receipt
    ): array {
        return $this->manager()
            ->completeShipment(
                $job->pubAssetId(),
                $receipt
            );
    }


    public function reportFailure(
        DispatchDriverJob $job,
        string $code,
        string $message
    ): array {
        return $this->manager()
            ->failShipment(
                $job->pubAssetId(),
                $code,
                $message
            );
    }


    public function reportTimeout(
        DispatchDriverJob $job
    ): array {
        return $this->reportFailure(
            $job,
            'dispatch_timeout',
            'Dispatch driver exceeded its maximum runtime of '
            . $job->timeoutSeconds()
            . ' seconds.'
        );
    }


    private function manager(): DispatchManager
    {
        return new DispatchManager(
            $this->pdo,
            $this->projectRoot
        );
    }
}
