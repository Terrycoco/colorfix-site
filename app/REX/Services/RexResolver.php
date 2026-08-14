<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\Resolvers\RexResolverRegistry;
use RuntimeException;
use App\REX\DTO\RexReservationDescriptor;
use InvalidArgumentException;

final class RexResolver
{
    public function __construct(
        private RexReservationRepositoryInterface $reservations,
        private RexResolverRegistry $registry,
    ) {}

    public function resolveToken(string $token, array $requestMetadata = []): RexResolutionResult
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('REX token is required.');
        }

        $reservation = $this->reservations->findByToken($token);
        if (!$reservation) {
            throw new RuntimeException('REX reservation token was not found.');
        }

        return $this->resolveReservation($reservation, 'token', $token, $requestMetadata);
    }

    public function resolveAlias(string $alias, array $requestMetadata = []): RexResolutionResult
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new RuntimeException('REX alias is required.');
        }

        $reservation = $this->reservations->findByAlias($alias);
        if (!$reservation) {
            throw new RuntimeException('REX reservation alias was not found.');
        }

        return $this->resolveReservation($reservation, 'alias', $alias, $requestMetadata);
    }

    public function describeReservation(
            RexReservation $reservation
        ): RexReservationDescriptor {
            $resolver = $this->registry->get($reservation->resolverKey);

            return $resolver->describe($reservation);
        }

    private function resolveReservation(
        RexReservation $reservation,
        string $matchedBy,
        string $lookupValue,
        array $requestMetadata
    ): RexResolutionResult {
        if ($reservation->status !== RexReserver::STATUS_ACTIVE || $reservation->revokedAt !== null) {
            throw new RuntimeException('REX reservation is not active.');
        }

        $resolver = $this->registry->get($reservation->resolverKey);
        return $resolver->resolve(new RexResolutionRequest(
            reservation: $reservation,
            matchedBy: $matchedBy,
            lookupValue: $lookupValue,
            resourceType: $reservation->resourceType,
            resourceId: $reservation->resourceId,
            context: $reservation->context,
            requestMetadata: $requestMetadata,
        ));
    }

    public function previewDescribe(
        string $resolverKey,
        string $resourceType,
        int $resourceId,
        array $context = []
    ): RexReservationDescriptor {
        $resolverKey = trim($resolverKey);

        if ($resolverKey === '') {
            throw new InvalidArgumentException('Resolver key required.');
        }

        if ($resourceId <= 0) {
            throw new InvalidArgumentException('Valid resource ID required.');
        }

        $resolver = $this->registry->get($resolverKey);

        return $resolver->previewDescribe(
            $resourceType,
            $resourceId,
            $context
        );
    }


}
