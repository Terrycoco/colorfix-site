<?php
declare(strict_types=1);

namespace App\Services\UrlReservations;

use App\PROJECTS\Repos\PdoProjectRepository;
use App\Repos\PdoUrlReservationResourceRepository;
use PDO;

final class UrlReservationRegistryFactory
{
    public static function create(PDO $pdo): UrlReservationResolverRegistry
    {
        $resources = new PdoUrlReservationResourceRepository($pdo);
        $registry = new UrlReservationResolverRegistry();
        $registry->register('project_experience', new ProjectExperienceResolver(new PdoProjectRepository($pdo)));
        $registry->register('color_plan_viewer', new ColorPlanViewerResolver($resources));
        return $registry;
    }
}
