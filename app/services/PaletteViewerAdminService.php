<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\PaletteViewer;
use App\Entities\PaletteViewerPhoto;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoSavedPaletteRepository;
use InvalidArgumentException;
use RuntimeException;

final class PaletteViewerAdminService
{
    public function __construct(
        private PdoPaletteViewerRepository $viewers,
        private PdoPaletteViewerPhotoRepository $photos,
        private PdoSavedPaletteRepository $savedPalettes,
        private PdoRexReservationRepository $rexReservations,
        private RexReservationRelationships $rexRelationships,
        private RexReserver $rexReserver
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listViewers(): array
    {
        $viewers = $this->viewers->listAll();
        $photoCounts = $this->photos->countsByViewerIds(
            array_map(static fn(PaletteViewer $viewer): int => $viewer->paletteViewerId, $viewers)
        );

        $rows = [];
        foreach ($viewers as $viewer) {
            $palette = $this->savedPalettes->getSavedPaletteById($viewer->savedPaletteId);
            $rows[] = [
                'viewer' => $this->viewerPayload($viewer),
                'palette' => $this->palettePayload($palette, $viewer->savedPaletteId),
                'photo_count' => $photoCounts[$viewer->paletteViewerId] ?? 0,
                'rex' => array_map(
                    static fn(RexReservation $reservation): int => $reservation->id,
                    $this->activeRexReservations($viewer->paletteViewerId)
                ),
            ];
        }

        return $rows;
    }

    public function getViewer(int $paletteViewerId): array
    {
        $viewer = $this->viewers->findById($paletteViewerId);
        if (!$viewer) {
            throw new RuntimeException('Palette Viewer not found.');
        }

        $palette = $this->savedPalettes->getSavedPaletteById($viewer->savedPaletteId);
        if (!$palette) {
            throw new RuntimeException('Saved Palette not found.');
        }

        return [
            'viewer' => $this->viewerPayload($viewer),
            'palette' => $this->palettePayload($palette, $viewer->savedPaletteId),
            'members' => array_map([$this, 'memberPayload'], $this->savedPalettes->getMembersForPalette($viewer->savedPaletteId)),
            'photos' => array_map([$this, 'photoPayload'], $this->photos->findByViewerId($paletteViewerId)),
            'rex' => $this->rexPayloadForViewer($viewer),
        ];
    }

    public function saveViewer(array $payload): array
    {
        $viewerPayload = is_array($payload['viewer'] ?? null) ? $payload['viewer'] : $payload;
        $paletteViewerId = (int)($viewerPayload['palette_viewer_id'] ?? $viewerPayload['id'] ?? 0);
        $savedPaletteId = (int)($viewerPayload['saved_palette_id'] ?? 0);
        $palette = $this->savedPalettes->getSavedPaletteById($savedPaletteId);
        if (!$palette) {
            throw new InvalidArgumentException('A valid Saved Palette is required.');
        }

        $data = [
            'saved_palette_id' => $savedPaletteId,
            'format' => $this->optionalKey($viewerPayload['format'] ?? 'public') ?? 'public',
            'template_key' => $this->optionalKey($viewerPayload['template_key'] ?? 'full_palette'),
            'kicker_text' => $this->optionalText($viewerPayload['kicker_text'] ?? null),
            'title' => $this->optionalText($viewerPayload['title'] ?? null),
            'intro' => $this->optionalText($viewerPayload['intro'] ?? null),
            'notes' => $this->optionalText($viewerPayload['notes'] ?? null),
            'cta_label' => $this->optionalText($viewerPayload['cta_label'] ?? null),
            'is_active' => isset($viewerPayload['is_active']) ? (int)(bool)$viewerPayload['is_active'] : 1,
        ];

        $viewer = $paletteViewerId > 0
            ? $this->viewers->update($paletteViewerId, $data)
            : $this->viewers->create($data);

        if (array_key_exists('photos', $payload)) {
            if (!is_array($payload['photos'])) {
                throw new InvalidArgumentException('photos must be an array.');
            }
            $this->photos->replaceForViewer(
                $viewer->paletteViewerId,
                array_map(
                    fn(array $row, int $index): array => $this->normalizePhotoPayload($row, $viewer->paletteViewerId, $index),
                    $payload['photos'],
                    array_keys(array_values($payload['photos']))
                )
            );
        }

        $result = $this->getViewer($viewer->paletteViewerId);
        try {
            $rex = $this->ensurePaletteViewerRex($viewer, $palette);
        } catch (\Throwable $e) {
            $rex = [
                'reservation_id' => null,
                'public_url' => null,
                'token' => null,
                'reused' => false,
                'warning' => $e->getMessage(),
                'reservations' => [],
            ];
        }
        $result['rex'] = $rex;
        if (!empty($rex['warning'])) {
            $result['rex_warning'] = $rex['warning'];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paletteOptions(string $query = '', int $limit = 1000): array
    {
        $filters = [];
        $query = trim($query);
        if ($query !== '') {
            $filters['q'] = $query;
        }

        return array_map(
            fn(array $row): array => $this->palettePayload($row, (int)($row['id'] ?? 0)),
            $this->savedPalettes->listPalettes($filters, max(1, min(2000, $limit)), 0)
        );
    }

    public function paletteDetail(int $savedPaletteId): array
    {
        $palette = $this->savedPalettes->getSavedPaletteById($savedPaletteId);
        if (!$palette) {
            throw new RuntimeException('Saved Palette not found.');
        }

        return [
            'palette' => $this->palettePayload($palette, $savedPaletteId),
            'members' => array_map([$this, 'memberPayload'], $this->savedPalettes->getMembersForPalette($savedPaletteId)),
        ];
    }

    private function normalizePhotoPayload(array $row, int $paletteViewerId, int $index): array
    {
        return [
            'palette_viewer_id' => $paletteViewerId,
            'photo_library_id' => $this->optionalPositiveInt($row['photo_library_id'] ?? null),
            'rel_path' => $this->optionalText($row['rel_path'] ?? $row['image_url'] ?? null),
            'photo_type' => $this->optionalKey($row['photo_type'] ?? 'inset') ?? 'inset',
            'trigger_mode' => $this->optionalKey($row['trigger_mode'] ?? 'any') ?? 'any',
            'trigger_color_id' => $this->optionalPositiveInt($row['trigger_color_id'] ?? null),
            'caption' => $this->optionalText($row['caption'] ?? null),
            'alt_text' => $this->optionalText($row['alt_text'] ?? null),
            'order_index' => max(0, (int)($row['order_index'] ?? $index)),
        ];
    }

    private function viewerPayload(PaletteViewer $viewer): array
    {
        return [
            'palette_viewer_id' => $viewer->paletteViewerId,
            'saved_palette_id' => $viewer->savedPaletteId,
            'format' => $viewer->format,
            'template_key' => $viewer->templateKey,
            'kicker_text' => $viewer->kickerText,
            'title' => $viewer->title,
            'intro' => $viewer->intro,
            'notes' => $viewer->notes,
            'cta_label' => $viewer->ctaLabel,
            'is_active' => $viewer->isActive ? 1 : 0,
            'created_at' => $viewer->createdAt,
            'updated_at' => $viewer->updatedAt,
        ];
    }

    private function photoPayload(PaletteViewerPhoto $photo): array
    {
        return [
            'palette_viewer_photo_id' => $photo->paletteViewerPhotoId,
            'palette_viewer_id' => $photo->paletteViewerId,
            'photo_library_id' => $photo->photoLibraryId,
            'rel_path' => $photo->relPath,
            'photo_type' => $photo->photoType,
            'trigger_mode' => $photo->triggerMode,
            'trigger_color_id' => $photo->triggerColorId,
            'caption' => $photo->caption,
            'alt_text' => $photo->altText,
            'order_index' => $photo->orderIndex,
            'created_at' => $photo->createdAt,
            'updated_at' => $photo->updatedAt,
        ];
    }

    private function palettePayload(?array $palette, int $fallbackId): array
    {
        $palette = $palette ?? [];
        $id = (int)($palette['id'] ?? $fallbackId);
        $label = $this->firstNonEmpty([
            $palette['display_title'] ?? null,
            $palette['nickname'] ?? null,
            $id > 0 ? "Saved Palette #{$id}" : 'Saved Palette',
        ]);

        return [
            'id' => $id,
            'nickname' => $palette['nickname'] ?? null,
            'display_title' => $palette['display_title'] ?? null,
            'label' => $label,
            'palette_hash' => $palette['palette_hash'] ?? null,
            'palette_type' => $palette['palette_type'] ?? null,
        ];
    }

    private function memberPayload(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'color_id' => isset($row['color_id']) ? (int)$row['color_id'] : null,
            'color_name' => $row['color_name'] ?? null,
            'color_code' => $row['color_code'] ?? null,
            'color_brand' => $row['color_brand'] ?? null,
            'color_brand_name' => $row['color_brand_name'] ?? ($row['brand_name'] ?? null),
            'color_hex6' => $row['color_hex6'] ?? null,
            'role' => $row['role'] ?? null,
            'sheen' => $row['sheen'] ?? null,
            'note' => $row['note'] ?? null,
        ];
    }

    /**
     * @return RexReservation[]
     */
    private function activeRexReservations(int $paletteViewerId): array
    {
        if ($paletteViewerId <= 0) {
            return [];
        }

        $matches = $this->rexReservations->search(new RexReservationSearchCriteria(
            resolverKey: 'viewer',
            resourceType: 'palette_viewer',
            resourceId: $paletteViewerId,
            status: RexReserver::STATUS_ACTIVE,
            limit: 100,
        ));

        $matches = array_values(array_filter(
            $matches,
            static fn(RexReservation $reservation): bool =>
                $reservation->resolverKey === 'viewer'
                && $reservation->resourceType === 'palette_viewer'
                && $reservation->resourceId === $paletteViewerId
                && $reservation->status === RexReserver::STATUS_ACTIVE
                && $reservation->revokedAt === null
        ));

        usort(
            $matches,
            static fn(RexReservation $a, RexReservation $b): int => $a->id <=> $b->id
        );

        return $matches;
    }

    private function ensurePaletteViewerRex(PaletteViewer $viewer, ?array $palette): array
    {
        $matches = $this->activeRexReservations($viewer->paletteViewerId);

        if (count($matches) > 1) {
            return [
                'reservation_id' => null,
                'public_url' => null,
                'token' => null,
                'reused' => false,
                'warning' => 'Multiple active REX reservations match this Palette Viewer; no new reservation was created.',
                'reservations' => array_map([$this, 'rexReservationPayload'], $matches),
            ];
        }

        if (count($matches) === 1) {
            return [
                ...$this->rexReservationPayload($matches[0]),
                'reused' => true,
                'warning' => null,
                'reservations' => [$this->rexReservationPayload($matches[0])],
            ];
        }

        $reservation = $this->rexReserver->reserve(new RexCreateReservationRequest(
            label: $this->viewerLabel($viewer, $palette),
            resolverKey: 'viewer',
            resourceType: 'palette_viewer',
            resourceId: $viewer->paletteViewerId,
            adminNote: 'Auto-created from Palette Viewer Save workflow.',
            context: ['format' => $viewer->format ?: 'public'],
        ));

        return [
            ...$this->rexReservationPayload($reservation),
            'reused' => false,
            'warning' => null,
            'reservations' => [$this->rexReservationPayload($reservation)],
        ];
    }

    private function rexPayloadForViewer(PaletteViewer $viewer): array
    {
        $matches = $this->activeRexReservations($viewer->paletteViewerId);
        if (count($matches) === 1) {
            return [
                ...$this->rexReservationPayload($matches[0]),
                'reused' => true,
                'warning' => null,
                'reservations' => [$this->rexReservationPayload($matches[0])],
            ];
        }

        if (count($matches) > 1) {
            return [
                'reservation_id' => null,
                'public_url' => null,
                'token' => null,
                'reused' => false,
                'warning' => 'Multiple active REX reservations match this Palette Viewer.',
                'reservations' => array_map([$this, 'rexReservationPayload'], $matches),
            ];
        }

        return [
            'reservation_id' => null,
            'public_url' => null,
            'token' => null,
            'reused' => false,
            'warning' => null,
            'reservations' => [],
        ];
    }

    private function rexReservationPayload(RexReservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,
            'id' => $reservation->id,
            'token' => $reservation->token,
            'public_url' => $this->rexRelationships->publicUrl($reservation),
        ];
    }

    private function viewerLabel(PaletteViewer $viewer, ?array $palette): string
    {
        return $this->firstNonEmpty([
            $viewer->title,
            $palette['display_title'] ?? null,
            $palette['nickname'] ?? null,
            'Palette Viewer #' . $viewer->paletteViewerId,
        ]);
    }

    private function optionalKey(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        $value = trim($value, '_-');
        return $value === '' ? null : $value;
    }

    private function optionalText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
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
        return 'Palette Viewer';
    }
}
