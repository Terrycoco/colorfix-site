<?php
declare(strict_types=1);

namespace App\PUB\Errors\Steps;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\RecoveryStepInterface;
use App\ERR\Contracts\RecoveryStepResult;
use App\PUB\Create\CreateManager;
use App\PUB\Errors\PubErrorHandler;

final class RetryCreate implements RecoveryStepInterface
{
    public function __construct(private PubErrorHandler $handler, private CreateManager $create) {}
    public function key(): string { return 'retry_create'; }
    public function description(): string { return 'Retry the saved CREATE order after confirming worker health'; }
    public function execute(ErrorContext $context): RecoveryStepResult
    {
        return $this->handler->recover($context, $this->create);
    }
}
