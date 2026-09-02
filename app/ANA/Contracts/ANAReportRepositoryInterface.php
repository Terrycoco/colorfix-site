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
}