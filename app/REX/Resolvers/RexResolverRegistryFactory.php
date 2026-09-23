<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use PDO;

final class RexResolverRegistryFactory
{
    public static function build(PDO $pdo): RexResolverRegistry
    {
        $registry = new RexResolverRegistry();

        $registry->register(
            'playlist_experience',
            new PlaylistExperienceResolver($pdo)
        );

        $registry->register(
            'playlist_thumbs',
            new PlaylistThumbsResolver($pdo)
        );

        $registry->register(
            'viewer',
            new ViewerResolver($pdo)
        );

        $registry->register(
            'route',
            new RouteResolver()
        );

$registry->register(
    'document',
    new DocumentResolver($pdo)
);

        return $registry;
    }
}
