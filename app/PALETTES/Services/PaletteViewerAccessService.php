<?php
declare(strict_types=1);

namespace App\PALETTES\Services;

use App\PALETTES\Repos\PdoPaletteEditorRepository;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PaletteViewerAccessService
{
    private PdoPaletteEditorRepository $editor;
    private PdoRexReservationRepository $rexReservations;
    private RexReservationRelationships $rexRelationships;
    private RexReserver $rexReserver;

    public function __construct(PDO $pdo)
    {
        $this->editor = new PdoPaletteEditorRepository($pdo);

        $this->rexReservations =
            new PdoRexReservationRepository($pdo);

        $this->rexRelationships =
            new RexReservationRelationships(
                $this->rexReservations
            );

        $this->rexReserver =
            new RexReserver(
                $this->rexReservations,
                new RexTokenGenerator()
            );
    }

    public function openForPalette(int $paletteId): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException(
                'Valid Palette ID is required.'
            );
        }

        $palette = $this->editor
            ->getEditorItem($paletteId);

        if (!$palette) {
            throw new RuntimeException(
                "Palette {$paletteId} was not found."
            );
        }

        $pv = $this->editor
            ->findPublicPVByPaletteId($paletteId);

        if (!$pv) {
            $pvId = $this->editor->createPublicPV(
                $paletteId,
                null,
                $this->firstNonEmpty([
                    $palette['nickname'] ?? null,
                    "Palette #{$paletteId}",
                ]),
                null
            );

            $pv = $this->editor
                ->findPublicPVByPaletteId($paletteId);

            if (!$pv) {
                throw new RuntimeException(
                    'PV creation did not return a Public PV.'
                );
            }
        }

        $pvId = (int)$pv['palette_viewer_id'];

        $matches = $this->activeReservations($pvId);

        if (count($matches) > 1) {
            throw new RuntimeException(
                'Multiple active REX reservations match this PV. No new reservation was created.'
            );
        }

        if (count($matches) === 1) {
            $reservation = $matches[0];
            $reused = true;
        } else {
            $reservation = $this->rexReserver->reserve(
                new RexCreateReservationRequest(
                    label: $this->firstNonEmpty([
                        $pv['title'] ?? null,
                        $palette['nickname'] ?? null,
                        "PV #{$pvId}",
                    ]),
                    resolverKey: 'viewer',
                    resourceType: 'palette_viewer',
                    resourceId: $pvId,
                    adminNote: 'Auto-created from Admin Palettes Open PV.',
                    context: [
                        'format' => $pv['format'] ?: 'public',
                    ],
                )
            );

            $reused = false;
        }

        return [
            'palette_id' => $paletteId,
            'palette_viewer_id' => $pvId,
            'reservation_id' => $reservation->id,
            'token' => $reservation->token,
            'public_url' =>
                $this->rexRelationships
                    ->publicUrl($reservation),
            'reused' => $reused,
        ];
    }

    /**
     * @return RexReservation[]
     */
    private function activeReservations(int $pvId): array
    {
        $matches = $this->rexReservations->search(
            new RexReservationSearchCriteria(
                resolverKey: 'viewer',
                resourceType: 'palette_viewer',
                resourceId: $pvId,
                status: RexReserver::STATUS_ACTIVE,
                limit: 100,
            )
        );

        $matches = array_values(
            array_filter(
                $matches,
                static fn(
                    RexReservation $reservation
                ): bool =>
                    $reservation->resolverKey === 'viewer'
                    && $reservation->resourceType
                        === 'palette_viewer'
                    && $reservation->resourceId === $pvId
                    && $reservation->status
                        === RexReserver::STATUS_ACTIVE
                    && $reservation->revokedAt === null
            )
        );

        usort(
            $matches,
            static fn(
                RexReservation $a,
                RexReservation $b
            ): int => $a->id <=> $b->id
        );

        return $matches;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim(
                (string)($value ?? '')
            );

            if ($text !== '') {
                return $text;
            }
        }

        return 'Palette Viewer';
    }
}
