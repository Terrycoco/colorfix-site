<?php
declare(strict_types=1);

namespace App\Services;

use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexShareMetadata;
use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPhotoRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\PROJECTS\Repos\PdoProjectColorPlanRepository;
use App\PROJECTS\Repos\PdoProjectRepository;
use App\Repos\PdoSavedPaletteRepository;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class ViewerService
{
    private const RESOURCE_SAVED_PALETTE = 'saved_palette';
    private const RESOURCE_PALETTE_VIEWER = 'palette_viewer';
    private const RESOURCE_COLOR_PLAN = 'color_plan';
    private const FORMATS = ['public', 'concept', 'client', 'painter'];

    private PaletteViewerService $paletteViewer;
    private PdoSavedPaletteRepository $savedPalettes;
    private PdoPaletteViewerRepository $paletteViewers;
    private PdoProjectColorPlanRepository $colorPlans;
    private PdoProjectRepository $projects;

    public function __construct(private PDO $pdo)
    {
        $photoRepo = new PdoPhotoRepository($pdo);
        $this->savedPalettes = new PdoSavedPaletteRepository($pdo);
        $this->paletteViewers = new PdoPaletteViewerRepository($pdo);
        $paletteViewerPhotos = new PdoPaletteViewerPhotoRepository($pdo);
        $this->colorPlans = new PdoProjectColorPlanRepository($pdo);
        $this->projects = new PdoProjectRepository($pdo);
        $this->paletteViewer = new PaletteViewerService(
            $this->savedPalettes,
            new PhotoRenderingService($photoRepo, $pdo),
            new PdoPlaylistInstanceRepository($pdo),
            $this->colorPlans,
            $this->paletteViewers,
            $paletteViewerPhotos
        );
    }

    public function resolve(
        string $resourceType,
        int $resourceId,
        array $context,
        ?string $makeoverUrl = null,
        ?string $viewerCtaLabel = null,
        ?string $viewerCtaUrl = null
    ): array {
        $resourceType = $this->normalizeResourceType($resourceType);
        $format = $this->viewerFormatForResource($resourceType, $resourceId, $context);
        $viewerKey = $this->paletteViewerKey($format);

        if ($resourceType === self::RESOURCE_SAVED_PALETTE) {
            $setId = $this->optionalPositiveInt($context['set_id'] ?? null);
            $payload = $this->paletteViewer->getSavedById(
                $resourceId,
                $setId,
                $viewerKey
            );

            return $this->withRuntimeContext(
                $payload,
                $resourceType,
                $resourceId,
                $format,
                $makeoverUrl,
                $viewerCtaLabel,
                $viewerCtaUrl
            );
        }

        if ($resourceType === self::RESOURCE_PALETTE_VIEWER) {
            $payload = $this->paletteViewer->getCanonicalById($resourceId);
            $viewerFormat = $this->viewerFormatFromPayload($payload, $format);

            return $this->withRuntimeContext(
                $payload,
                $resourceType,
                $resourceId,
                $viewerFormat,
                $makeoverUrl,
                $viewerCtaLabel,
                $viewerCtaUrl
            );
        }

        $plan = $this->requireColorPlan($resourceId);
        $projectId = (int)($plan['project_id'] ?? 0);
        if ($projectId <= 0) {
            throw new RuntimeException(
                "Color Plan {$resourceId} is not attached to a project."
            );
        }

        $payload = $this->paletteViewer->getProjectColorPlan(
            $resourceId,
            $viewerKey
        );

        return $this->withRuntimeContext(
            $payload,
            $resourceType,
            $resourceId,
            $format,
            $makeoverUrl,
            $viewerCtaLabel,
            $viewerCtaUrl
        );
    }

    public function describe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        $resourceType = $this->normalizeResourceType($resourceType);
        $format = $this->viewerFormatForResource($resourceType, $resourceId, $context);

        if ($resourceType === self::RESOURCE_SAVED_PALETTE) {
            $palette = $this->savedPalettes->getSavedPaletteById($resourceId);
            if (!$palette) {
                throw new RuntimeException(
                    "Saved Palette {$resourceId} was not found."
                );
            }

            $title = $this->firstNonEmpty([
                $palette['display_title'] ?? null,
                $palette['nickname'] ?? null,
                "Saved Palette #{$resourceId}",
            ]);

            return new RexReservationDescriptor(
                title: "{$title} — " . $this->formatLabel($format),
                fields: [
                    [
                        'label' => 'Viewer',
                        'value' => $this->formatLabel($format),
                    ],
                    [
                        'label' => 'Saved Palette',
                        'value' => $title,
                    ],
                    [
                        'label' => 'Saved Palette ID',
                        'value' => (string)$resourceId,
                    ],
                    [
                        'label' => 'Set ID',
                        'value' => $this->optionalPositiveInt($context['set_id'] ?? null) !== null
                            ? (string)$this->optionalPositiveInt($context['set_id'] ?? null)
                            : '—',
                    ],
                ],
            );
        }

        if ($resourceType === self::RESOURCE_PALETTE_VIEWER) {
            $viewer = $this->paletteViewer->getPaletteViewer($resourceId);
            if (!$viewer->isActive) {
                throw new RuntimeException(
                    "Palette Viewer {$resourceId} is inactive."
                );
            }

            $viewerFormat = $this->normalizeViewerFormatText($viewer->format);
            $palette = $this->savedPalettes->getSavedPaletteById($viewer->savedPaletteId);
            if (!$palette) {
                throw new RuntimeException(
                    "Saved Palette {$viewer->savedPaletteId} was not found."
                );
            }

            $paletteTitle = $this->firstNonEmpty([
                $viewer->title,
                $palette['display_title'] ?? null,
                $palette['nickname'] ?? null,
                "Saved Palette #{$viewer->savedPaletteId}",
            ]);

            return new RexReservationDescriptor(
                title: "{$paletteTitle} — " . $this->formatLabel($viewerFormat),
                fields: [
                    [
                        'label' => 'Viewer Format',
                        'value' => $this->formatLabel($viewerFormat),
                    ],
                    [
                        'label' => 'Palette Viewer ID',
                        'value' => (string)$resourceId,
                    ],
                    [
                        'label' => 'Saved Palette',
                        'value' => $paletteTitle,
                    ],
                    [
                        'label' => 'Saved Palette ID',
                        'value' => (string)$viewer->savedPaletteId,
                    ],
                ],
            );
        }

        $plan = $this->requireColorPlan($resourceId);
        $projectId = (int)($plan['project_id'] ?? 0);
        $project = $projectId > 0 ? $this->projects->findById($projectId) : null;
        if (!$project) {
            throw new RuntimeException(
                "Project for Color Plan {$resourceId} was not found."
            );
        }
        $planTitle = $this->firstNonEmpty([
            $plan['area_name'] ?? null,
            $plan['nickname'] ?? null,
            $plan['scheme_title'] ?? null,
            "Color Plan #{$resourceId}",
        ]);
        $projectTitle = $this->firstNonEmpty([
            $project['project_name'] ?? null,
            "Project #{$projectId}",
        ]);

        return new RexReservationDescriptor(
            title: "{$projectTitle} — {$planTitle} — " . $this->formatLabel($format),
            fields: [
                [
                    'label' => 'Viewer',
                    'value' => $this->formatLabel($format),
                ],
                [
                    'label' => 'Project',
                    'value' => $projectTitle,
                ],
                [
                    'label' => 'Color Plan',
                    'value' => $planTitle,
                ],
                [
                    'label' => 'Color Plan ID',
                    'value' => (string)$resourceId,
                ],
            ],
        );
    }

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        if ($resourceId <= 0) {
            throw new InvalidArgumentException('Viewer preview requires a valid resource ID.');
        }

        return $this->describe($resourceType, $resourceId, $context);
    }

    public function shareMetadata(array $viewerPayload): RexShareMetadata
    {
        $meta = is_array($viewerPayload['meta'] ?? null)
            ? $viewerPayload['meta']
            : [];

        return new RexShareMetadata(
            title: $this->nullableText(
                $meta['display_title'] ?? $meta['title'] ?? $meta['scheme_title'] ?? null
            ),
            description: $this->nullableText(
                $meta['notes'] ?? $meta['intro'] ?? null
            ),
            imageUrl: $this->nullableText($meta['photo_url'] ?? null),
        );
    }

    public function viewerFormat(array $context): string
    {
        $raw = null;
        foreach (['format', 'viewer_format', 'experience_key', 'palette_viewer_key'] as $key) {
            if (array_key_exists($key, $context)) {
                $raw = $context[$key];
                break;
            }
        }

        if (!is_string($raw)) {
            throw new DomainException(
                'Viewer reservation requires context.format.'
            );
        }

        $format = strtolower(trim($raw));
        if ($format === 'full_palette') {
            $format = 'public';
        }

        if (!in_array($format, self::FORMATS, true)) {
            throw new DomainException(
                "Unsupported Viewer format '{$format}'."
            );
        }

        return $format;
    }

    public function viewerFormatForResource(string $resourceType, int $resourceId, array $context): string
    {
        $resourceType = $this->normalizeResourceType($resourceType);
        foreach (['format', 'viewer_format', 'experience_key', 'palette_viewer_key'] as $key) {
            if (array_key_exists($key, $context) && is_string($context[$key])) {
                return $this->viewerFormat($context);
            }
        }

        if ($resourceType === self::RESOURCE_PALETTE_VIEWER) {
            return $this->normalizeViewerFormatText(
                $this->paletteViewer->getPaletteViewer($resourceId)->format
            );
        }

        return $this->viewerFormat($context);
    }

    private function normalizeResourceType(string $resourceType): string
    {
        $resourceType = strtolower(trim($resourceType));
        if (!in_array(
            $resourceType,
            [self::RESOURCE_SAVED_PALETTE, self::RESOURCE_PALETTE_VIEWER, self::RESOURCE_COLOR_PLAN],
            true
        )) {
            throw new DomainException(
                "Viewer resolver does not support resource_type '{$resourceType}'."
            );
        }

        return $resourceType;
    }

    private function paletteViewerKey(string $format): string
    {
        return $format === 'public' ? 'full_palette' : $format;
    }

    private function viewerFormatFromPayload(array $payload, string $fallback): string
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        return $this->normalizeViewerFormatText(
            $meta['format'] ?? $meta['viewer_format'] ?? $fallback
        );
    }

    private function normalizeViewerFormatText(mixed $value): string
    {
        $format = strtolower(trim((string)($value ?? '')));
        if ($format === 'full_palette') {
            $format = 'public';
        }

        return in_array($format, self::FORMATS, true) ? $format : 'public';
    }

    private function requireColorPlan(int $resourceId): array
    {
        $plan = $this->colorPlans->findPlan($resourceId);
        if (!$plan) {
            throw new RuntimeException(
                "Color Plan {$resourceId} was not found."
            );
        }

        return $plan;
    }

    private function withRuntimeContext(
        array $payload,
        string $resourceType,
        int $resourceId,
        string $format,
        ?string $makeoverUrl,
        ?string $viewerCtaLabel = null,
        ?string $viewerCtaUrl = null
    ): array {
        if (!is_array($payload['meta'] ?? null)) {
            $payload['meta'] = [];
        }

        $payload['meta']['rex_resource_type'] = $resourceType;
        $payload['meta']['rex_resource_id'] = $resourceId;
        $payload['meta']['viewer_format'] = $format;
        if ($makeoverUrl !== null && $makeoverUrl !== '') {
            $payload['meta']['makeover_url'] = $makeoverUrl;
            $payload['meta']['watch_complete_makeover_url'] = $makeoverUrl;
        }
        if ($viewerCtaLabel !== null && $viewerCtaLabel !== '' && $viewerCtaUrl !== null && $viewerCtaUrl !== '') {
            $payload['meta']['viewer_cta_label'] = $viewerCtaLabel;
            $payload['meta']['viewer_cta_url'] = $viewerCtaUrl;
        }

        return $payload;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private function formatLabel(string $format): string
    {
        return $format === 'public' ? 'Public' : ucfirst($format);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return 'ColorFix Viewer';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }
}
