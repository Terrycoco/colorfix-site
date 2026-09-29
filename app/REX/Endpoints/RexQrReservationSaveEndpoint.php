<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\DTO\RexUpdateDestinationRequest;
use App\REX\DTO\RexUpdateMetadataRequest;
use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexQrReservationSaveEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                self::respond(['ok' => false, 'error' => 'POST only'], 405);
            }

            $data = self::jsonInput();
            $reservationId = self::positiveInt(
                $data['reservation_id'] ?? null,
                'Reservation ID'
            );

            $repo = new PdoRexReservationRepository($pdo);
            $existing = $repo->findById($reservationId);

            if ($existing === null) {
                throw new InvalidArgumentException('REX reservation was not found.');
            }

            $qrKey = self::requiredString($data['qr_key'] ?? null, 'QR key');
            $label = self::requiredString($data['label'] ?? null, 'Label');
            $resolverKey = self::requiredString($data['resolver_key'] ?? null, 'Resolver key');
            $resourceType = self::requiredString($data['resource_type'] ?? null, 'Resource type');
            $resourceId = self::positiveInt($data['resource_id'] ?? null, 'Resource ID');
            $context = self::context($data['context'] ?? []);
            $experienceKey = self::optionalString($data['experience_key'] ?? null);

            $pdo->beginTransaction();

            try {
                $repo->updateMetadata(new RexUpdateMetadataRequest(
                    reservationId: $reservationId,
                    label: $label,
                ));

                $repo->setQrKey($reservationId, $qrKey);

                $reservation = $repo->updateDestination(new RexUpdateDestinationRequest(
                    reservationId: $reservationId,
                    resolverKey: $resolverKey,
                    resourceType: $resourceType,
                    resourceId: $resourceId,
                    context: $context,
                    experienceKey: $experienceKey,
                ));

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            self::respond([
                'ok' => true,
                'item' => self::payload($reservation),
            ]);
        } catch (InvalidArgumentException $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function payload(object $reservation): array
    {
        return [
            'id' => $reservation->id,
            'token' => $reservation->token,
            'label' => $reservation->label,
            'admin_note' => $reservation->adminNote,
            'qr_key' => $reservation->qrKey,
            'resolver_key' => $reservation->resolverKey,
            'experience_key' => $reservation->experienceKey,
            'resource_type' => $reservation->resourceType,
            'resource_id' => $reservation->resourceId,
            'context' => $reservation->context,
            'status' => $reservation->status,
            'public_url' => '/t/' . $reservation->token,
        ];
    }

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw !== false ? $raw : '', true);

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON body.');
        }

        return $data;
    }

    private static function context(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Context must be an object.');
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function requiredString(mixed $value, string $label): string
    {
        $value = self::optionalString($value);

        if ($value === null) {
            throw new InvalidArgumentException("{$label} is required.");
        }

        return $value;
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        $value = (int)($value ?? 0);

        if ($value <= 0) {
            throw new InvalidArgumentException("{$label} must be greater than zero.");
        }

        return $value;
    }

    private static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
