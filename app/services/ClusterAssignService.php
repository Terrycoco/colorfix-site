<?php
declare(strict_types=1);

namespace App\Services;

use App\repos\PdoClusterRepository;

final class ClusterAssignService
{
    public function __construct(private PdoClusterRepository $clusters) {}

    /** Assign clusters for a set of color ids (expects HCL already computed). */
    public function assignForColorIds(array $ids): array
    {
        return $this->clusters->assignClustersBulkByColorIds($ids);
    }

    /** Assign cluster for one color id. */
    public function assignForColorId(int $id): ?int
    {
        return $this->clusters->assignClusterForColorId($id);
    }
}
