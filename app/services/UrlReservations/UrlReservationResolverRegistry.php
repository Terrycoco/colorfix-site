<?php
declare(strict_types=1);

namespace App\Services\UrlReservations;

use InvalidArgumentException;

final class UrlReservationResolverRegistry
{
    /** @var array<string, UrlReservationResolver> */
    private array $resolvers = [];

    public function register(string $key, UrlReservationResolver $resolver): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('resolver key required');
        }
        $this->resolvers[$key] = $resolver;
    }

    public function get(string $key): UrlReservationResolver
    {
        $key = trim($key);
        if (!isset($this->resolvers[$key])) {
            throw new InvalidArgumentException('Unknown reservation resolver');
        }
        return $this->resolvers[$key];
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->resolvers);
    }
}
