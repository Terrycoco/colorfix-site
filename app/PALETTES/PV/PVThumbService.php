<?php
declare(strict_types=1);

namespace App\PALETTES\PV;

use App\PALETTES\Repos\PdoPVThumbRepository;
use PDO;
use RuntimeException;

final class PVThumbService
{
    public function __construct(private PDO $pdo) {}

    public function forParentToken(string $token): array
    {
        $repo = new PdoPVThumbRepository($this->pdo);
        $parent = $repo->parentByToken($token);
        if (!$parent) {
            throw new RuntimeException('Active playlist REX not found.');
        }
        $experience = strtolower(trim((string)($parent['experience_key'] ?? '')));
        if ($experience === '') {
            throw new RuntimeException('Playlist REX has no experience.');
        }
        $items = [];
        $seen = [];
        foreach ($repo->children((int)$parent['id'], $experience) as $row) {
            $pvId = (int)$row['palette_viewer_id'];
            $paletteId = (int)($row['saved_palette_id'] ?? 0);
            $dedupe = $paletteId > 0 ? 'palette:' . $paletteId : 'pv:' . $pvId;
            if (isset($seen[$dedupe])) continue;
            $seen[$dedupe] = true;
            $url = trim((string)($row['image_url'] ?? ''));
            $date = (string)($row['image_updated_at'] ?? '');
            $stamp = $date !== '' ? strtotime($date) : false;
            if ($url !== '' && $stamp && !str_contains($url, '?v=') && !str_contains($url, '&v=')) {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $stamp;
            }
            $items[] = [
                'palette_viewer_id' => $pvId,
                'saved_palette_id' => $paletteId ?: null,
                'title' => (string)$row['title'],
                'image_url' => $url,
                'rex_url' => '/t/' . rawurlencode((string)$row['rex_token']),
            ];
        }
        return [
            'playlist_id' => (int)$parent['playlist_id'],
            'title' => (string)$parent['title'],
            'experience_key' => $experience,
            'total' => count($items),
            'thumbs' => $items,
        ];
    }
}
