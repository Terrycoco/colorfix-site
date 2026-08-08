<?php
declare(strict_types=1);

namespace App\Services\UrlReservations;

use App\Repos\PdoUrlReservationResourceRepository;
use InvalidArgumentException;

final class ColorPlanViewerResolver implements UrlReservationResolver
{
    public function __construct(private PdoUrlReservationResourceRepository $resources) {}

    public function resolve(array $reservation, array $params): array
    {
        $experienceKey = $this->validateExperience($reservation['experience_key'] ?? null);
        $colorPlanId = (int)($reservation['resource_id'] ?? 0);
        $plan = $this->resources->findColorPlan($colorPlanId);
        if (!$plan) {
            throw new InvalidArgumentException('Reserved color plan not found');
        }
        $this->validateParams($params);
        $viewerExists = $this->resources->colorPlanViewerExists($colorPlanId, $experienceKey);

        return [
            'resolver_key' => 'color_plan_viewer',
            'delivery_mode' => 'render',
            'resource_kind' => 'project_color_plan',
            'resource_id' => $colorPlanId,
            'project_id' => (int)$plan['project_id'],
            'project_color_plan_id' => $colorPlanId,
            'experience_key' => $experienceKey,
            'viewer_key' => $experienceKey,
            'viewer_exists' => $viewerExists,
            'destination' => [
                'kind' => 'color_plan_viewer',
                'project_id' => (int)$plan['project_id'],
                'project_color_plan_id' => $colorPlanId,
                'viewer_key' => $experienceKey,
                'viewer_exists' => $viewerExists,
            ],
            'og_defaults' => [
                'title' => $this->title($plan, $experienceKey),
                'description' => 'A private ColorFix room viewer by Terry Marr.',
                'image_url' => '/apple-touch-icon-teal-20260712.png',
            ],
        ];
    }

    private function validateExperience(mixed $value): string
    {
        $key = trim((string)($value ?? ''));
        if (!in_array($key, ['concept', 'client', 'painter'], true)) {
            throw new InvalidArgumentException('Invalid color plan viewer experience');
        }
        return $key;
    }

    private function validateParams(array $params): void
    {
        if ($params === []) {
            return;
        }
        throw new InvalidArgumentException('Invalid reservation params');
    }

    private function title(array $plan, string $experienceKey): string
    {
        $base = trim((string)($plan['scheme_title'] ?? ''))
            ?: trim((string)($plan['area_name'] ?? ''))
            ?: trim((string)($plan['nickname'] ?? ''))
            ?: 'ColorFix Room';
        return $base . ' ' . ucfirst($experienceKey);
    }
}
