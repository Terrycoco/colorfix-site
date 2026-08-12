<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use InvalidArgumentException;
use RuntimeException;

final class RexReserver
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        private RexReservationRepositoryInterface $reservations,
        private RexTokenGenerator $tokens,
    ) {}

    public function reserve(RexCreateReservationRequest $request): RexReservation
    {
        $this->validateCreateRequest($request);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = $this->tokens->generate();
            if (!$this->reservations->tokenExists($token)) {
                return $this->reservations->create($request, $token);
            }
        }

        throw new RuntimeException('Unable to generate a unique REX token.');
    }

    private function validateCreateRequest(RexCreateReservationRequest $request): void
    {
        if (trim($request->label) === '') {
            throw new InvalidArgumentException('REX reservation label is required.');
        }
        if (trim($request->resolverKey) === '') {
            throw new InvalidArgumentException('REX resolver_key is required.');
        }
        if (trim($request->resourceType) === '') {
            throw new InvalidArgumentException('REX resource_type is required.');
        }
        if ($request->resourceId <= 0) {
            throw new InvalidArgumentException('REX resource_id must be positive.');
        }
        if (!in_array($request->status, [self::STATUS_ACTIVE, self::STATUS_REVOKED], true)) {
            throw new InvalidArgumentException('Unsupported REX reservation status.');
        }
    }
}
