<?php
declare(strict_types=1);

namespace App\ANA\Contracts;

interface ANAReportRepositoryInterface
{
    public function countEventsByResourceType(
        string $resourceType,
        string $eventKey
    ): array;

    public function listResourceTypes(): array;

    public function countPlaylistEngagement(): array;

    public function listEventsForResource(
        string $resourceType,
        int $resourceId,
        string $eventKey,
        ?string $sourceKey
    ): array;

    public function deleteEventById(int $id): bool;
}
