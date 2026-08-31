<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexReservationsListEndpoint
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

            $repo = new PdoRexReservationRepository($pdo);
            $registry = RexResolverRegistryFactory::build($pdo);
            $rexResolver = new RexResolver($repo, $registry);

            $ids = self::requestedIds($_GET['ids'] ?? null);

            if ($ids !== []) {
                $items = [];

                foreach ($ids as $id) {
                    $reservation = $repo->findById($id);

                    if ($reservation !== null) {
                        $items[] = $reservation;
                    }
                }
            } else {
                $requestedType = self::requiredString(
                    $_GET['resource_type'] ?? null,
                    'Resource type'
                );

                /*
                 * The Reservations admin groups public REX experiences by the
                 * kind of thing they represent. Thumbs is a first-class REX
                 * experience even though its stored resource_type is playlist.
                 */
                $storageResourceType = $requestedType === 'thumbs'
                    ? 'playlist'
                    : $requestedType;

                $resourceId = isset($_GET['resource_id'])
                    && $_GET['resource_id'] !== ''
                        ? self::positiveInt(
                            $_GET['resource_id'],
                            'Resource ID'
                        )
                        : null;

                $items = $repo->search(
                    new RexReservationSearchCriteria(
                        resourceType: $storageResourceType,
                        resourceId: $resourceId,
                        limit: 500,
                    )
                );

                if ($requestedType === 'thumbs') {
                    $items = array_values(array_filter(
                        $items,
                        static fn(RexReservation $reservation): bool =>
                            strtolower(trim($reservation->resolverKey))
                                === 'playlist_thumbs'
                    ));
                } elseif ($requestedType === 'playlist') {
                    $items = array_values(array_filter(
                        $items,
                        static fn(RexReservation $reservation): bool =>
                            strtolower(trim($reservation->resolverKey))
                                === 'playlist_experience'
                    ));
                }
            }

            $payload = array_map(
                static function (
                    RexReservation $reservation
                ) use ($rexResolver): array {
                    $item = self::reservationPayload($reservation);

                    try {
                        $descriptor = $rexResolver->describeReservation(
                            $reservation
                        );

                        $item['descriptor'] = [
                            'title' => $descriptor->title,
                            'fields' => $descriptor->fields,
                        ];
                    } catch (Throwable $e) {
                        $item['descriptor'] = [
                            'title' => $reservation->label,
                            'fields' => [],
                            'error' => $e->getMessage(),
                        ];
                    }

                    return $item;
                },
                $items
            );

            $resourceTypes = $repo->listResourceTypes();

            if (!in_array('page', $resourceTypes, true)) {
                $resourceTypes[] = 'page';
            }

            if (
                in_array('playlist', $resourceTypes, true)
                && !in_array('thumbs', $resourceTypes, true)
            ) {
                $resourceTypes[] = 'thumbs';
            }

            sort($resourceTypes);

            self::respond([
                'ok' => true,
                'resource_types' => $resourceTypes,
                'items' => $payload,
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

    /**
     * Preserve the existing admin list payload contract.
     */
    private static function reservationPayload(
        RexReservation $reservation
    ): array {
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
            'revoked_at' => $reservation->revokedAt,
            'created_at' => $reservation->createdAt,
            'updated_at' => $reservation->updatedAt,
            'public_url' => '/t/' . $reservation->token,
        ];
    }

    /**
     * @return int[]
     */
    private static function requestedIds(mixed $value): array
    {
        $raw = trim((string)($value ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                'intval',
                explode(',', $raw)
            ),
            static fn(int $id): bool => $id > 0
        )));
    }

    private static function requiredString(
        mixed $value,
        string $label
    ): string {
        $text = trim((string)($value ?? ''));

        if ($text === '') {
            throw new InvalidArgumentException(
                "{$label} is required."
            );
        }

        return $text;
    }

    private static function positiveInt(
        mixed $value,
        string $label
    ): int {
        $number = (int)($value ?? 0);

        if ($number <= 0) {
            throw new InvalidArgumentException(
                "{$label} must be greater than zero."
            );
        }

        return $number;
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
