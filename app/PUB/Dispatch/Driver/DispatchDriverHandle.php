<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Driver;

/**
 * The small acknowledgement returned to a Shipper after the server has
 * successfully created its one-shot background driver process.
 */
final class DispatchDriverHandle
{
    public function __construct(
        private int $pid,
        private int $pubAssetId,
    ) {}


    public function pid(): int
    {
        return $this->pid;
    }


    public function pubAssetId(): int
    {
        return $this->pubAssetId;
    }


    public function toArray(): array
    {
        return [
            'pid' =>
                $this->pid,

            'pub_asset_id' =>
                $this->pubAssetId,
        ];
    }
}
