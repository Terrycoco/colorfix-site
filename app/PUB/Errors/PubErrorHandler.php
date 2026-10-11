<?php
declare(strict_types=1);

namespace App\PUB\Errors;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Contracts\ErrorHandlingResult;
use App\ERR\Contracts\RecoveryStepResult;
use App\ERR\Contracts\RecoveryStepInterface;
use App\PUB\Dispatch\DispatchManager;
use App\PUB\Create\CreateManager;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Errors\Steps\CheckVideoWorker;
use Throwable;

/** PUB decides how its interrupted CREATE work is safely re-attempted. */
final class PubErrorHandler implements ErrorHandlerInterface
{
    public function __construct(private VideoWorkerHealthService $workerHealth) {}

    public function steps(ErrorContext $context): array
    {
        if ($context->department !== 'PUB') {
            throw new \InvalidArgumentException('Unsupported recovery department.');
        }
        if (in_array($context->errNum, [4002, 4003], true)) {
            return [new class implements RecoveryStepInterface {
                public function key(): string { return 'retry_dispatch'; }
                public function description(): string { return 'Awaiting manual publish retry'; }
                public function execute(ErrorContext $context): RecoveryStepResult {
                    return RecoveryStepResult::waiting('Review the publishing failure, correct the cause, then click Recover.');
                }
            }];
        }
        if ($context->errNum !== 4001) {
            throw new \InvalidArgumentException('Unsupported PUB recovery error.');
        }
        return [new CheckVideoWorker($this->workerHealth)];
    }

    public function outcome(ErrorContext $context, array $results): ErrorHandlingResult
    {
        if (in_array($context->errNum, [4002, 4003], true)) {
            return ErrorHandlingResult::waiting('Correct the publishing failure, then click Recover.');
        }
        $result = $results['check_video_worker'] ?? null;
        if (!$result instanceof RecoveryStepResult) {
            return ErrorHandlingResult::failed('Worker health check did not execute.');
        }
        return match ($result->status) {
            RecoveryStepResult::SUCCESS => ErrorHandlingResult::resolved($result->detail),
            RecoveryStepResult::WAITING => ErrorHandlingResult::waiting($result->detail),
            default => ErrorHandlingResult::failed($result->detail ?: 'Worker health check failed.'),
        };
    }

    /** Dispatch owns the retry; ERR owns the saved bookmark and Working lifecycle. */
    public function recoverDispatch(ErrorContext $context, DispatchManager $dispatch): RecoveryStepResult
    {
        if ($context->department !== 'PUB' || !in_array($context->errNum, [4002, 4003], true)) {
            return RecoveryStepResult::failed('Unsupported publishing recovery.');
        }
        $bookmark = $context->bookmark ?? [];
        $params = $bookmark['params'] ?? [];
        $assetId = is_array($params) ? (int)($params['pub_asset_id'] ?? 0) : 0;
        if (($bookmark['operation'] ?? null) !== 'PUB.DISPATCH.shipOne' || $assetId <= 0
            || $assetId !== (int)$context->resourceId) {
            return RecoveryStepResult::failed('Publishing bookmark does not match the affected asset.');
        }
        try {
            $result = $dispatch->shipOneForErrRecovery($assetId);
            if (!empty($result['ok'])) {
                return RecoveryStepResult::success('PUB accepted the recovered publishing order.');
            }
            $failure = $result['failed'] ?? [];
            $detail = is_array($failure) ? ($failure['error'] ?? null) : null;
            return RecoveryStepResult::waiting((string)($detail ?: 'Publishing is still blocked. Correct the problem and retry.'));
        } catch (Throwable $failure) {
            return RecoveryStepResult::waiting('Publishing retry was not accepted: ' . $failure->getMessage());
        }
    }

    /** Manual Recover: never clear ERR work until CREATE accepts the exact saved order. */
    public function recover(ErrorContext $context, CreateManager $create): RecoveryStepResult
    {
        if ($context->department !== 'PUB' || $context->errNum !== 4001) {
            return RecoveryStepResult::failed('Unsupported PUB recovery error.');
        }

        $bookmark = $context->bookmark;
        if (($bookmark['operation'] ?? null) !== 'PUB.CREATE.processBatch') {
            return RecoveryStepResult::failed('No valid CREATE bookmark. Original work must be restarted manually.');
        }
        $params = $bookmark['params'] ?? null;
        $orders = is_array($params) ? ($params['orders'] ?? null) : null;
        $policy = is_array($params) ? ($params['existing_policy'] ?? 'check') : 'check';
        if (!is_array($orders) || count($orders) !== 1 || !is_array($orders[0] ?? null)
            || !is_array($policy) && !is_string($policy)) {
            return RecoveryStepResult::failed('Saved CREATE order is incomplete.');
        }

        try {
            $health = $this->workerHealth->status();
            if (empty($health['alive'])) {
                return RecoveryStepResult::waiting(
                    'Video worker is still offline. Start the worker on your Mac, then try Recover again.'
                );
            }

            $result = $create->processBatch($orders, $policy);
            $accepted = count($result['created'] ?? []) + count($result['queued'] ?? []);
            if ($accepted === 1 && empty($result['failed']) && empty($result['existing_asset_warning'])) {
                return RecoveryStepResult::success('PUB accepted the recovered CREATE order.');
            }
            if (!empty($result['existing_asset_warning'])) {
                return RecoveryStepResult::failed(
                    'PUB requires an existing-asset decision. Open CREATE to review it; bookmark retained.'
                );
            }
            $firstFailure = $result['failed'][0]['error'] ?? 'PUB did not accept this CREATE order.';
            return RecoveryStepResult::failed((string)$firstFailure);
        } catch (Throwable $failure) {
            return RecoveryStepResult::failed('PUB could not rerun CREATE: ' . $failure->getMessage());
        }
    }
}
