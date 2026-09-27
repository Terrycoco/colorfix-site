<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexCreateEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                self::respond([
                    'ok' => false,
                    'error' => 'POST only',
                ], 405);
                return;
            }

            $data = self::jsonInput();
            $repo = new PdoRexReservationRepository($pdo);

            $context = self::context(
                $data['context'] ?? []
            );

            /*
             * experience_key is first-class reservation data.
             *
             * Compatibility: accept context.experience_key from older
             * admin callers, then remove it from context so new
             * reservations do not store the duplicate value.
             */
            $experienceKey = self::normalizedExperienceKey(
                self::optionalString(
                    $data['experience_key']
                        ?? ($context['experience_key'] ?? null)
                )
            );

            unset($context['experience_key']);

            $request = new RexCreateReservationRequest(
                label: self::requiredString(
                    $data['label'] ?? null,
                    'Label'
                ),
                resolverKey: self::requiredString(
                    $data['resolver_key'] ?? null,
                    'Resolver key'
                ),
                resourceType: self::requiredString(
                    $data['resource_type'] ?? null,
                    'Resource type'
                ),
                resourceId: self::positiveInt(
                    $data['resource_id'] ?? null,
                    'Resource ID'
                ),
                adminNote: self::optionalString(
                    $data['admin_note'] ?? null
                ),
                context: $context,
                experienceKey: $experienceKey,
            );

            if (!empty($data['reuse_existing'])) {
                $reservation = self::findReusableReservation(
                    $repo,
                    $request
                );

                if ($reservation) {
                    self::respond([
                        'ok' => true,
                        'item' => self::reservationPayload($reservation),
                        'reused' => true,
                    ]);
                    return;
                }
            }

            $reservation = (new RexReserver(
                $repo,
                new RexTokenGenerator()
            ))->reserve($request);

            self::respond([
                'ok' => true,
                'item' => self::reservationPayload($reservation),
                'reused' => false,
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

    private static function findReusableReservation(
        PdoRexReservationRepository $repo,
        RexCreateReservationRequest $request
    ): ?RexReservation {
        $matches = $repo->search(
            new RexReservationSearchCriteria(
                resolverKey: $request->resolverKey,
                resourceType: $request->resourceType,
                resourceId: $request->resourceId,
                status: RexReserver::STATUS_ACTIVE,
                limit: 500,
                experienceKey: $request->experienceKey,
            )
        );

        $requestContext = self::normalizedContext(
            $request->context
        );

        $requestExperienceKey = self::normalizedExperienceKey(
            $request->experienceKey
        );

        $reusable = [];

        foreach ($matches as $reservation) {
            if (
                self::normalizedExperienceKey(
                    $reservation->experienceKey
                ) !== $requestExperienceKey
            ) {
                continue;
            }

            if (
                self::normalizedContext(
                    $reservation->context
                ) !== $requestContext
            ) {
                continue;
            }

            $reusable[] = $reservation;
        }

        usort(
            $reusable,
            static fn(
                RexReservation $a,
                RexReservation $b
            ): int => $a->id <=> $b->id
        );

        return $reusable[0] ?? null;
    }

    private static function normalizedContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::normalizedContext($value);
            }
        }

        ksort($context);

        return $context;
    }

    private static function normalizedExperienceKey(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== ''
            ? $value
            : null;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized !== ''
            ? $normalized
            : null;
    }

    private static function requiredString(
        mixed $value,
        string $label
    ): string {
        $normalized = self::optionalString($value);

        if ($normalized === null) {
            throw new InvalidArgumentException(
                "{$label} is required."
            );
        }

        return $normalized;
    }

    private static function positiveInt(
        mixed $value,
        string $label
    ): int {
        $id = (int)($value ?? 0);

        if ($id <= 0) {
            throw new InvalidArgumentException(
                "{$label} must be a positive integer."
            );
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException(
                'Context must be a JSON object.'
            );
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonInput(): array
    {
        $data = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        if (!is_array($data)) {
            throw new InvalidArgumentException(
                'Invalid JSON.'
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
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
     * @param array<string, mixed> $payload
     */
    private static function respond(
        array $payload,
        int $status = 200
    ): void {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
