<?php
declare(strict_types=1);

use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationRelationship;
use App\REX\Services\RexReservationRelationships;

function rex_admin_optional_string(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : null;
}

function rex_admin_required_string(mixed $value, string $label): string
{
    $normalized = rex_admin_optional_string($value);
    if ($normalized === null) {
        throw new InvalidArgumentException("{$label} is required.");
    }

    return $normalized;
}

function rex_admin_positive_int(mixed $value, string $label): int
{
    $id = (int)($value ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException("{$label} must be a positive integer.");
    }

    return $id;
}

function rex_admin_context(mixed $value): array
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }

    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('Context must be a JSON object.');
    }

    return $value;
}

function rex_admin_json_input(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Invalid JSON.');
    }

    return $data;
}

function rex_admin_reservation_payload(RexReservation $reservation): array
{
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

function rex_admin_reservations_payload(array $reservations): array
{
    return array_map(
        static fn(RexReservation $reservation): array => rex_admin_reservation_payload($reservation),
        $reservations
    );
}

function rex_admin_nonnegative_int(mixed $value, string $label): int
{
    $number = (int)($value ?? 0);
    if ($number < 0) {
        throw new InvalidArgumentException("{$label} must be zero or greater.");
    }

    return $number;
}

function rex_admin_relationship_payload(RexReservationRelationship $relationship): array
{
    return [
        'relationship_key' => $relationship->relationshipKey,
        'sort_order' => $relationship->sortOrder,
        'reservation' => rex_admin_reservation_payload($relationship->reservation),
        'public_url' => '/t/' . $relationship->reservation->token,
    ];
}

function rex_admin_relationships_payload(array $relationships): array
{
    return array_map(
        static fn(RexReservationRelationship $relationship): array => rex_admin_relationship_payload($relationship),
        $relationships
    );
}

function rex_admin_reservation_relationships_payload(
    RexReservationRelationships $relationships,
    RexReservation $reservation,
): array {
    return [
        'parents' => rex_admin_relationships_payload(
            $relationships->parentRelationships($reservation->id)
        ),
        'children' => rex_admin_relationships_payload(
            $relationships->childRelationships($reservation->id)
        ),
    ];
}
