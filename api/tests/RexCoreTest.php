<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\Contracts\RexResolverInterface;
use App\REX\DTO\RexAlias;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationLink;
use App\REX\DTO\RexReservationRelationship;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use App\REX\DTO\RexUpdateDestinationRequest;
use App\REX\DTO\RexUpdateMetadataRequest;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistry;
use App\REX\Services\RexReserver;
use App\REX\Services\RexResolver;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexTokenGenerator;
use App\REX\DTO\RexReservationDescriptor;
use App\Services\ProjectReleaseSelectionService;
use App\PLAYLISTS\Entities\PlaylistItem;

final class RexCoreTestRepository implements RexReservationRepositoryInterface
{
    public ?RexReservation $reservation = null;
    public array $reservations = [];
    public array $existingTokens = [];
    public array $links = [];

    public function create(RexCreateReservationRequest $request, string $token): RexReservation
    {
        $this->reservation = new RexReservation(
            id: 123,
            token: $token,
            label: $request->label,
            adminNote: $request->adminNote,
            resolverKey: $request->resolverKey,
            resourceType: $request->resourceType,
            resourceId: $request->resourceId,
            context: $request->context,
            status: $request->status,
            revokedAt: null,
            createdAt: '2026-08-10 00:00:00',
            updatedAt: '2026-08-10 00:00:00',
        );
        $this->reservations[$this->reservation->id] = $this->reservation;

        return $this->reservation;
    }

    public function findById(int $id): ?RexReservation
    {
        return $this->reservations[$id] ?? null;
    }

    public function findByToken(string $token): ?RexReservation
    {
        return $this->reservation && $this->reservation->token === $token ? $this->reservation : null;
    }

    public function findByAlias(string $alias): ?RexReservation
    {
        return $alias === 'sample-alias' ? $this->reservation : null;
    }

    public function findByResource(string $resourceType, int $resourceId, int $limit = 100): array
    {
        if (!$this->reservation) {
            return [];
        }

        return $this->reservation->resourceType === $resourceType && $this->reservation->resourceId === $resourceId
            ? [$this->reservation]
            : [];
    }

    public function search(RexReservationSearchCriteria $criteria): array
    {
        return $this->reservation ? [$this->reservation] : [];
    }

    public function listAliases(int $reservationId): array
    {
        return [new RexAlias(1, $reservationId, 'sample-alias', null, null)];
    }

    public function addAlias(int $reservationId, string $alias): RexAlias
    {
        return new RexAlias(2, $reservationId, $alias, null, null);
    }

    public function removeAlias(int $reservationId, string $alias): bool
    {
        return true;
    }

    public function createLink(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
        int $sortOrder = 0,
    ): RexReservationLink {
        $link = new RexReservationLink(
            id: count($this->links) + 1,
            parentReservationId: $parentReservationId,
            childReservationId: $childReservationId,
            relationshipKey: $relationshipKey,
            sortOrder: $sortOrder,
            createdAt: null,
            updatedAt: null,
        );
        $this->links[] = $link;

        return $link;
    }

    public function removeLink(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
    ): bool {
        $before = count($this->links);
        $this->links = array_values(array_filter(
            $this->links,
            static fn(RexReservationLink $link): bool => !(
                $link->parentReservationId === $parentReservationId
                && $link->childReservationId === $childReservationId
                && $link->relationshipKey === $relationshipKey
            )
        ));

        return count($this->links) < $before;
    }

    public function findChildReservations(
        int $parentReservationId,
        ?string $relationshipKey = null,
    ): array {
        return array_map(
            static fn(RexReservationRelationship $relationship): RexReservation => $relationship->reservation,
            $this->findChildRelationships($parentReservationId, $relationshipKey)
        );
    }

