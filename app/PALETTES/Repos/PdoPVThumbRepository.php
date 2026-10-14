<?php
declare(strict_types=1);

namespace App\PALETTES\Repos;

use PDO;

/** Lightweight thumbnail projection; never loads full PVs or palettes. */
final class PdoPVThumbRepository
{
    public function __construct(private PDO $pdo) {}

    public function parentByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.token, r.resource_id AS playlist_id,
                    r.experience_key, p.title
               FROM rex_reservations r
               JOIN playlists p ON p.playlist_id = r.resource_id
              WHERE r.token = :token
                AND r.resolver_key = 'playlist_experience'
                AND r.resource_type = 'playlist'
                AND r.status = 'active'
                AND r.revoked_at IS NULL
              LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function children(int $parentId, string $experience): array
    {
        // One SQL request for all cards; the correlated lookup selects a single
        // FULL photo (or first non-BEFORE photo) using the existing PV-photo link.
        $sql = "SELECT child.id AS rex_reservation_id,
                       child.token AS rex_token,
                       pv.palette_viewer_id,
                       pv.saved_palette_id,
                       pv.format,
                       COALESCE(NULLIF(TRIM(pv.title), ''),
                                NULLIF(TRIM(sp.display_title), ''),
                                NULLIF(TRIM(sp.nickname), ''), 'ColorFix Palette') AS title,
                       COALESCE(NULLIF(pl.rel_path, ''), NULLIF(chosen.rel_path, ''), '') AS image_url,
                       COALESCE(pl.updated_at, chosen.updated_at) AS image_updated_at
                  FROM rex_reservation_links link_row
                  JOIN rex_reservations child
                    ON child.id = link_row.child_reservation_id
                  JOIN palette_viewers pv
                    ON pv.palette_viewer_id = child.resource_id AND pv.is_active = 1
             LEFT JOIN saved_palettes sp ON sp.id = pv.saved_palette_id
             LEFT JOIN palette_viewer_photos chosen ON chosen.palette_viewer_photo_id = (
                    SELECT pvp.palette_viewer_photo_id
                      FROM palette_viewer_photos pvp
                     WHERE pvp.palette_viewer_id = pv.palette_viewer_id
                       AND LOWER(COALESCE(pvp.photo_type, '')) <> 'before'
                  ORDER BY CASE WHEN LOWER(COALESCE(pvp.photo_type, '')) IN ('full', 'main') THEN 0 ELSE 1 END,
                           pvp.order_index ASC, pvp.palette_viewer_photo_id ASC
                     LIMIT 1
                )
             LEFT JOIN photo_library pl ON pl.photo_library_id = chosen.photo_library_id
                 WHERE link_row.parent_reservation_id = :parent_id
                   AND link_row.relationship_key = 'viewer'
                   AND child.resolver_key = 'viewer'
                   AND child.resource_type = 'palette_viewer'
                   AND child.status = 'active'
                   AND child.revoked_at IS NULL
                   AND LOWER(COALESCE(child.experience_key, '')) IN (:experience, '')
                   AND LOWER(COALESCE(pv.format, '')) IN (:experience_format, :legacy_format)
              ORDER BY link_row.sort_order, link_row.id, child.id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':parent_id' => $parentId,
            ':experience' => $experience,
            ':experience_format' => $experience,
            ':legacy_format' => $experience === 'public' ? 'full_palette' : $experience,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
