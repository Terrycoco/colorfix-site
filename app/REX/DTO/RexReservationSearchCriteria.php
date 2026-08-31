<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexReservationSearchCriteria
{
    public function __construct(
        public ?string $query = null,
        public ?string $resolverKey = null,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
        public ?string $status = null,
        public int $limit = 200,
        public ?string $experienceKey = null,
    ) {}
}
