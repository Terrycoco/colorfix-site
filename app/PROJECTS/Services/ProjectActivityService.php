<?php
declare(strict_types=1);

namespace App\PROJECTS\Services;

use App\PROJECTS\Repos\PdoProjectActivityRepository;
use PDO;
use RuntimeException;

final class ProjectActivityService
{
    private PdoProjectActivityRepository $activity;

    public function __construct(PDO $pdo)
    {
        $this->activity = new PdoProjectActivityRepository($pdo);
    }

    /**
     * Record a system-generated Project Activity event.
     *
     * @return array<string, mixed>
     */
    public function log(
        int $projectId,
        string $description,
        string $eventType,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $activityDate = null
    ): array {
        if ($projectId <= 0) {
            throw new RuntimeException('Valid project ID required.');
        }

        $description = trim($description);

        if ($description === '') {
            throw new RuntimeException('Activity description required.');
        }

        $eventType = trim($eventType);

        if ($eventType === '') {
            throw new RuntimeException('Activity event type required.');
        }

        return $this->activity->createSystem(
            projectId: $projectId,
            description: $description,
            eventType: $eventType,
            resourceType: $resourceType,
            resourceId: $resourceId,
            activityDate: $activityDate
        );
    }
}