    public function findChildRelationships(
        int $parentReservationId,
        ?string $relationshipKey = null,
    ): array {
        $links = array_values(array_filter(
            $this->links,
            static fn(RexReservationLink $link): bool =>
                $link->parentReservationId === $parentReservationId
                && ($relationshipKey === null || $link->relationshipKey === $relationshipKey)
        ));
        usort(
            $links,
            static fn(RexReservationLink $a, RexReservationLink $b): int =>
                [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]
        );

        return array_values(array_filter(array_map(
            function (RexReservationLink $link): ?RexReservationRelationship {
                $reservation = $this->reservations[$link->childReservationId] ?? null;
                if (!$reservation) {
                    return null;
                }

                return new RexReservationRelationship(
                    linkId: $link->id,
                    relationshipKey: $link->relationshipKey,
                    sortOrder: $link->sortOrder,
                    reservation: $reservation,
                    createdAt: $link->createdAt,
                    updatedAt: $link->updatedAt,
                );
            },
            $links
        )));
    }

    public function findParentReservations(
        int $childReservationId,
        ?string $relationshipKey = null,
    ): array {
        return array_map(
            static fn(RexReservationRelationship $relationship): RexReservation => $relationship->reservation,
            $this->findParentRelationships($childReservationId, $relationshipKey)
        );
    }

    public function findParentRelationships(
        int $childReservationId,
        ?string $relationshipKey = null,
    ): array {
        $links = array_values(array_filter(
            $this->links,
            static fn(RexReservationLink $link): bool =>
                $link->childReservationId === $childReservationId
                && ($relationshipKey === null || $link->relationshipKey === $relationshipKey)
        ));
        usort(
            $links,
            static fn(RexReservationLink $a, RexReservationLink $b): int =>
                [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]
        );

        return array_values(array_filter(array_map(
            function (RexReservationLink $link): ?RexReservationRelationship {
                $reservation = $this->reservations[$link->parentReservationId] ?? null;
                if (!$reservation) {
                    return null;
                }

                return new RexReservationRelationship(
                    linkId: $link->id,
                    relationshipKey: $link->relationshipKey,
                    sortOrder: $link->sortOrder,
                    reservation: $reservation,
                    createdAt: $link->createdAt,
                    updatedAt: $link->updatedAt,
                );
            },
            $links
        )));
    }

    public function updateDestination(RexUpdateDestinationRequest $request): RexReservation
    {
        if (!$this->reservation) {
            throw new RuntimeException('no reservation');
        }

        $this->reservation = new RexReservation(
            id: $this->reservation->id,
            token: $this->reservation->token,
            label: $this->reservation->label,
            adminNote: $this->reservation->adminNote,
            resolverKey: $request->resolverKey,
            resourceType: $request->resourceType,
            resourceId: $request->resourceId,
            context: $request->context,
            status: $this->reservation->status,
            revokedAt: $this->reservation->revokedAt,
            createdAt: $this->reservation->createdAt,
            updatedAt: $this->reservation->updatedAt,
        );

        return $this->reservation;
    }

    public function updateMetadata(RexUpdateMetadataRequest $request): RexReservation
    {
        if (!$this->reservation) {
            throw new RuntimeException('no reservation');
        }

        $this->reservation = new RexReservation(
            id: $this->reservation->id,
            token: $this->reservation->token,
            label: $request->label,
            adminNote: $this->reservation->adminNote,
            resolverKey: $this->reservation->resolverKey,
            resourceType: $this->reservation->resourceType,
            resourceId: $this->reservation->resourceId,
            context: $this->reservation->context,
            status: $this->reservation->status,
            revokedAt: $this->reservation->revokedAt,
            createdAt: $this->reservation->createdAt,
            updatedAt: $this->reservation->updatedAt,
        );

        return $this->reservation;
    }

    public function revoke(int $reservationId): RexReservation
    {
        if (!$this->reservation) {
            throw new RuntimeException('no reservation');
        }

        $this->reservation = new RexReservation(
            id: $this->reservation->id,
            token: $this->reservation->token,
            label: $this->reservation->label,
            adminNote: $this->reservation->adminNote,
            resolverKey: $this->reservation->resolverKey,
            resourceType: $this->reservation->resourceType,
            resourceId: $this->reservation->resourceId,
            context: $this->reservation->context,
            status: RexReserver::STATUS_REVOKED,
            revokedAt: '2026-08-10 00:00:00',
            createdAt: $this->reservation->createdAt,
            updatedAt: $this->reservation->updatedAt,
        );

        return $this->reservation;
    }

