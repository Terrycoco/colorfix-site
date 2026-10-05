<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\DTO\RexReservation;
use App\REX\Repos\PdoRexReservationRepository;
use PDO;

final class RexViewerPlaylistLinker
{
    public function __construct(private PDO $pdo) {}

    public function link(RexReservation $reservation): void
    {
        if ($reservation->resolverKey !== 'viewer' || $reservation->resourceType !== 'palette_viewer'
            || $reservation->status !== 'active' || $reservation->revokedAt !== null) { return; }

        $stmt = $this->pdo->prepare('SELECT saved_palette_id, project_id, format, is_active FROM palette_viewers WHERE palette_viewer_id = ?');
        $stmt->execute([$reservation->resourceId]);
        $viewer = $stmt->fetch(PDO::FETCH_ASSOC);
        $format = strtolower(trim((string)($viewer['format'] ?? '')));
        // Painter is a separately shared, project-wide destination.
        if (!$viewer || !$viewer['is_active'] || !in_array($format, ['public', 'concept', 'client'], true)) { return; }

        $stmt = $this->pdo->prepare(
            "SELECT rr.id, COALESCE(MIN(pi.order_index), MIN(pp.order_index), 0) AS sort_order
             FROM rex_reservations rr
             JOIN playlists pl ON pl.playlist_id = rr.resource_id
             LEFT JOIN playlist_items pi ON pi.playlist_id = pl.playlist_id AND pi.saved_palette_id = :item_palette
             LEFT JOIN project_palettes pp ON pp.project_id = pl.project_id AND pp.saved_palette_id = :project_palette
             WHERE rr.resolver_key = 'playlist_experience' AND rr.resource_type = 'playlist'
               AND rr.status = 'active' AND rr.revoked_at IS NULL AND rr.experience_key = :format
               AND (pi.saved_palette_id IS NOT NULL OR pp.saved_palette_id IS NOT NULL
                    OR (pl.project_id = :project_id AND pl.project_id > 0))
             GROUP BY rr.id ORDER BY rr.id"
        );
        $stmt->execute([
            ':item_palette' => (int)($viewer['saved_palette_id'] ?? 0),
            ':project_palette' => (int)($viewer['saved_palette_id'] ?? 0),
            ':project_id' => (int)($viewer['project_id'] ?? 0), ':format' => $format,
        ]);
        $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $repo = new PdoRexReservationRepository($this->pdo);
        $relationships = new RexReservationRelationships($repo);
        $existing = array_fill_keys(array_map(
            static fn(RexReservation $parent): int => $parent->id,
            $repo->findParentReservations($reservation->id, 'viewer')
        ), true);
        foreach ($parents as $parent) {
            $id = (int)$parent['id'];
            if (!isset($existing[$id])) {
                $relationships->create($id, $reservation->id, 'viewer', max(0, (int)$parent['sort_order']));
                $existing[$id] = true;
            }
        }
    }
}
