<?php
declare(strict_types=1);

namespace App\SAS\Services;

use App\SAS\Repos\PdoRexPlaylistRepository;
use App\PLAYLISTS\Repos\PdoPlayerExperienceRepository;
use PDO;

final class RexPlaylistSearchService
{
    private PdoRexPlaylistRepository $repo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new PdoRexPlaylistRepository($pdo);
    }

    public function listRexPlaylists(): array
    {
        // Load the existing Player Experience configuration once, independently
        // of reservations. Listing/searching never creates a REX.
        $experiences = [];
        foreach ((new PdoPlayerExperienceRepository($this->pdo))->listAll() as $row) {
            if ((int)$row['is_active'] !== 1) continue;
            $key = strtolower(trim((string)$row['experience_key']));
            $experiences[$key] = [
                'rex_id' => null, 'token' => null,
                'label' => (string)$row['name'], 'experience_key' => $key,
            ];
        }
        $playlists = [];
        foreach ($this->repo->listRexPlaylists() as $row) {
            $id = (int)$row['playlist_id'];
            if (!isset($playlists[$id])) {
                $playlists[$id] = [
                    'playlist_id' => $id,
                    'title' => (string)$row['title'],
                    'type' => (string)($row['type'] ?? ''),
                    'experiences' => $experiences,
                ];
            }
            if (empty($row['rex_id'])) continue;
            $context = json_decode((string)($row['context_json'] ?? ''), true);
            $context = is_array($context) ? $context : [];
            $experience = trim((string)($row['experience_key'] ?? ''));
            if ($experience === '') {
                $experience = trim((string)($context['experience_key'] ?? ''));
            }
            $key = strtolower($experience);
            if ($key === '' || !empty($playlists[$id]['experiences'][$key]['token'])) continue;
            $playlists[$id]['experiences'][$key] = [
                'rex_id' => (int)$row['rex_id'],
                'token' => (string)$row['token'],
                'label' => $experiences[$key]['label'] ?? (string)$row['rex_label'],
                'experience_key' => strtolower($experience),
            ];
        }
        foreach ($playlists as &$playlist) {
            $playlist['experiences'] = array_values($playlist['experiences']);
        }
        unset($playlist);
        return array_values($playlists);
    }
}
