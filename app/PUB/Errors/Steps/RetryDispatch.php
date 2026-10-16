<?php
declare(strict_types=1);

namespace App\PUB\Errors\Steps;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\PendingRecoveryStepInterface;
use App\ERR\Contracts\RecoveryStepResult;
use App\PUB\Dispatch\DispatchManager;
use App\PUB\Errors\PubErrorHandler;

/** PUB owns the operation; ERR controls execution and recording. */
final class RetryDispatch implements PendingRecoveryStepInterface
{
    public function __construct(private PubErrorHandler $handler, private DispatchManager $dispatch) {}

    public function key(): string { return 'retry_dispatch'; }
    public function description(): string { return 'Retry publishing the existing asset using its saved DISPATCH bookmark'; }
    public function check(ErrorContext $context): RecoveryStepResult
    {
        $bookmark = $context->bookmark ?? [];
        $params = $bookmark['params'] ?? [];
        $assetId = is_array($params) ? (int)($params['pub_asset_id'] ?? 0) : 0;
        if (($bookmark['operation'] ?? null) !== 'PUB.DISPATCH.shipOne'
            || $assetId <= 0 || $assetId !== (int)$context->resourceId) {
            return RecoveryStepResult::failed('DISPATCH bookmark does not match the affected asset.');
        }
        $asset = $this->dispatch->recoveryShipmentState($assetId);
        if (!$asset) return RecoveryStepResult::failed('The PUB asset no longer exists.');
        $stage = strtolower(trim((string)($asset['pipeline_stage'] ?? '')));
        if ($stage === 'shipped') {
            return RecoveryStepResult::success("PUB asset #{$assetId} shipped successfully; receipt recorded.");
        }
        if ($stage === 'shipping') {
            return RecoveryStepResult::waiting("PUB asset #{$assetId} is still shipping.");
        }
        if ($stage === 'error') {
            $reason = (string)($asset['error_message'] ?? $asset['last_error'] ?? 'DISPATCH reported an error.');
            return RecoveryStepResult::failed($reason);
        }
        return RecoveryStepResult::waiting("PUB asset #{$assetId} is {$stage}; shipment not yet confirmed.");
    }

    public function execute(ErrorContext $context): RecoveryStepResult
    {
        return $this->handler->recoverDispatch($context, $this->dispatch);
    }
}
