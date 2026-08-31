<?php
declare(strict_types=1);

namespace App\REX\Contracts;

use App\REX\DTO\RexAlias;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationLink;
use App\REX\DTO\RexReservationRelationship;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\DTO\RexUpdateDestinationRequest;
use App\REX\DTO\RexUpdateMetadataRequest;

interface RexReservationRepositoryInterface
{
    public function create(RexCreateReservationRequest $request, string $token): RexReservation;

    public function findById(int $id): ?RexReservation;

    public function findByToken(string $token): ?RexReservation;

    public function findByAlias(string $alias): ?RexReservation;

    /**
     * @return RexReservation[]
     */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 100): array;

    /**
     * @return RexReservation[]
     */
    public function search(RexReservationSearchCriteria $criteria): array;

    /**
     * @return RexAlias[]
     */
    public function listAliases(int $reservationId): array;

    public function addAlias(int $reservationId, string $alias): RexAlias;

    public function removeAlias(int $reservationId, string $alias): bool;

    public function createLink(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
        int $sortOrder = 0,
    ): RexReservationLink;

    public function removeLink(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
    ): bool;

    /**
     * @return RexReservation[]
     */
    public function findChildReservations(
        int $parentReservationId,
        ?string $relationshipKey = null,
    ): array;

    /**
     * @return RexReservationRelationship[]
     */
    public function findChildRelationships(
        int $parentReservationId,
        ?string $relationshipKey = null,
    ): array;

    /**
     * @return RexReservation[]
     */
    public function findParentReservations(
        int $childReservationId,
        ?string $relationshipKey = null,
    ): array;

    /**
     * @return RexReservationRelationship[]
     */
    public function findParentRelationships(
        int $childReservationId,
        ?string $relationshipKey = null,
    ): array;

    public function updateDestination(RexUpdateDestinationRequest $request): RexReservation;

    public function updateMetadata(RexUpdateMetadataRequest $request): RexReservation;

    public function revoke(int $reservationId): RexReservation;

    public function reactivate(int $reservationId): RexReservation;

    public function tokenExists(string $token): bool;

    /**
     * @param int[] $resourceIds
     * @return array<int, RexReservation[]>
     */
    public function findActiveByResourceIds(
        string $resolverKey,
        string $resourceType,
        array $resourceIds,
    ): array;

    public function findActiveByResourceIdsAndExperience(
        string $resolverKey,
        string $resourceType,
        array $resourceIds,
        string $experienceKey,
    ): array;


public function setFallbackRexId(
    int $reservationId,
    ?int $fallbackRexId,
): RexReservation;




}
