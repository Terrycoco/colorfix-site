<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use App\REX\Contracts\RexResolverInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use App\REX\Resources\RexRouteCatalog;
use RuntimeException;

final class RouteResolver implements RexResolverInterface
{
    public function resolve(
        RexResolutionRequest $request
    ): RexResolutionResult {
        $route = RexRouteCatalog::get($request->resourceId);

        if ($route === null) {
            throw new RuntimeException('REX route resource was not found.');
        }

        return new RexResolutionResult(
            resolverKey: 'route',
            resourceType: $request->resourceType,
            resourceId: $request->resourceId,
            behavior: RexResolutionBehavior::REDIRECT,
            shareMetadata: new RexShareMetadata(
    title: null,
    description: null,
    imageUrl: null,
),
            destination: [
                'path' => $route['path'],
            ],
            analyticsMetadata: [
                'reservation_id' => $request->reservation->id,
                'resource_type' => $request->resourceType,
                'resource_id' => $request->resourceId,
            ],
        );
    }

    public function describe(
        RexReservation $reservation
    ): RexReservationDescriptor {
        return $this->descriptor(
            $reservation->resourceType,
            $reservation->resourceId
        );
    }

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        return $this->descriptor(
            $resourceType,
            $resourceId
        );
    }

    private function descriptor(
        string $resourceType,
        int $resourceId
    ): RexReservationDescriptor {
        $route = RexRouteCatalog::get($resourceId);

        if ($route === null) {
            throw new RuntimeException('REX route resource was not found.');
        }

        return new RexReservationDescriptor(
            title: $route['title'],
            fields: [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'path' => $route['path'],
            ],
        );
    }
}