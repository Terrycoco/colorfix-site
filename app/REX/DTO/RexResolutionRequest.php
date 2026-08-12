<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexResolutionRequest
{
    public function __construct(
        public RexReservation $reservation,
        public string $matchedBy,
        public string $lookupValue,
        public string $resourceType,
        public int $resourceId,
        public array $context,
        public ?string $sourceKey = null,
        public array $requestMetadata = [],
    ) {}
}
