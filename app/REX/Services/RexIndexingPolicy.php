<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionResult;
use PDO;

/** Shared policy for the public entry response and sitemap discovery. */
final class RexIndexingPolicy
{
    public static function allows(PDO $pdo, RexResolutionResult $result): bool
    {
        $plan = $result->destination['playback_plan'] ?? [];
        if ($result->behavior !== RexResolutionBehavior::RENDER
            || $result->resolverKey !== 'playlist_experience'
            || $result->resourceType !== 'playlist'
            || !in_array($plan['experience_key'] ?? '', ['public', 'showcase'], true)
        ) {
            return false;
        }

        // Read stored fields, without the repository DTO/UI's permissive defaults.
        // The resolved resource ID also handles fallback destinations correctly.
        $stmt = $pdo->prepare(
            'SELECT playlist_id FROM playlists WHERE playlist_id = :playlist_id '
            . 'AND is_active = 1 AND is_public = 1 AND indexable = 1 LIMIT 1'
        );
        $stmt->execute(['playlist_id' => $result->resourceId]);
        return $stmt->fetchColumn() !== false;
    }
}
