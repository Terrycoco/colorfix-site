<?php
declare(strict_types=1);

namespace App\PUB\Errors\Steps;

use App\PUB\Dispatch\DispatchManager;

/** Return an asset already in Dispatch custody to the ordinary shipping dock. */
final class RequeueDispatchAsset
{
    public function execute(DispatchManager $dispatch, array $asset, string $code, string $message, ?array $diagnostics): array
    {
        return $dispatch->requeueShipment((int)$asset['pub_asset_id']);
    }
}
