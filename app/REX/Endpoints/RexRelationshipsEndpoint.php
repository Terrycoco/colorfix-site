<?php
declare(strict_types=1);

namespace App\REX\Endpoints;
use App\REX\DTO\RexReservation;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexRelationshipsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                self::respond([
                    'ok' => false,
                    'error' => 'GET only',
                ], 405);
            }

            $resourceType = trim((string)($_GET['resource_type'] ?? ''));
            $resourceId = (int)($_GET['resource_id'] ?? 0);

            if ($resourceType === '') {
                throw new InvalidArgumentException('Resource type is required.');
            }

            if ($resourceId <= 0) {
                throw new InvalidArgumentException('Valid resource ID is required.');
            }

            if ($resourceType !== 'playlist') {
                throw new InvalidArgumentException(
                    'REX Relationships currently supports playlist objects only.'
                );
            }

            $repo = new PdoRexReservationRepository($pdo);

            $registry = RexResolverRegistryFactory::build($pdo);

            $rexResolver = new RexResolver(
                $repo,
                $registry
            );

            $reservations = $repo->findByResource(
                'playlist',
                $resourceId,
                100
            );

            $publicParents = array_values(array_filter(
                $reservations,
                static fn(RexReservation $reservation): bool =>
                    strtolower(trim($reservation->resolverKey)) === 'playlist_experience'
                    && strtolower(trim($reservation->status)) === 'active'
                    && strtolower(trim(
                        (string)($reservation->context['experience_key'] ?? '')
                    )) === 'public'
            ));

            usort(
                $publicParents,
                static fn(RexReservation $a, RexReservation $b): int =>
                    $a->id <=> $b->id
            );

            $reservation = $publicParents[0] ?? null;

            if (!$reservation) {
                self::respond([
                    'ok' => true,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'has_rex' => false,
                    'reservation' => null,
                    'parents' => [],
                    'children' => [],
                    'fallback' => null,
                ]);
            }

            $toPayload = static function (
                RexReservation $item
            ) use ($rexResolver): array {
                return self::reservationPayload(
                    $item,
                    $rexResolver
                );
            };

            $parents = array_map(
                static function ($relationship) use ($toPayload): array {
                    return [
                        'link_id' => $relationship->linkId,
                        'relationship_key' => $relationship->relationshipKey,
                        'sort_order' => $relationship->sortOrder,
                        'reservation' => $toPayload($relationship->reservation),
                    ];
                },
                $repo->findParentRelationships($reservation->id)
            );

            $children = array_map(
                static function ($relationship) use ($toPayload): array {
                    return [
                        'link_id' => $relationship->linkId,
                        'relationship_key' => $relationship->relationshipKey,
                        'sort_order' => $relationship->sortOrder,
                        'reservation' => $toPayload($relationship->reservation),
                    ];
                },
                $repo->findChildRelationships($reservation->id)
            );

            $fallback = null;

            if ($reservation->fallbackRexId !== null) {
                $fallbackReservation = $repo->findById(
                    $reservation->fallbackRexId
                );

                if ($fallbackReservation) {
                    $fallback = $toPayload($fallbackReservation);
                }
            }

            self::respond([
                'ok' => true,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'has_rex' => true,
                'reservation' => $toPayload($reservation),
                'parents' => $parents,
                'children' => $children,
                'fallback' => $fallback,
            ]);

        } catch (InvalidArgumentException $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private static function reservationPayload(
        RexReservation $reservation,
        RexResolver $rexResolver,
    ): array {
        $descriptor = null;

        try {
            $described = $rexResolver->describeReservation($reservation);

            $descriptor = [
                'title' => $described->title,
                'fields' => $described->fields,
            ];
        } catch (Throwable $e) {
            $descriptor = [
                'title' => $reservation->label,
                'fields' => [],
                'error' => $e->getMessage(),
            ];
        }

        return [
            'id' => $reservation->id,
            'token' => $reservation->token,
            'label' => $reservation->label,
            'admin_note' => $reservation->adminNote,
            'resolver_key' => $reservation->resolverKey,
            'resource_type' => $reservation->resourceType,
            'resource_id' => $reservation->resourceId,
            'context' => $reservation->context,
            'status' => $reservation->status,
            'fallback_rex_id' => $reservation->fallbackRexId,
            'revoked_at' => $reservation->revokedAt,
            'created_at' => $reservation->createdAt,
            'updated_at' => $reservation->updatedAt,
            'descriptor' => $descriptor,
        ];
    }

    private static function respond(
        array $payload,
        int $status = 200,
    ): never {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}
