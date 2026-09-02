<?php
declare(strict_types=1);

namespace App\ANA\DTO;

final readonly class ANAEvent
{
    public function __construct(
        public string $eventKey,
        public bool $isTest = false,
        public ?int $reservationId = null,

        public ?string $resolverKey = null,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
        public ?string $experienceKey = null,

        public ?string $src = null,
        public ?string $sessionId = null,
        public ?string $referrer = null,
        public ?string $viewerId = null,
        public ?string $path = null,

        public array $payload = [],
    ) {}
}