    public function reactivate(int $reservationId): RexReservation
    {
        if (!$this->reservation) {
            throw new RuntimeException('no reservation');
        }

        $this->reservation = new RexReservation(
            id: $this->reservation->id,
            token: $this->reservation->token,
            label: $this->reservation->label,
            adminNote: $this->reservation->adminNote,
            resolverKey: $this->reservation->resolverKey,
            resourceType: $this->reservation->resourceType,
            resourceId: $this->reservation->resourceId,
            context: $this->reservation->context,
            status: RexReserver::STATUS_ACTIVE,
            revokedAt: null,
            createdAt: $this->reservation->createdAt,
            updatedAt: $this->reservation->updatedAt,
        );

        return $this->reservation;
    }

    public function tokenExists(string $token): bool
    {
        return in_array($token, $this->existingTokens, true);
    }

    public function findActiveByResourceIds(
        string $resolverKey,
        string $resourceType,
        array $resourceIds,
    ): array {
        $resourceIdSet = array_flip(array_map('intval', $resourceIds));
        $grouped = [];

        foreach ($this->reservations as $reservation) {
            if (
                $reservation->status !== RexReserver::STATUS_ACTIVE
                || $reservation->resolverKey !== $resolverKey
                || $reservation->resourceType !== $resourceType
                || !isset($resourceIdSet[$reservation->resourceId])
            ) {
                continue;
            }

            $grouped[$reservation->resourceId] ??= [];
            $grouped[$reservation->resourceId][] = $reservation;
        }

        return $grouped;
    }
}

final class RexCoreTestResolver implements RexResolverInterface
{
    public ?RexResolutionRequest $lastRequest = null;

    public function resolve(RexResolutionRequest $request): RexResolutionResult
    {
        $this->lastRequest = $request;

        return new RexResolutionResult(
            resolverKey: $request->reservation->resolverKey,
            resourceType: $request->resourceType,
            resourceId: $request->resourceId,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: new RexShareMetadata(
                title: 'Share title',
                description: 'Share description',
                imageUrl: '/share.jpg',
            ),
            destination: ['ok' => true],
            analyticsMetadata: ['matched_by' => $request->matchedBy],
        );
    }

    public function describe(
            RexReservation $reservation
        ): RexReservationDescriptor {
            return new RexReservationDescriptor(
                title: 'Test reservation',
                fields: [
                    [
                        'label' => 'Resource',
                        'value' => "{$reservation->resourceType} #{$reservation->resourceId}",
                    ],
                ],
            );
        }


        public function previewDescribe(
            string $resourceType,
            int $resourceId,
            array $context
        ): RexReservationDescriptor {
            return new RexReservationDescriptor(
                title: 'Test reservation preview',
                fields: [
                    [
                        'label' => 'Resource',
                        'value' => "{$resourceType} #{$resourceId}",
                    ],
                ],
            );
        }

        }

test('rex token generator returns base64url 32-byte token shape', function () {
    $token = (new RexTokenGenerator())->generate();

    assert_equals(43, strlen($token), 'REX token length mismatch');
    assert_true((bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $token), 'REX token is not base64url');
});

test('rex reserver creates typed reservation with context', function () {
    $repo = new RexCoreTestRepository();
    $reserver = new RexReserver($repo, new RexTokenGenerator());

    $reservation = $reserver->reserve(new RexCreateReservationRequest(
        label: 'Project sample',
        resolverKey: 'test_resolver',
        resourceType: 'project',
        resourceId: 42,
        context: ['experience_key' => 'concept'],
    ));

    assert_equals('Project sample', $reservation->label);
    assert_equals('test_resolver', $reservation->resolverKey);
    assert_equals('project', $reservation->resourceType);
    assert_equals(42, $reservation->resourceId);
    assert_equals(['experience_key' => 'concept'], $reservation->context);
    assert_equals(RexReserver::STATUS_ACTIVE, $reservation->status);
});

