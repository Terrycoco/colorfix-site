<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexUpdateDestinationRequest
{
    public function __construct(
        public int $reservationId,
        public string $resolverKey,
        public string $resourceType,
        public int $resourceId,
        public array $context = [],
        public ?string $experienceKey = null,
    ) {}
}
