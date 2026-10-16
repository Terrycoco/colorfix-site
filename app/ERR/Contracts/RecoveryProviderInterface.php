<?php
declare(strict_types=1);

namespace App\ERR\Contracts;

use PDO;

/** Installed departments register their own manual recovery handlers. */
interface RecoveryProviderInterface
{
    public function handler(ErrorContext $context, string $handlerKey, PDO $pdo, string $projectRoot): ErrorHandlerInterface;
}
