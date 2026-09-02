<?php
declare(strict_types=1);

namespace App\ANA\Contracts;

use App\ANA\DTO\ANAEvent;

interface ANAEventRepositoryInterface
{
    public function record(ANAEvent $event): int;
}