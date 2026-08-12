<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use App\REX\Contracts\RexResolverInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\Services\ViewerService;
use PDO;
use RuntimeException;

final class ViewerResolver implements RexResolverInterface
{
    private ViewerService $viewers;
    private RexReservationRelationships $relationships;

    public function __construct(private PDO $pdo)
    {
        $this->viewers = new ViewerService($pdo);
        $this->relationships = new RexReservationRelationships(
            new PdoRexReservationRepository($pdo)
        );
    }

    public function resolve(
        RexResolutionRequest $request
    ): RexResolutionResult {
        $format = $this->viewers->viewerFormatForResource(
            $request->resourceType,
            $request->resourceId,
            $request->context
        );
        $makeoverUrl = $this->parentMakeoverUrl($request->reservation);

        $viewer = $this->viewers->resolve(
            $request->resourceType,
            $request->resourceId,
            $request->context,
            $makeoverUrl
        );

        return new RexResolutionResult(
            resolverKey: 'viewer',
            resourceType: $request->resourceType,
            resourceId: $request->resourceId,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: $this->viewers->shareMetadata($viewer),
            destination: [
                'viewer' => $viewer,
                'viewer_format' => $format,
                'makeover_url' => $makeoverUrl,
            ],
            analyticsMetadata: [
                'reservation_id' => $request->reservation->id,
                'resource_type' => $request->resourceType,
                'resource_id' => $request->resourceId,
                'viewer_format' => $format,
            ],
        );
    }

    public function describe(
        RexReservation $reservation
    ): RexReservationDescriptor {
        return $this->viewers->describe(
            $reservation->resourceType,
            $reservation->resourceId,
            $reservation->context
        );
    }

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        return $this->viewers->previewDescribe(
            $resourceType,
            $resourceId,
            $context
        );
    }

    private function parentMakeoverUrl(RexReservation $reservation): ?string
    {
        if ($reservation->id <= 0) {
            throw new RuntimeException('Viewer reservation requires a persisted reservation ID.');
        }

        $parents = $this->relationships->parents($reservation->id, 'viewer');
        $parent = $parents[0] ?? null;

        return $parent instanceof RexReservation
            ? $this->relationships->publicUrl($parent)
            : null;
    }
}
