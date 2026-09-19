<?php
declare(strict_types=1);

namespace App\REX\Repos;

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexAlias;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationLink;
use App\REX\DTO\RexReservationRelationship;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\DTO\RexUpdateDestinationRequest;
use App\REX\DTO\RexUpdateMetadataRequest;
use PDO;
use RuntimeException;

final class PdoRexReservationRepository implements RexReservationRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function create(RexCreateReservationRequest $request, string $token): RexReservation
    {
        $experienceKey = $this->resolveExperienceKey(
            $request->experienceKey,
            $request->context,
        );

        $stmt = $this->pdo->prepare(
            "INSERT INTO rex_reservations (
                token,
                label,
                admin_note,
                resolver_key,
                experience_key,
                resource_type,
                resource_id,
                context_json,
                status,
                revoked_at,
                created_at,
                updated_at
             ) VALUES (
                :token,
                :label,
                :admin_note,
                :resolver_key,
                :experience_key,
                :resource_type,
                :resource_id,
                :context_json,
                :status,
                :revoked_at,
                NOW(),
                NOW()
             )"
        );

        $stmt->execute([
            ':token' => $token,
            ':label' => trim($request->label),
            ':admin_note' => $this->nullableTrim($request->adminNote),
            ':resolver_key' => trim($request->resolverKey),
            ':experience_key' => $experienceKey,
            ':resource_type' => trim($request->resourceType),
            ':resource_id' => $request->resourceId,
            ':context_json' => $this->encodeContext($request->context),
            ':status' => $request->status,
            ':revoked_at' => $request->status === 'revoked' ? $this->nowString() : null,
        ]);

        return $this->requireReservation((int)$this->pdo->lastInsertId());
    }

    public function findById(int $id): ?RexReservation
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rex_reservations WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->rowToReservation($row) : null;
    }

    public function findByToken(string $token): ?RexReservation
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rex_reservations WHERE token = :token LIMIT 1'
        );
        $stmt->execute([':token' => trim($token)]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->rowToReservation($row) : null;
    }

    public function findByAlias(string $alias): ?RexReservation
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*
               FROM rex_aliases a
               INNER JOIN rex_reservations r
                 ON r.id = a.reservation_id
              WHERE a.alias = :alias
              LIMIT 1"
        );

        $stmt->execute([':alias' => trim($alias)]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->rowToReservation($row) : null;
    }

    public function findByResource(
        string $resourceType,
        int $resourceId,
        int $limit = 100,
    ): array {
        return $this->search(new RexReservationSearchCriteria(
            resourceType: $resourceType,
            resourceId: $resourceId,
            limit: $limit,
        ));
    }