test('rex resolver registry rejects unknown resolver keys', function () {
    $registry = new RexResolverRegistry();

    try {
        $registry->get('missing');
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('expected registry exception was not thrown');
});

test('rex central resolver delegates token lookup to registered resolver', function () {
    $repo = new RexCoreTestRepository();
    $reservation = (new RexReserver($repo, new RexTokenGenerator()))->reserve(new RexCreateReservationRequest(
        label: 'Project sample',
        resolverKey: 'test_resolver',
        resourceType: 'project',
        resourceId: 42,
        context: ['experience_key' => 'client'],
    ));

    $stub = new RexCoreTestResolver();
    $registry = new RexResolverRegistry();
    $registry->register('test_resolver', $stub);

    $result = (new RexResolver($repo, $registry))->resolveToken($reservation->token, ['ip' => '127.0.0.1']);

    assert_equals(RexResolutionBehavior::RENDER, $result->behavior);
    assert_equals('project', $result->resourceType);
    assert_equals(42, $result->resourceId);
    assert_equals('Share title', $result->shareMetadata->title);
    assert_equals('Share description', $result->shareMetadata->description);
    assert_equals('/share.jpg', $result->shareMetadata->imageUrl);
    assert_equals('token', $stub->lastRequest?->matchedBy);
    assert_equals(['experience_key' => 'client'], $stub->lastRequest?->context);
    assert_equals(['ip' => '127.0.0.1'], $stub->lastRequest?->requestMetadata);
});

test('rex central resolver delegates alias lookup to same reservation object', function () {
    $repo = new RexCoreTestRepository();
    (new RexReserver($repo, new RexTokenGenerator()))->reserve(new RexCreateReservationRequest(
        label: 'Alias sample',
        resolverKey: 'test_resolver',
        resourceType: 'playlist',
        resourceId: 99,
    ));

    $stub = new RexCoreTestResolver();
    $registry = new RexResolverRegistry();
    $registry->register('test_resolver', $stub);

    $result = (new RexResolver($repo, $registry))->resolveAlias('sample-alias');

    assert_equals('playlist', $result->resourceType);
    assert_equals(99, $result->resourceId);
    assert_equals('alias', $stub->lastRequest?->matchedBy);
    assert_equals('sample-alias', $stub->lastRequest?->lookupValue);
});

test('rex resolution result accepts supported redirect behavior with destination', function () {
    $result = new RexResolutionResult(
        resolverKey: 'test_resolver',
        resourceType: 'project',
        resourceId: 42,
        behavior: RexResolutionBehavior::REDIRECT,
        shareMetadata: new RexShareMetadata(),
        destination: ['path' => '/somewhere'],
    );

    assert_equals(RexResolutionBehavior::REDIRECT, $result->behavior);
    assert_equals('/somewhere', $result->destination['path']);
});

test('rex resolution result rejects redirect without destination url or path', function () {
    try {
        new RexResolutionResult(
            resolverKey: 'test_resolver',
            resourceType: 'project',
            resourceId: 42,
            behavior: RexResolutionBehavior::REDIRECT,
            shareMetadata: new RexShareMetadata(),
            destination: ['id' => 42],
        );
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('expected redirect destination exception was not thrown');
});

test('rex resolution result accepts supported render behavior with generic destination data', function () {
    $result = new RexResolutionResult(
        resolverKey: 'test_resolver',
        resourceType: 'project',
        resourceId: 42,
        behavior: RexResolutionBehavior::RENDER,
        shareMetadata: new RexShareMetadata(),
        destination: ['entry' => 'project_public_view'],
    );

    assert_equals(RexResolutionBehavior::RENDER, $result->behavior);
    assert_equals('project_public_view', $result->destination['entry']);
});

test('rex resolution result rejects render without destination data', function () {
    try {
        new RexResolutionResult(
            resolverKey: 'test_resolver',
            resourceType: 'project',
            resourceId: 42,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: new RexShareMetadata(),
        );
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('expected render destination exception was not thrown');
});

test('rex pdo search finds reservations by alias and identifiers without duplicates', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE rex_reservations (
            id INTEGER PRIMARY KEY,
            token TEXT NOT NULL,
            label TEXT NOT NULL,
            admin_note TEXT NULL,
            resolver_key TEXT NOT NULL,
            resource_type TEXT NOT NULL,
            resource_id INTEGER NOT NULL,
            context_json TEXT NULL,
            status TEXT NOT NULL,
            revoked_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE rex_aliases (
            id INTEGER PRIMARY KEY,
            reservation_id INTEGER NOT NULL,
            alias TEXT NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );
    $pdo->exec(
        "INSERT INTO rex_reservations
            (id, token, label, resolver_key, resource_type, resource_id, context_json, status, created_at)
         VALUES
            (77, 'abcTokenFragment123', 'Kitchen Project', 'project_experience', 'project', 555, '{\"experience_key\":\"client\"}', 'active', '2026-08-10 00:00:00')"
    );
    $pdo->exec(
        "INSERT INTO rex_aliases
            (id, reservation_id, alias)
         VALUES
            (1, 77, 'mojdeh-kitchen'),
            (2, 77, 'mojdeh-kitchen-alt')"
    );

    $repo = new PdoRexReservationRepository($pdo);

    foreach (['TokenFragment', 'mojdeh-kitchen', '77', '555', 'Kitchen', 'project', 'project_experience'] as $query) {
        $rows = $repo->search(new RexReservationSearchCriteria(query: $query));
        assert_equals(1, count($rows), "search failed for {$query}");
        assert_equals(77, $rows[0]->id, "search returned wrong reservation for {$query}");
    }
});

test('rex pdo findByResource returns empty array for object with no reservations', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE rex_reservations (
            id INTEGER PRIMARY KEY,
            token TEXT NOT NULL,
            label TEXT NOT NULL,
            admin_note TEXT NULL,
            resolver_key TEXT NOT NULL,
            resource_type TEXT NOT NULL,
            resource_id INTEGER NOT NULL,
            context_json TEXT NULL,
            status TEXT NOT NULL,
            revoked_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE rex_aliases (
            id INTEGER PRIMARY KEY,
            reservation_id INTEGER NOT NULL,
            alias TEXT NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );

    $repo = new PdoRexReservationRepository($pdo);
    assert_equals([], $repo->findByResource('project', 999));
});

test('rex repository metadata and destination updates preserve token', function () {
    $repo = new RexCoreTestRepository();
    $reservation = (new RexReserver($repo, new RexTokenGenerator()))->reserve(new RexCreateReservationRequest(
        label: 'Original',
        resolverKey: 'original_resolver',
        resourceType: 'project',
        resourceId: 1,
    ));
    $token = $reservation->token;

    $repo->updateMetadata(new RexUpdateMetadataRequest(
        reservationId: $reservation->id,
        label: 'Updated',
    ));
    $updated = $repo->updateDestination(new RexUpdateDestinationRequest(
        reservationId: $reservation->id,
        resolverKey: 'new_resolver',
        resourceType: 'playlist',
        resourceId: 22,
        context: ['mode' => 'test'],
    ));

    assert_equals($token, $updated->token);
    assert_equals('Updated', $updated->label);
    assert_equals('new_resolver', $updated->resolverKey);
    assert_equals('playlist', $updated->resourceType);
    assert_equals(22, $updated->resourceId);
});

test('rex revoke and reactivate preserve token', function () {
    $repo = new RexCoreTestRepository();
    $reservation = (new RexReserver($repo, new RexTokenGenerator()))->reserve(new RexCreateReservationRequest(
        label: 'Permanent',
        resolverKey: 'resolver',
        resourceType: 'project',
        resourceId: 1,
    ));
    $token = $reservation->token;

    $revoked = $repo->revoke($reservation->id);
    assert_equals($token, $revoked->token);
    assert_equals(RexReserver::STATUS_REVOKED, $revoked->status);

    $reactivated = $repo->reactivate($reservation->id);
    assert_equals($token, $reactivated->token);
    assert_equals(RexReserver::STATUS_ACTIVE, $reactivated->status);
});

test('rex relationship service creates removes and formats generic reservation links', function () {
    $repo = new RexCoreTestRepository();
    $repo->reservations[1] = new RexReservation(
        id: 1,
        token: 'parentToken',
        label: 'Parent',
        adminNote: null,
        resolverKey: 'resolver',
        resourceType: 'generic',
        resourceId: 1,
        context: [],
        status: 'active',
        revokedAt: null,
        createdAt: null,
        updatedAt: null,
    );
    $repo->reservations[2] = new RexReservation(
        id: 2,
        token: 'childToken',
        label: 'Child',
        adminNote: null,
        resolverKey: 'resolver',
        resourceType: 'generic',
        resourceId: 2,
        context: [],
        status: 'active',
        revokedAt: null,
        createdAt: null,
        updatedAt: null,
    );
    $relationships = new RexReservationRelationships($repo);

    $link = $relationships->create(1, 2, 'related', 20);

    assert_equals(1, $link->parentReservationId);
    assert_equals(2, $link->childReservationId);
    assert_equals('related', $link->relationshipKey);
    assert_equals(20, $link->sortOrder);
    assert_equals('/t/childToken', $relationships->publicUrl($repo->reservations[2]));
    assert_equals([2], array_map(
        static fn(RexReservation $reservation): int => $reservation->id,
        $relationships->children(1, 'related')
    ));
    assert_equals([1], array_map(
        static fn(RexReservation $reservation): int => $reservation->id,
        $relationships->parents(2, 'related')
    ));
    assert_true($relationships->remove(1, 2, 'related'));
    assert_equals([], $relationships->children(1, 'related'));
});

test('rex relationship service rejects invalid generic relationship requests', function () {
    $repo = new RexCoreTestRepository();
    $repo->reservations[1] = new RexReservation(
        id: 1,
        token: 'parentToken',
        label: 'Parent',
        adminNote: null,
        resolverKey: 'resolver',
        resourceType: 'generic',
        resourceId: 1,
        context: [],
        status: 'active',
        revokedAt: null,
        createdAt: null,
        updatedAt: null,
    );
    $repo->reservations[2] = new RexReservation(
        id: 2,
        token: 'childToken',
        label: 'Child',
        adminNote: null,
        resolverKey: 'resolver',
        resourceType: 'generic',
        resourceId: 2,
        context: [],
        status: 'active',
        revokedAt: null,
        createdAt: null,
        updatedAt: null,
    );
    $relationships = new RexReservationRelationships($repo);

    foreach ([
        static fn() => $relationships->create(1, 1, 'related', 0),
        static fn() => $relationships->create(1, 2, '', 0),
        static fn() => $relationships->create(1, 2, 'related', -1),
        static fn() => $relationships->create(999, 2, 'related', 0),
        static fn() => $relationships->create(1, 999, 'related', 0),
    ] as $attempt) {
        try {
            $attempt();
        } catch (InvalidArgumentException $e) {
            continue;
        }

        throw new RuntimeException('expected relationship validation exception was not thrown');
    }

    $relationships->create(1, 2, 'related', 0);

    try {
        $relationships->create(1, 2, 'related', 1);
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('expected duplicate relationship exception was not thrown');
});

test('rex pdo relationship lookup returns child and parent reservations in link order', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE rex_reservations (
            id INTEGER PRIMARY KEY,
            token TEXT NOT NULL,
            label TEXT NOT NULL,
            admin_note TEXT NULL,
            resolver_key TEXT NOT NULL,
            resource_type TEXT NOT NULL,
            resource_id INTEGER NOT NULL,
            context_json TEXT NULL,
            status TEXT NOT NULL,
            revoked_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE rex_aliases (
            id INTEGER PRIMARY KEY,
            reservation_id INTEGER NOT NULL,
            alias TEXT NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE rex_reservation_links (
            id INTEGER PRIMARY KEY,
            parent_reservation_id INTEGER NOT NULL,
            child_reservation_id INTEGER NOT NULL,
            relationship_key TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )"
    );
    $pdo->exec(
        "INSERT INTO rex_reservations
            (id, token, label, resolver_key, resource_type, resource_id, status, created_at)
         VALUES
            (1, 'parentToken', 'Parent', 'generic', 'generic', 101, 'active', '2026-08-10 00:00:00'),
            (2, 'childTokenTwo', 'Child Two', 'generic', 'generic', 202, 'active', '2026-08-10 00:00:00'),
            (3, 'childTokenOne', 'Child One', 'generic', 'generic', 203, 'active', '2026-08-10 00:00:00'),
            (4, 'otherChildToken', 'Other Child', 'generic', 'generic', 204, 'active', '2026-08-10 00:00:00')"
    );

    $repo = new PdoRexReservationRepository($pdo);
    $relationships = new RexReservationRelationships($repo);
    $relationships->create(1, 2, 'related', 20);
    $relationships->create(1, 3, 'related', 10);
    $relationships->create(1, 4, 'other', 5);

    $children = $repo->findChildReservations(1, 'related');
    assert_equals([3, 2], array_map(static fn(RexReservation $reservation): int => $reservation->id, $children));
    assert_equals('childTokenOne', $children[0]->token);

    $childRelationships = $relationships->childRelationships(1, 'related');
    assert_equals([10, 20], array_map(
        static fn(RexReservationRelationship $relationship): int => $relationship->sortOrder,
        $childRelationships
    ));
    assert_equals('related', $childRelationships[0]->relationshipKey);
    assert_equals(3, $childRelationships[0]->reservation->id);

    $allChildren = $repo->findChildReservations(1);
    assert_equals([4, 3, 2], array_map(static fn(RexReservation $reservation): int => $reservation->id, $allChildren));
    assert_equals([], $relationships->children(999, 'related'));

    $parents = $repo->findParentReservations(3, 'related');
    assert_equals(1, count($parents));
    assert_equals(1, $parents[0]->id);
    assert_equals('/t/parentToken', $relationships->publicUrl($parents[0]));

    $parentRelationships = $relationships->parentRelationships(3, 'related');
    assert_equals(1, count($parentRelationships));
    assert_equals('related', $parentRelationships[0]->relationshipKey);
    assert_equals(1, $parentRelationships[0]->reservation->id);

    assert_true($relationships->remove(1, 3, 'related'));
    assert_equals([2], array_map(
        static fn(RexReservation $reservation): int => $reservation->id,
        $repo->findChildReservations(1, 'related')
    ));
});

test('project release selection preserves concept public client and painter rules', function () {
    $ref = new ReflectionClass(ProjectReleaseSelectionService::class);
    $svc = $ref->newInstanceWithoutConstructor();

    $v1 = new PlaylistItem(ap_id: 'v1', palette_hash: null, image_url: null, version_number: 1, is_final: false, color_plan_id: 101);
    $v2 = new PlaylistItem(ap_id: 'v2', palette_hash: null, image_url: null, version_number: 2, is_final: false, color_plan_id: 102);
    $final = new PlaylistItem(ap_id: 'final', palette_hash: null, image_url: null, version_number: 3, is_final: true, color_plan_id: 101);
    $items = [$v1, $v2, $final];

    assert_equals('FINAL', $svc->normalizeCurrentRelease(' final '));
    assert_equals('2', $svc->normalizeCurrentRelease('02'));

    assert_equals($items, $svc->filterItemsForRelease($items, 'concept', '1'));
    assert_equals($items, $svc->filterItemsForRelease($items, 'public', '1'));
    assert_equals([$v1, $v2], $svc->filterItemsForRelease($items, 'client', '2'));
    assert_equals([$v1, $v2], $svc->filterItemsForRelease($items, 'painter', '2'));
    assert_equals([$final], $svc->filterItemsForRelease($items, 'painter', 'FINAL'));
    assert_equals([101, 102], $svc->collectColorPlanIds($items));
});
