<?php
declare(strict_types=1);

namespace App\Analytics\DTO;

final readonly class AnalyticsEvent
{
    public function __construct(
        public string $eventKey,

        public ?int $reservationId = null,
        public ?string $reservationToken = null,

        public ?string $resolverKey = null,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
        public ?string $experienceKey = null,

        public ?string $src = null,
        public ?string $viewerId = null,
        public ?string $path = null,

        public array $payload = [],
    ) {}
}