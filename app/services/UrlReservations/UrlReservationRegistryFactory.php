<?php
declare(strict_types=1);

namespace App\Services\UrlReservations;

use App\Repos\PdoUrlReservationResourceRepository;

final class UrlReservationRegistryFactory
{
    public static function create(PdoUrlReservationResourceRepository $resources): UrlReservationResolverRegistry
    {
        $registry = new UrlReservationResolverRegistry();
        $registry->register('project_experience', new ProjectExperienceResolver($resources));
        $registry->register('color_plan_viewer', new ColorPlanViewerResolver($resources));
        return $registry;
    }
}
