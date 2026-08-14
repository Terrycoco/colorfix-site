<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexCreateReservationRequest
{
    public function __construct(
        public string $label,
        public string $resolverKey,
        public string $resourceType,
        public int $resourceId,
        public ?string $adminNote = null,
        public array $context = [],
        public string $status = 'active',
    ) {}
}
