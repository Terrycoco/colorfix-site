<?php
declare(strict_types=1);

namespace App\PALETTES\Services;

use App\PALETTES\Repos\PdoPVRepository;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use App\REX\DTO\RexReservation;
use App\REX\Repos\PdoRexReservationRepository;
use PDO;

final class PublicSavedPaletteService
{
    private PdoSavedPaletteRepository $palettes;
    private PdoPVRepository $pvs;
    private PdoRexReservationRepository $rex;

    public function __construct(PDO $pdo)
    {
        $this->palettes = new PdoSavedPaletteRepository($pdo);
        $this->pvs = new PdoPVRepository($pdo);
        $this->rex = new PdoRexReservationRepository($pdo);
    }

    /**
     * Public Saved Palette catalog.
     *
     * Visibility belongs to Saved Palette itself.
     * A palette must also have an active Public PV and active Public Viewer REX
     * before it is useful in the public catalog.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(
        array $filters = [],
        int $limit = 200,
        int $offset = 0
    ): array {
        $filters['is_public'] = 1;

        $rows = $this->palettes->listPalettes(
            $filters,
            max(1, min(200, $limit)),
            max(0, $offset)
        );

        $items = [];

        foreach ($rows as $palette) {
            $savedPaletteId = (int)($palette['id'] ?? 0);

            if ($savedPaletteId <= 0) {
                continue;
            }

            $pv = $this->pvs->findActivePublicBySavedPaletteId(
                $savedPaletteId
            );

            if (!$pv) {
                continue;
            }

            $pvId = (int)($pv['palette_viewer_id'] ?? 0);

            if ($pvId <= 0) {
                continue;
            }

            $viewerRex = $this->findPublicViewerRex($pvId);

            if (!$viewerRex) {
                continue;
            }

            $members = $this->normalizeMembers(
                $this->palettes->getMembersForPalette($savedPaletteId)
            );

            $photo = $this->pickThumbnailPhoto(
                is_array($pv['photos'] ?? null)
                    ? $pv['photos']
                    : []
            );

            $title = $this->firstNonEmpty([
                $pv['viewer_title'] ?? null,
                $palette['display_title'] ?? null,
                $palette['nickname'] ?? null,
                'ColorFix Palette',
            ]);

            $photoUrl = trim((string)($photo['url'] ?? ''));

            $items[] = [
                'id' => $savedPaletteId,
                'palette_hash' => (string)($palette['palette_hash'] ?? ''),
                'brand' => $palette['brand'] ?? null,
                'palette_type' => $palette['palette_type'] ?? null,
                'nickname' => $palette['nickname'] ?? null,
                'display_title' => $title,
                'notes' => $palette['notes'] ?? null,

                'members' => $members,

                'palette_viewer_id' => $pvId,
                'viewer_url' => '/t/' . $viewerRex->token,
                'rex_url' => '/t/' . $viewerRex->token,

                'thumbnail_url' => $photoUrl,
                'photo_url' => $photoUrl,
                'photo_alt' => $photo['alt_text'] ?? null,
            ];
        }

        return $items;
    }

    private function findPublicViewerRex(int $pvId): ?RexReservation
    {
        $reservations = $this->rex->findByResource(
            'palette_viewer',
            $pvId,
            100
        );

        $eligible = [];

        foreach ($reservations as $reservation) {
            if (!$reservation instanceof RexReservation) {
                continue;
            }

            if (
                strtolower(trim($reservation->status)) !== 'active'
                || strtolower(trim($reservation->resolverKey)) !== 'viewer'
            ) {
                continue;
            }

            $experienceKey = strtolower(trim(
                (string)($reservation->experienceKey ?? '')
            ));

            $legacyFormat = strtolower(trim(
                (string)($reservation->context['format'] ?? '')
            ));

            if (
                $experienceKey !== 'public'
                && $legacyFormat !== 'public'
            ) {
                continue;
            }

            $eligible[] = $reservation;
        }

        usort(
            $eligible,
            static fn(RexReservation $a, RexReservation $b): int =>
                $a->id <=> $b->id
        );

        return $eligible[0] ?? null;
    }

    /**
     * @param array<int,array<string,mixed>> $members
     * @return array<int,array<string,mixed>>
     */
    private function normalizeMembers(array $members): array
    {
        $out = [];

        foreach ($members as $member) {
            $out[] = [
                'color_id' => isset($member['color_id'])
                    ? (int)$member['color_id']
                    : null,
                'name' => $member['color_name'] ?? null,
                'brand' => $member['color_brand'] ?? null,
                'brand_name' => $member['color_brand_name'] ?? null,
                'code' => $member['color_code'] ?? null,
                'hex6' => $member['color_hex6'] ?? null,
                'role' => $member['role'] ?? null,
                'sheen' => $member['sheen'] ?? null,
                'note' => $member['note'] ?? null,
                'order_index' => isset($member['order_index'])
                    ? (int)$member['order_index']
                    : 0,
            ];
        }

        return $out;
    }

    /**
     * Prefer FULL, otherwise use the first usable PV photo.
     *
     * @param array<int,array<string,mixed>> $photos
     * @return array<string,mixed>
     */
    private function pickThumbnailPhoto(array $photos): array
    {
        $fallback = [];

        foreach ($photos as $photo) {
            $url = trim((string)($photo['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            if ($fallback === []) {
                $fallback = $photo;
            }

            if (
                strtolower(trim((string)($photo['photo_type'] ?? '')))
                === 'full'
            ) {
                return $photo;
            }
        }

        return $fallback;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)($value ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
