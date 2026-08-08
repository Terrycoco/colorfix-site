<?php
declare(strict_types=1);

namespace App\Services\UrlReservations;

interface UrlReservationResolver
{
    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resolve(array $reservation, array $params): array;
}
