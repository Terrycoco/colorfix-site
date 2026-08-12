<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\PlaylistItem;
use App\Repos\PdoProjectRepository;
use DomainException;
use RuntimeException;

final class ProjectReleaseSelectionService
{
    public function __construct(
        private PdoProjectRepository $projects
    ) {}

    public function currentReleaseForProject(int $projectId): string
    {
        $value = $this->projects->getCurrentRelease($projectId);
        if ($value === null) {
            throw new RuntimeException(
                "Project not found while resolving current release: {$projectId}"
            );
        }

        return $this->normalizeCurrentRelease($value);
    }

    public function normalizeCurrentRelease(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === 'FINAL') {
            return 'FINAL';
        }
        if ($value === '' || !ctype_digit($value) || (int)$value < 1) {
            throw new DomainException(
                'Project release configuration error: current_release must be a positive version number or FINAL.'
            );
        }

        return (string)max(1, (int)$value);
    }

    public function assertExperienceAllowed(
        string $experienceKey,
        string $currentRelease
    ): void {
        $experienceKey = strtolower(trim($experienceKey));
        $currentRelease = $this->normalizeCurrentRelease($currentRelease);

    }

    /**
     * @param PlaylistItem[] $items
     * @return PlaylistItem[]
     */
    public function filterItemsForRelease(
        array $items,
        string $experienceKey,
        string $currentRelease
    ): array {
        $experienceKey = strtolower(trim($experienceKey));
        $currentRelease = $this->normalizeCurrentRelease($currentRelease);

        if (in_array($experienceKey, ['public', 'concept'], true)) {
            return array_values($items);
        }

        if (!in_array($experienceKey, ['client', 'painter'], true)) {
            return array_values($items);
        }

        if ($currentRelease === 'FINAL') {
            return array_values(array_filter(
                $items,
                static fn($item): bool => $item instanceof PlaylistItem && !empty($item->is_final)
            ));
        }

        $version = (int)$currentRelease;
        return array_values(array_filter(
            $items,
            static function ($item) use ($version): bool {
                if (!$item instanceof PlaylistItem) {
                    return false;
                }
                $itemVersion = max(1, (int)($item->version_number ?? 1));
                return $itemVersion <= $version;
            }
        ));
    }

    /**
     * @param PlaylistItem[] $items
     * @return int[]
     */
    public function collectColorPlanIds(array $items): array
    {
        $seen = [];
        foreach ($items as $item) {
            if (!$item instanceof PlaylistItem) {
                continue;
            }
            $colorPlanId = (int)($item->color_plan_id ?? 0);
            if ($colorPlanId > 0) {
                $seen[$colorPlanId] = true;
            }
        }

        $ids = array_keys($seen);
        sort($ids, SORT_NUMERIC);

        return array_values(array_map('intval', $ids));
    }
}
