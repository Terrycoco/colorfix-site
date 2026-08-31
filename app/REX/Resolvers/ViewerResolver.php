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

        $experienceKey = $this->reservationExperienceKey(
            $request->reservation,
            $format
        );

        $viewerCta = $this->viewerCta($request);

        $makeoverUrl = $viewerCta['url'] !== '/'
            ? $viewerCta['url']
            : null;

        $viewer = $this->viewers->resolve(
            $request->resourceType,
            $request->resourceId,
            $request->context,
            $makeoverUrl,
            $viewerCta['label'],
            $viewerCta['url']
        );

        return new RexResolutionResult(
            resolverKey: 'viewer',
            resourceType: $request->resourceType,
            resourceId: $request->resourceId,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: $this->viewers->shareMetadata($viewer),
            destination: [
                'viewer' => $viewer,
                'experience_key' => $experienceKey,
                'viewer_format' => $format,
                'makeover_url' => $makeoverUrl,
                'viewer_cta_label' => $viewerCta['label'],
                'viewer_cta_url' => $viewerCta['url'],
            ],
            analyticsMetadata: [
                'reservation_id' => $request->reservation->id,
                'resource_type' => $request->resourceType,
                'resource_id' => $request->resourceId,
                'experience_key' => $experienceKey,
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

    /**
     * First-class REX experience identity is authoritative.
     *
     * Temporary migration fallback:
     * existing Viewer reservations may not yet have experience_key populated,
     * so infer it from the resolved Viewer format until those rows are migrated.
     */
    private function reservationExperienceKey(
        RexReservation $reservation,
        string $viewerFormat,
    ): string {
        $experienceKey = strtolower(trim(
            (string)($reservation->experienceKey ?? '')
        ));

        if ($experienceKey !== '') {
            return $experienceKey;
        }

        $format = strtolower(trim($viewerFormat));

        return match ($format) {
            'full_palette', 'public' => 'public',
            'concept' => 'concept',
            'client' => 'client',
            'painter' => 'painter',
            default => 'public',
        };
    }

    /**
     * Decide the viewer's standalone CTA without adding domain rules to REX.
     *
     * Precedence:
     * 1. trusted REX request metadata from the current request
     * 2. any linked parent reservation
     * 3. the ColorFix home page
     *
     * @return array{label:string,url:string}
     */
    private function viewerCta(RexResolutionRequest $request): array
    {
        $originPlaylist = $this->validInternalRexPath(
            $request->requestMetadata['origin_playlist'] ?? ''
        );

        if ($originPlaylist !== null) {
            return [
                'label' => 'Watch the Complete Makeover',
                'url' => $originPlaylist,
            ];
        }

        $parentUrl = $this->parentMakeoverUrl($request->reservation);

        if ($parentUrl !== null) {
            return [
                'label' => 'Watch the Complete Makeover',
                'url' => $parentUrl,
            ];
        }

        return [
            'label' => 'See More on ColorFix',
            'url' => '/',
        ];
    }

    private function parentMakeoverUrl(RexReservation $reservation): ?string
    {
        if ($reservation->id <= 0) {
            throw new RuntimeException(
                'Viewer reservation requires a persisted reservation ID.'
            );
        }

        $parents = $this->relationships->parents(
            $reservation->id,
            'viewer'
        );

        foreach ($parents as $parent) {
            if (!$parent instanceof RexReservation) {
                continue;
            }

            $url = $this->validInternalRexPath(
                $this->relationships->publicUrl($parent)
            );

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function validInternalRexPath(mixed $value): ?string
    {
        $path = trim((string)$value);

        if (
            $path === ''
            || !str_starts_with($path, '/t/')
            || str_starts_with($path, '//')
        ) {
            return null;
        }

        return $path;
    }
}
