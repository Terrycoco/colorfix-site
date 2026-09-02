<?php
declare(strict_types=1);

namespace App\ANA\Services;

use App\ANA\Contracts\ANAEventRepositoryInterface;
use App\ANA\DTO\ANAEvent;

final class ANAService
{
    public function __construct(
        private ANAEventRepositoryInterface $events,
    ) {}

    public function record(ANAEvent $event): int
    {
        return $this->events->record($event);
    }
}