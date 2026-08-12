<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use App\REX\Contracts\RexResolverInterface;
use InvalidArgumentException;

final class RexResolverRegistry
{
    /** @var array<string, RexResolverInterface> */
    private array $resolvers = [];

    public function register(string $resolverKey, RexResolverInterface $resolver): void
    {
        $resolverKey = trim($resolverKey);
        if ($resolverKey === '') {
            throw new InvalidArgumentException('REX resolver key is required.');
        }

        $this->resolvers[$resolverKey] = $resolver;
    }

    public function get(string $resolverKey): RexResolverInterface
    {
        $resolverKey = trim($resolverKey);
        if (!isset($this->resolvers[$resolverKey])) {
            throw new InvalidArgumentException("No REX resolver registered for '{$resolverKey}'.");
        }

        return $this->resolvers[$resolverKey];
    }
}
