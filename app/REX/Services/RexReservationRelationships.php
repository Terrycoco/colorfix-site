<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationLink;
use App\REX\DTO\RexReservationRelationship;
use InvalidArgumentException;

final class RexReservationRelationships
{
    public function __construct(
        private RexReservationRepositoryInterface $reservations,
    ) {}

    public function create(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
        int $sortOrder = 0,
    ): RexReservationLink {
        $this->validateReservationId($parentReservationId, 'Parent reservation ID');
        $this->validateReservationId($childReservationId, 'Child reservation ID');
        $this->validateDistinctReservations($parentReservationId, $childReservationId);
        $this->requireReservation($parentReservationId, 'Parent reservation');
        $this->requireReservation($childReservationId, 'Child reservation');
        $relationshipKey = $this->normalizeRelationshipKey($relationshipKey);
        $this->validateSortOrder($sortOrder);
        $this->rejectDuplicate($parentReservationId, $childReservationId, $relationshipKey);

        return $this->reservations->createLink(
            $parentReservationId,
            $childReservationId,
            $relationshipKey,
            $sortOrder,
        );
    }

    public function remove(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
    ): bool {
        $this->validateReservationId($parentReservationId, 'Parent reservation ID');
        $this->validateReservationId($childReservationId, 'Child reservation ID');
        $this->validateDistinctReservations($parentReservationId, $childReservationId);
        $relationshipKey = $this->normalizeRelationshipKey($relationshipKey);

        return $this->reservations->removeLink(
            $parentReservationId,
            $childReservationId,
            $relationshipKey,
        );
    }

    /**
     * @return RexReservation[]
     */
    public function children(int $parentReservationId, ?string $relationshipKey = null): array
    {
        $this->validateReservationId($parentReservationId, 'Parent reservation ID');

        return $this->reservations->findChildReservations(
            $parentReservationId,
            $this->normalizeOptionalRelationshipKey($relationshipKey),
        );
    }

    /**
     * @return RexReservationRelationship[]
     */
    public function childRelationships(int $parentReservationId, ?string $relationshipKey = null): array
    {
        $this->validateReservationId($parentReservationId, 'Parent reservation ID');

        return $this->reservations->findChildRelationships(
            $parentReservationId,
            $this->normalizeOptionalRelationshipKey($relationshipKey),
        );
    }

    /**
     * @return RexReservation[]
     */
    public function parents(int $childReservationId, ?string $relationshipKey = null): array
    {
        $this->validateReservationId($childReservationId, 'Child reservation ID');

        return $this->reservations->findParentReservations(
            $childReservationId,
            $this->normalizeOptionalRelationshipKey($relationshipKey),
        );
    }

    /**
     * @return RexReservationRelationship[]
     */
    public function parentRelationships(int $childReservationId, ?string $relationshipKey = null): array
    {
        $this->validateReservationId($childReservationId, 'Child reservation ID');

        return $this->reservations->findParentRelationships(
            $childReservationId,
            $this->normalizeOptionalRelationshipKey($relationshipKey),
        );
    }

    public function publicUrl(RexReservation $reservation): string
    {
        return '/t/' . $reservation->token;
    }

    private function validateReservationId(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException("{$label} must be positive.");
        }
    }

    private function validateDistinctReservations(int $parentReservationId, int $childReservationId): void
    {
        if ($parentReservationId === $childReservationId) {
            throw new InvalidArgumentException('A REX reservation cannot link to itself.');
        }
    }

    private function validateSortOrder(int $sortOrder): void
    {
        if ($sortOrder < 0) {
            throw new InvalidArgumentException('REX relationship sort_order must be zero or greater.');
        }
    }

    private function requireReservation(int $reservationId, string $label): RexReservation
    {
        $reservation = $this->reservations->findById($reservationId);
        if (!$reservation) {
            throw new InvalidArgumentException("{$label} was not found.");
        }

        return $reservation;
    }

    private function rejectDuplicate(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
    ): void {
        foreach ($this->reservations->findChildRelationships($parentReservationId, $relationshipKey) as $relationship) {
            if ($relationship->reservation->id === $childReservationId) {
                throw new InvalidArgumentException('That REX relationship already exists.');
            }
        }
    }

    private function normalizeOptionalRelationshipKey(?string $relationshipKey): ?string
    {
        if ($relationshipKey === null) {
            return null;
        }

        return $this->normalizeRelationshipKey($relationshipKey);
    }

    private function normalizeRelationshipKey(string $relationshipKey): string
    {
        $relationshipKey = trim($relationshipKey);
        if ($relationshipKey === '') {
            throw new InvalidArgumentException('REX relationship_key is required.');
        }

        return $relationshipKey;
    }
}
