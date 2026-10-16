<?php
declare(strict_types=1);

namespace App\PUB\Errors;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Contracts\RecoveryProviderInterface;
use App\PUB\Create\CreateManager;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Dispatch\DispatchManager;
use PDO;

/** PUB owns all registration and dependency construction for its recoveries. */
final class RecoveryProvider implements RecoveryProviderInterface
{
    public function handler(ErrorContext $context, string $handlerKey, PDO $pdo, string $projectRoot): ErrorHandlerInterface
    {
        if ($context->department !== 'PUB' || $handlerKey === '') {
            throw new \InvalidArgumentException('Invalid PUB recovery registration.');
        }
        if ($context->errNum === 4001) {
            return new PubErrorHandler(
                new VideoWorkerHealthService($projectRoot),
                new CreateManager($pdo, $projectRoot)
            );
        }
        if (in_array($context->errNum, [4002, 4003], true)) {
            return new PubErrorHandler(
                new VideoWorkerHealthService($projectRoot),
                null, new DispatchManager($pdo, $projectRoot)
            );
        }
        throw new \InvalidArgumentException('No PUB recovery procedure registered for this error.');
    }
}
