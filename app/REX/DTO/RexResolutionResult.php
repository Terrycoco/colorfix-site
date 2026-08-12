<?php
declare(strict_types=1);

namespace App\REX\DTO;

use InvalidArgumentException;

final readonly class RexResolutionResult
{
    public function __construct(
        public string $resolverKey,
        public string $resourceType,
        public int $resourceId,
        public RexResolutionBehavior $behavior,
        public RexShareMetadata $shareMetadata,
        public array $destination = [],
        public array $analyticsMetadata = [],
    ) {
        if ($behavior === RexResolutionBehavior::REDIRECT) {
            $url = trim((string)($destination['url'] ?? $destination['path'] ?? ''));
            if ($url === '') {
                throw new InvalidArgumentException('REX redirect results require destination url or path.');
            }
        }

        if ($behavior === RexResolutionBehavior::RENDER && $destination === []) {
            throw new InvalidArgumentException('REX render results require destination data.');
        }
    }
}
