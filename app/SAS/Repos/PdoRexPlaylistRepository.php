<?php
declare(strict_types=1);

namespace App\SAS\Repos;

use PDO;

/** Read-only SAS catalogue of all saved playlists and their existing links. */
final class PdoRexPlaylistRepository
{
    public function __construct(private PDO $pdo) {}

    public function listRexPlaylists(): array
    {
        $sql = <<<'SQL'
            SELECT p.playlist_id, p.title, p.type, p.project_id,
                   r.id AS rex_id, r.token, r.label AS rex_label,
                   r.experience_key, r.context_json
              FROM playlists p
              LEFT JOIN rex_reservations r
                ON r.resolver_key = 'playlist_experience'
               AND r.resource_type = 'playlist'
               AND r.resource_id = p.playlist_id
               AND r.status = 'active'
               AND r.revoked_at IS NULL
               AND TRIM(r.token) <> ''
             ORDER BY p.title ASC, p.playlist_id ASC, r.id ASC
        SQL;
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