public function deleteByResource(
    string $resourceType,
    int $resourceId,
): int {
    $resourceType = trim($resourceType);

    if ($resourceType === '' || $resourceId <= 0) {
        return 0;
    }

    $stmt = $this->pdo->prepare(
        'SELECT id
           FROM rex_reservations
          WHERE resource_type = :resource_type
            AND resource_id = :resource_id
          ORDER BY id ASC'
    );

    $stmt->execute([
        ':resource_type' => $resourceType,
        ':resource_id' => $resourceId,
    ]);

    $reservationIds = array_values(array_filter(
        array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [],
        ),
        static fn(int $id): bool => $id > 0,
    ));

    if ($reservationIds === []) {
        return 0;
    }

    $placeholders = [];
    $params = [];

    foreach ($reservationIds as $index => $reservationId) {
        $placeholder = ':rex_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $reservationId;
    }

    $in = implode(', ', $placeholders);

    /*
     * Other REX reservations may point at one of these reservations
     * as a fallback. Clear those references before deleting.
     */
    $stmt = $this->pdo->prepare(
        "UPDATE rex_reservations
            SET fallback_rex_id = NULL,
                updated_at = NOW()
          WHERE fallback_rex_id IN ({$in})"
    );
    $stmt->execute($params);

    /*
     * Delete relationship edges in both directions.
     */
    $stmt = $this->pdo->prepare(
        "DELETE FROM rex_reservation_links
          WHERE parent_reservation_id IN ({$in})"
    );
    $stmt->execute($params);

    $stmt = $this->pdo->prepare(
        "DELETE FROM rex_reservation_links
          WHERE child_reservation_id IN ({$in})"
    );
    $stmt->execute($params);

    /*
     * Aliases are owned by the reservation.
     */
    $stmt = $this->pdo->prepare(
        "DELETE FROM rex_aliases
          WHERE reservation_id IN ({$in})"
    );
    $stmt->execute($params);

    /*
     * Finally delete the reservations themselves.
     */
    $stmt = $this->pdo->prepare(
        "DELETE FROM rex_reservations
          WHERE id IN ({$in})"
    );
    $stmt->execute($params);

    return $stmt->rowCount();
}




    public function search(RexReservationSearchCriteria $criteria): array
    {
        $where = [];
        $params = [];

        if ($criteria->query !== null && trim($criteria->query) !== '') {
            $where[] = "(
                r.label LIKE :query
                OR r.token LIKE :query
                OR r.resource_type LIKE :query
                OR r.resolver_key LIKE :query
                OR r.experience_key LIKE :query
                OR a.alias LIKE :query
                OR CAST(r.id AS CHAR) = :query_exact
                OR CAST(r.resource_id AS CHAR) = :query_exact
            )";
            $params[':query'] = '%' . trim($criteria->query) . '%';
            $params[':query_exact'] = trim($criteria->query);
        }

        if ($criteria->resolverKey !== null && trim($criteria->resolverKey) !== '') {
            $where[] = 'r.resolver_key = :resolver_key';
            $params[':resolver_key'] = trim($criteria->resolverKey);
        }

        if ($criteria->experienceKey !== null && trim($criteria->experienceKey) !== '') {
            $where[] = 'r.experience_key = :experience_key';
            $params[':experience_key'] = strtolower(trim($criteria->experienceKey));
        }

        if ($criteria->resourceType !== null && trim($criteria->resourceType) !== '') {
            $where[] = 'r.resource_type = :resource_type';
            $params[':resource_type'] = trim($criteria->resourceType);
        }

        if ($criteria->resourceId !== null) {
            $where[] = 'r.resource_id = :resource_id';
            $params[':resource_id'] = $criteria->resourceId;
        }

        if ($criteria->status !== null && trim($criteria->status) !== '') {
            $where[] = 'r.status = :status';
            $params[':status'] = trim($criteria->status);
        }

        $limit = max(1, min(500, $criteria->limit));

        $sql = '
            SELECT DISTINCT r.*
            FROM rex_reservations r
            LEFT JOIN rex_aliases a
              ON a.reservation_id = r.id
        ';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn(array $row): RexReservation => $this->rowToReservation($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function listAliases(int $reservationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM rex_aliases
              WHERE reservation_id = :reservation_id
              ORDER BY alias ASC, id ASC'
        );

        $stmt->execute([':reservation_id' => $reservationId]);

        return array_map(
            fn(array $row): RexAlias => $this->rowToAlias($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function addAlias(int $reservationId, string $alias): RexAlias
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rex_aliases (
                reservation_id,
                alias,
                created_at,
                updated_at
             ) VALUES (
                :reservation_id,
                :alias,
                NOW(),
                NOW()
             )"
        );

        $stmt->execute([
            ':reservation_id' => $reservationId,
            ':alias' => trim($alias),
        ]);

        $aliasId = (int)$this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM rex_aliases WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $aliasId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('REX alias was not found after insert.');
        }

        return $this->rowToAlias($row);
    }

    public function removeAlias(int $reservationId, string $alias): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rex_aliases
              WHERE reservation_id = :reservation_id
                AND alias = :alias'
        );

        $stmt->execute([
            ':reservation_id' => $reservationId,
            ':alias' => trim($alias),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function createLink(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
        int $sortOrder = 0,
    ): RexReservationLink {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rex_reservation_links (
                parent_reservation_id,
                child_reservation_id,
                relationship_key,
                sort_order,
                created_at,
                updated_at
             ) VALUES (
                :parent_reservation_id,
                :child_reservation_id,
                :relationship_key,
                :sort_order,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )"
        );

        $stmt->execute([
            ':parent_reservation_id' => $parentReservationId,
            ':child_reservation_id' => $childReservationId,
            ':relationship_key' => trim($relationshipKey),
            ':sort_order' => $sortOrder,
        ]);

        return $this->requireLink((int)$this->pdo->lastInsertId());
    }

    public function removeLink(
        int $parentReservationId,
        int $childReservationId,
        string $relationshipKey,
    ): bool {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rex_reservation_links
              WHERE parent_reservation_id = :parent_reservation_id
                AND child_reservation_id = :child_reservation_id
                AND relationship_key = :relationship_key'
        );

        $stmt->execute([
            ':parent_reservation_id' => $parentReservationId,
            ':child_reservation_id' => $childReservationId,
            ':relationship_key' => trim($relationshipKey),
        ]);

        return $stmt->rowCount() > 0;
    }


    /**
     * Set the lock state for one REX reservation and all descendant
     * reservations in its relationship tree.
     *
     * Allowed transitions:
     * - unlocked -> dispatched
     * - dispatched -> unlocked
     * - dispatched -> published
     * - published -> published
     *
     * Forbidden transitions:
     * - published -> unlocked
     * - published -> dispatched
     *
     * @return array{
     *   ok: bool,
     *   reason: ?string,
     *   message: string,
     *   affected_ids: int[]
     * }
     */
    /**
     * Set the lock state for one REX reservation and all descendant
     * reservations in its relationship tree.
     *
     * "published" is sticky:
     * - dispatch may temporarily lock previously-unpublished rows
     * - a failed dispatch may unlock only rows that are not already published
     * - a published row is never downgraded or unlocked by this method
     * - publishing promotes every row in the current tree to published
     *
     * This lets an already-published parent keep its permanent lock while
     * newly-added descendants participate safely in later dispatch attempts.
     *
     * @return array{
     *   ok: bool,
     *   reason: ?string,
     *   message: string,
     *   affected_ids: int[]
     * }
     */
    public function setREXLock(
        int $reservationId,
        bool $locked,
        ?string $reason = null,
    ): array {
        if ($reservationId <= 0) {
            return [
                'ok' => false,
                'reason' => 'invalid_reservation',
                'message' => 'Valid REX reservation ID required.',
                'affected_ids' => [],
            ];
        }

        $normalizedReason = $reason !== null
            ? strtolower(trim($reason))
            : null;

        if ($locked) {
            if (!in_array($normalizedReason, ['dispatched', 'published'], true)) {
                return [
                    'ok' => false,
                    'reason' => 'invalid_lock_reason',
                    'message' => 'Locked REX must use lock reason dispatched or published.',
                    'affected_ids' => [],
                ];
            }
        } else {
            $normalizedReason = null;
        }

        if ($this->findById($reservationId) === null) {
            return [
                'ok' => false,
                'reason' => 'not_found',
                'message' => "REX reservation {$reservationId} was not found.",
                'affected_ids' => [],
            ];
        }

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            /*
             * Gather the selected reservation and every descendant.
             * A visited map protects against accidental cycles.
             */
            $visited = [];
            $queue = [$reservationId];

            while ($queue !== []) {
                $currentId = array_shift($queue);

                if (
                    $currentId <= 0
                    || isset($visited[$currentId])
                ) {
                    continue;
                }

                $visited[$currentId] = true;

                $stmt = $this->pdo->prepare(
                    'SELECT child_reservation_id
                       FROM rex_reservation_links
                      WHERE parent_reservation_id = :parent_id
                      ORDER BY id ASC'
                );

                $stmt->execute([
                    ':parent_id' => $currentId,
                ]);

                foreach (
                    $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
                    as $childId
                ) {
                    $childId = (int)$childId;

                    if (
                        $childId > 0
                        && !isset($visited[$childId])
                    ) {
                        $queue[] = $childId;
                    }
                }
            }

            $ids = array_map(
                'intval',
                array_keys($visited)
            );

            $placeholders = [];
            $params = [];

            foreach ($ids as $index => $id) {
                $placeholder = ':rex_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $id;
            }

            $in = implode(', ', $placeholders);

            if ($locked && $normalizedReason === 'published') {
                /*
                 * Confirmed publication makes the entire CURRENT tree
                 * permanently published.
                 */
                $stmt = $this->pdo->prepare(
                    "UPDATE rex_reservations
                        SET locked = 1,
                            lock_reason = 'published',
                            updated_at = NOW()
                      WHERE id IN ({$in})"
                );

                $stmt->execute($params);

            } elseif ($locked && $normalizedReason === 'dispatched') {
                /*
                 * Start a dispatch attempt.
                 *
                 * Already-published rows remain published.
                 * Anything else becomes temporarily dispatched.
                 */
                $stmt = $this->pdo->prepare(
                    "UPDATE rex_reservations
                        SET locked = 1,
                            lock_reason =
                                CASE
                                    WHEN locked = 1
                                     AND lock_reason = 'published'
                                        THEN 'published'
                                    ELSE 'dispatched'
                                END,
                            updated_at = NOW()
                      WHERE id IN ({$in})"
                );

                $stmt->execute($params);

            } else {
                /*
                 * Confirmed dispatch failure.
                 *
                 * Published rows are permanent and remain untouched.
                 * Only non-published rows lose the temporary dispatch lock.
                 */
                $stmt = $this->pdo->prepare(
                    "UPDATE rex_reservations
                        SET locked =
                                CASE
                                    WHEN locked = 1
                                     AND lock_reason = 'published'
                                        THEN 1
                                    ELSE 0
                                END,
                            lock_reason =
                                CASE
                                    WHEN locked = 1
                                     AND lock_reason = 'published'
                                        THEN 'published'
                                    ELSE NULL
                                END,
                            updated_at = NOW()
                      WHERE id IN ({$in})"
                );

                $stmt->execute($params);
            }

            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->commit();
            }

            return [
                'ok' => true,
                'reason' => null,
                'message' =>
                    $locked
                        ? "REX tree lock reconciled as {$normalizedReason}."
                        : 'Temporary REX dispatch locks cleared; published locks preserved.',
                'affected_ids' => $ids,
            ];

        } catch (\Throwable $e) {
            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }


    /**
     * Delete one specific REX reservation.
     *
     * This deletes only the selected REX identity and its owned REX metadata:
     * - fallback references pointing to it are cleared
     * - parent/child relationship edges touching it are removed
     * - aliases owned by it are removed
     * - the reservation itself is removed
     *
     * Child reservations themselves are NOT deleted.
     *
     * A locked reservation is permanent from the application's point of view
     * and cannot be deleted here.
     *
     * @return array{
     *   ok: bool,
     *   reason: ?string,
     *   message: string,
     *   reservation_id: int
     * }
     */
    public function deleteREX(
        int $reservationId,
    ): array {
        if ($reservationId <= 0) {
            return [
                'ok' => false,
                'reason' => 'invalid_reservation',
                'message' => 'Valid REX reservation ID required.',
                'reservation_id' => $reservationId,
            ];
        }

        /*
         * Read the lock directly from the table so deletion protection does
         * not depend on the DTO having lock fields.
         */
        $stmt = $this->pdo->prepare(
            'SELECT id, locked, lock_reason
               FROM rex_reservations
              WHERE id = :id
              LIMIT 1'
        );

        $stmt->execute([
            ':id' => $reservationId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'ok' => false,
                'reason' => 'not_found',
                'message' => "REX reservation {$reservationId} was not found.",
                'reservation_id' => $reservationId,
            ];
        }

        $locked =
            (int)($row['locked'] ?? 0) === 1;

        $lockReason =
            isset($row['lock_reason'])
            && $row['lock_reason'] !== null
                ? trim((string)$row['lock_reason'])
                : null;

        if ($locked) {
            $suffix =
                $lockReason !== null
                && $lockReason !== ''
                    ? " ({$lockReason})"
                    : '';

            return [
                'ok' => false,
                'reason' => 'locked',
                'message' =>
                    "REX reservation {$reservationId} is locked{$suffix} and cannot be deleted.",
                'reservation_id' => $reservationId,
            ];
        }

        $ownsTransaction =
            !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            /*
             * Other reservations may use this reservation as fallback.
             * Clear those references before deleting the target.
             */
            $stmt = $this->pdo->prepare(
                "UPDATE rex_reservations
                    SET fallback_rex_id = NULL,
                        updated_at = NOW()
                  WHERE fallback_rex_id = :reservation_id"
            );

            $stmt->execute([
                ':reservation_id' => $reservationId,
            ]);

            /*
             * Remove topology edges in both directions.
             * The linked child/parent reservations remain intact.
             */
            $stmt = $this->pdo->prepare(
                'DELETE FROM rex_reservation_links
                  WHERE parent_reservation_id = :reservation_id
                     OR child_reservation_id = :reservation_id'
            );

            $stmt->execute([
                ':reservation_id' => $reservationId,
            ]);

            /*
             * Aliases belong to the reservation itself.
             */
            $stmt = $this->pdo->prepare(
                'DELETE FROM rex_aliases
                  WHERE reservation_id = :reservation_id'
            );

            $stmt->execute([
                ':reservation_id' => $reservationId,
            ]);

            /*
             * Finally remove the specific REX reservation.
             */
            $stmt = $this->pdo->prepare(
                'DELETE FROM rex_reservations
                  WHERE id = :reservation_id
                    AND locked = 0'
            );

            $stmt->execute([
                ':reservation_id' => $reservationId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "REX reservation {$reservationId} could not be deleted."
                );
            }

            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->commit();
            }

            return [
                'ok' => true,
                'reason' => null,
                'message' =>
                    "REX reservation {$reservationId} was deleted.",
                'reservation_id' => $reservationId,
            ];

        } catch (\Throwable $e) {
            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }


    public function findChildReservations(
        int $parentReservationId,
        ?string $relationshipKey = null,
    ): array {
        return array_map(
            static fn(RexReservationRelationship $relationship): RexReservation =>
                $relationship->reservation,
            $this->findChildRelationships($parentReservationId, $relationshipKey),
        );
    }

    public function findChildRelationships(
        int $parentReservationId,
        ?string $relationshipKey = null,
    ): array {
        $params = [
            ':parent_reservation_id' => $parentReservationId,
        ];

        $where = 'l.parent_reservation_id = :parent_reservation_id';

        if ($relationshipKey !== null && trim($relationshipKey) !== '') {
            $where .= ' AND l.relationship_key = :relationship_key';
            $params[':relationship_key'] = trim($relationshipKey);
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                l.id AS rex_link_id,
                l.relationship_key AS rex_link_relationship_key,
                l.sort_order AS rex_link_sort_order,
                l.created_at AS rex_link_created_at,
                l.updated_at AS rex_link_updated_at,
                r.*
               FROM rex_reservation_links l
               INNER JOIN rex_reservations r
                 ON r.id = l.child_reservation_id
              WHERE {$where}
              ORDER BY l.sort_order ASC, l.id ASC"
        );

        $stmt->execute($params);

        return array_map(
            fn(array $row): RexReservationRelationship => $this->rowToRelationship($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function findParentReservations(
        int $childReservationId,
        ?string $relationshipKey = null,
    ): array {
        return array_map(
            static fn(RexReservationRelationship $relationship): RexReservation =>
                $relationship->reservation,
            $this->findParentRelationships($childReservationId, $relationshipKey),
        );
    }

    public function findParentRelationships(
        int $childReservationId,
        ?string $relationshipKey = null,
    ): array {
        $params = [
            ':child_reservation_id' => $childReservationId,
        ];

        $where = 'l.child_reservation_id = :child_reservation_id';

        if ($relationshipKey !== null && trim($relationshipKey) !== '') {
            $where .= ' AND l.relationship_key = :relationship_key';
            $params[':relationship_key'] = trim($relationshipKey);
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                l.id AS rex_link_id,
                l.relationship_key AS rex_link_relationship_key,
                l.sort_order AS rex_link_sort_order,
                l.created_at AS rex_link_created_at,
                l.updated_at AS rex_link_updated_at,
                r.*
               FROM rex_reservation_links l
               INNER JOIN rex_reservations r
                 ON r.id = l.parent_reservation_id
              WHERE {$where}
              ORDER BY l.sort_order ASC, l.id ASC"
        );

        $stmt->execute($params);

        return array_map(
            fn(array $row): RexReservationRelationship => $this->rowToRelationship($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function updateDestination(
        RexUpdateDestinationRequest $request,
    ): RexReservation {
        $experienceKey = $this->resolveExperienceKey(
            $request->experienceKey,
            $request->context,
        );

        $stmt = $this->pdo->prepare(
            "UPDATE rex_reservations
                SET resolver_key = :resolver_key,
                    experience_key = :experience_key,
                    resource_type = :resource_type,
                    resource_id = :resource_id,
                    context_json = :context_json,
                    updated_at = NOW()
              WHERE id = :id"
        );

        $stmt->execute([
            ':id' => $request->reservationId,
            ':resolver_key' => trim($request->resolverKey),
            ':experience_key' => $experienceKey,
            ':resource_type' => trim($request->resourceType),
            ':resource_id' => $request->resourceId,
            ':context_json' => $this->encodeContext($request->context),
        ]);

        return $this->requireReservation($request->reservationId);
    }

    public function updateMetadata(
        RexUpdateMetadataRequest $request,
    ): RexReservation {
        $stmt = $this->pdo->prepare(
            "UPDATE rex_reservations
                SET label = :label,
                    updated_at = NOW()
              WHERE id = :id"
        );

        $stmt->execute([
            ':id' => $request->reservationId,
            ':label' => trim($request->label),
        ]);

        return $this->requireReservation($request->reservationId);
    }

    public function setFallbackRexId(
        int $reservationId,
        ?int $fallbackRexId,
    ): RexReservation {
        $stmt = $this->pdo->prepare(
            "UPDATE rex_reservations
                SET fallback_rex_id = :fallback_rex_id,
                    updated_at = NOW()
              WHERE id = :id"
        );

        $stmt->execute([
            ':id' => $reservationId,
            ':fallback_rex_id' => $fallbackRexId,
        ]);

        return $this->requireReservation($reservationId);
    }

    public function revoke(int $reservationId): RexReservation
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rex_reservations
                SET status = 'revoked',
                    revoked_at = COALESCE(revoked_at, NOW()),
                    updated_at = NOW()
              WHERE id = :id"
        );

        $stmt->execute([':id' => $reservationId]);

        return $this->requireReservation($reservationId);
    }

    public function reactivate(int $reservationId): RexReservation
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rex_reservations
                SET status = 'active',
                    revoked_at = NULL,
                    updated_at = NOW()
              WHERE id = :id"
        );

        $stmt->execute([':id' => $reservationId]);

        return $this->requireReservation($reservationId);
    }

    public function countActiveByResources(
        string $resourceType,
        array $resourceIds,
    ): array {
        $resourceType = trim($resourceType);

        $resourceIds = array_values(array_unique(array_filter(
            array_map('intval', $resourceIds),
            static fn(int $id): bool => $id > 0,
        )));

        if ($resourceType === '' || $resourceIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':resource_type' => $resourceType,
            ':status' => 'active',
        ];

        foreach ($resourceIds as $index => $resourceId) {
            $placeholder = ':resource_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $resourceId;
        }

        $sql = "
            SELECT
                resource_id,
                COUNT(*) AS rex_count
            FROM rex_reservations
            WHERE resource_type = :resource_type
              AND status = :status
              AND resource_id IN (" . implode(', ', $placeholders) . ")
            GROUP BY resource_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(int)$row['resource_id']] = (int)$row['rex_count'];
        }

        return $counts;
    }

    public function listResourceTypes(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT resource_type
               FROM rex_reservations
              WHERE resource_type IS NOT NULL
                AND TRIM(resource_type) <> ''
              ORDER BY resource_type ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function tokenExists(string $token): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rex_reservations WHERE token = :token LIMIT 1'
        );

        $stmt->execute([':token' => trim($token)]);

        return (bool)$stmt->fetchColumn();
    }

    public function findActiveByResourceIds(
        string $resolverKey,
        string $resourceType,
        array $resourceIds,
    ): array {
        $resolverKey = trim($resolverKey);
        $resourceType = trim($resourceType);

        $resourceIds = array_values(array_unique(array_filter(
            array_map('intval', $resourceIds),
            static fn(int $id): bool => $id > 0,
        )));

        if ($resolverKey === '' || $resourceType === '' || $resourceIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':resolver_key' => $resolverKey,
            ':resource_type' => $resourceType,
            ':status' => 'active',
        ];

        foreach ($resourceIds as $index => $resourceId) {
            $placeholder = ':resource_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $resourceId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM rex_reservations
              WHERE resolver_key = :resolver_key
                AND resource_type = :resource_type
                AND status = :status
                AND resource_id IN (' . implode(', ', $placeholders) . ')
              ORDER BY resource_id ASC, id ASC'
        );

        $stmt->execute($params);

        $grouped = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $reservation = $this->rowToReservation($row);
            $grouped[$reservation->resourceId] ??= [];
            $grouped[$reservation->resourceId][] = $reservation;
        }

        return $grouped;
    }

    public function findActiveByResourceIdsAndExperience(
        string $resolverKey,
        string $resourceType,
        array $resourceIds,
        string $experienceKey,
    ): array {
        $resolverKey = trim($resolverKey);
        $resourceType = trim($resourceType);
        $experienceKey = strtolower(trim($experienceKey));

        $resourceIds = array_values(array_unique(array_filter(
            array_map('intval', $resourceIds),
            static fn(int $id): bool => $id > 0,
        )));

        if (
            $resolverKey === ''
            || $resourceType === ''
            || $experienceKey === ''
            || $resourceIds === []
        ) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':resolver_key' => $resolverKey,
            ':resource_type' => $resourceType,
            ':status' => 'active',
            ':experience_key' => $experienceKey,
        ];

        foreach ($resourceIds as $index => $resourceId) {
            $placeholder = ':resource_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $resourceId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM rex_reservations
              WHERE resolver_key = :resolver_key
                AND resource_type = :resource_type
                AND status = :status
                AND experience_key = :experience_key
                AND resource_id IN (' . implode(', ', $placeholders) . ')
              ORDER BY resource_id ASC, id ASC'
        );

        $stmt->execute($params);

        $reservations = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $reservation = $this->rowToReservation($row);

            if (!isset($reservations[$reservation->resourceId])) {
                $reservations[$reservation->resourceId] = $reservation;
            }
        }

        return $reservations;
    }

    private function requireReservation(int $reservationId): RexReservation
    {
        $reservation = $this->findById($reservationId);

        if (!$reservation) {
            throw new RuntimeException('REX reservation was not found.');
        }

        return $reservation;
    }

    private function requireLink(int $linkId): RexReservationLink
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rex_reservation_links WHERE id = :id LIMIT 1'
        );

        $stmt->execute([':id' => $linkId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('REX reservation link was not found after insert.');
        }

        return $this->rowToLink($row);
    }

    private function rowToReservation(array $row): RexReservation
    {
        $context = $this->decodeContext($row['context_json'] ?? null);

        $experienceKey = $this->resolveExperienceKey(
            isset($row['experience_key']) ? (string)$row['experience_key'] : null,
            $context,
        );

        return new RexReservation(
            id: (int)$row['id'],
            token: (string)$row['token'],
            label: (string)$row['label'],
            adminNote: $row['admin_note'] !== null
                ? (string)$row['admin_note']
                : null,
            resolverKey: (string)$row['resolver_key'],
            resourceType: (string)$row['resource_type'],
            resourceId: (int)$row['resource_id'],
            context: $context,
            status: (string)$row['status'],
            revokedAt: $row['revoked_at'] !== null
                ? (string)$row['revoked_at']
                : null,
            createdAt: $row['created_at'] !== null
                ? (string)$row['created_at']
                : null,
            updatedAt: $row['updated_at'] !== null
                ? (string)$row['updated_at']
                : null,
            fallbackRexId: isset($row['fallback_rex_id']) && $row['fallback_rex_id'] !== null
                ? (int)$row['fallback_rex_id']
                : null,
            experienceKey: $experienceKey,
        );
    }

    private function rowToAlias(array $row): RexAlias
    {
        return new RexAlias(
            id: (int)$row['id'],
            reservationId: (int)$row['reservation_id'],
            alias: (string)$row['alias'],
            createdAt: $row['created_at'] !== null
                ? (string)$row['created_at']
                : null,
            updatedAt: $row['updated_at'] !== null
                ? (string)$row['updated_at']
                : null,
        );
    }

    private function rowToLink(array $row): RexReservationLink
    {
        return new RexReservationLink(
            id: (int)$row['id'],
            parentReservationId: (int)$row['parent_reservation_id'],
            childReservationId: (int)$row['child_reservation_id'],
            relationshipKey: (string)$row['relationship_key'],
            sortOrder: (int)$row['sort_order'],
            createdAt: $row['created_at'] !== null
                ? (string)$row['created_at']
                : null,
            updatedAt: $row['updated_at'] !== null
                ? (string)$row['updated_at']
                : null,
        );
    }

    private function rowToRelationship(array $row): RexReservationRelationship
    {
        return new RexReservationRelationship(
            linkId: (int)$row['rex_link_id'],
            relationshipKey: (string)$row['rex_link_relationship_key'],
            sortOrder: (int)$row['rex_link_sort_order'],
            reservation: $this->rowToReservation($row),
            createdAt: $row['rex_link_created_at'] !== null
                ? (string)$row['rex_link_created_at']
                : null,
            updatedAt: $row['rex_link_updated_at'] !== null
                ? (string)$row['rex_link_updated_at']
                : null,
        );
    }

    private function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $json = json_encode($context, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('REX context_json could not be encoded.');
        }

        return $json;
    }

    private function decodeContext(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * During migration, old callers may still place experience_key in context_json.
     * Prefer the first-class column/request value; fall back to legacy context only
     * so existing public REX behavior remains intact until callers/resolvers move.
     */
    private function resolveExperienceKey(
        ?string $experienceKey,
        array $context,
    ): ?string {
        $explicit = $this->nullableTrim($experienceKey);

        if ($explicit !== null) {
            return strtolower($explicit);
        }

        $legacy = $context['experience_key'] ?? null;

        if (!is_string($legacy)) {
            return null;
        }

        $legacy = $this->nullableTrim($legacy);

        return $legacy !== null
            ? strtolower($legacy)
            : null;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nowString(): string
    {
        return date('Y-m-d H:i:s');
    }
}
