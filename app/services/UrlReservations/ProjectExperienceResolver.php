<?php
declare(strict_types=1);

namespace App\Services\UrlReservations;

use App\Repos\PdoUrlReservationResourceRepository;
use InvalidArgumentException;

final class ProjectExperienceResolver implements UrlReservationResolver
{
    public function __construct(private PdoUrlReservationResourceRepository $resources) {}

    public function resolve(array $reservation, array $params): array
    {
        $experienceKey = $this->validateExperience($reservation['experience_key'] ?? null);
        $projectId = (int)($reservation['resource_id'] ?? 0);
        $project = $this->resources->findProject($projectId);
        if (!$project) {
            throw new InvalidArgumentException('Reserved project not found');
        }
        $this->validateParams($params);

        return [
            'resolver_key' => 'project_experience',
            'delivery_mode' => 'render',
            'resource_kind' => 'project',
            'resource_id' => $projectId,
            'project_id' => $projectId,
            'playlist_id' => isset($project['playlist_id']) && $project['playlist_id'] !== null ? (int)$project['playlist_id'] : null,
            'experience_key' => $experienceKey,
            'destination' => [
                'kind' => 'project_experience',
                'project_id' => $projectId,
                'playlist_id' => isset($project['playlist_id']) && $project['playlist_id'] !== null ? (int)$project['playlist_id'] : null,
                'experience_key' => $experienceKey,
            ],
            'og_defaults' => [
                'title' => trim((string)($project['name'] ?? '')) ?: 'ColorFix Project',
                'description' => 'A private ColorFix project experience by Terry Marr.',
                'image_url' => '/apple-touch-icon-teal-20260712.png',
            ],
        ];
    }

    private function validateExperience(mixed $value): string
    {
        $key = trim((string)($value ?? ''));
        if (!in_array($key, ['concept', 'client', 'painter', 'public'], true)) {
            throw new InvalidArgumentException('Invalid project experience');
        }
        return $key;
    }

    private function validateParams(array $params): void
    {
        $allowed = ['return_to'];
        foreach ($params as $key => $value) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new InvalidArgumentException('Invalid reservation params');
            }
            if ($key === 'return_to' && is_string($value) && preg_match('#^https?://#i', $value)) {
                throw new InvalidArgumentException('External return_to is not allowed');
            }
        }
    }
}
