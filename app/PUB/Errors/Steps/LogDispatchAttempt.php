<?php
declare(strict_types=1);

namespace App\PUB\Errors\Steps;

use App\PUB\Dispatch\DispatchManager;

/** Record a failed publishing attempt without opening an ERR incident. */
final class LogDispatchAttempt
{
    public function execute(DispatchManager $dispatch, array $asset, string $code, string $message, ?array $diagnostics): void
    {
        $dispatch->recordFailedDispatchAttempt((int)$asset['pub_asset_id'], $code, $message, $diagnostics);
    }
}
