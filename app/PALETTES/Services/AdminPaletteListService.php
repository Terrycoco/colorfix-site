<?php
declare(strict_types=1);

namespace App\PALETTES\Services;

use App\PALETTES\Repos\PdoPVRepository;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use PDO;

final class AdminPaletteListService
{
    private PdoSavedPaletteRepository $palettes;
    private PdoPVRepository $pvs;

    public function __construct(PDO $pdo)
    {
        $this->palettes = new PdoSavedPaletteRepository($pdo);
        $this->pvs = new PdoPVRepository($pdo);
    }

    /**
     * Lightweight inventory payload for the Admin palette master list.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(int $limit = 500): array
    {
        $rows = $this->palettes->listPalettes(
            [],
            max(1, min(1000, $limit)),
            0
        );

        $items = [];

        foreach ($rows as $palette) {
            $savedPaletteId = (int)($palette['id'] ?? 0);

            if ($savedPaletteId <= 0) {
                continue;
            }

            $members = $this->palettes->getMembersForPalette(
                $savedPaletteId
            );

            $pv = $this->pvs->findActivePublicBySavedPaletteId(
                $savedPaletteId
            );

            $thumbnail = '';

            if ($pv && is_array($pv['photos'] ?? null)) {
                $thumbnail = $this->pickThumbnailUrl($pv['photos']);
            }

            $items[] = [
                'id' => $savedPaletteId,
                'nickname' => $palette['nickname'] ?? null,
                'display_title' => $palette['display_title'] ?? null,
                'palette_type' => $palette['palette_type'] ?? null,
                'is_public' => (int)($palette['is_public'] ?? 0),
                'updated_at' => $palette['updated_at'] ?? null,
                'created_at' => $palette['created_at'] ?? null,
                'members' => $members,
                'thumbnail_url' => $thumbnail,
                'palette_viewer_id' => $pv
                    ? (int)($pv['palette_viewer_id'] ?? 0)
                    : null,
            ];
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                $aName = trim((string)(
                    $a['nickname']
                    ?? $a['display_title']
                    ?? ''
                ));

                $bName = trim((string)(
                    $b['nickname']
                    ?? $b['display_title']
                    ?? ''
                ));

                return strcasecmp($aName, $bName);
            }
        );

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $photos
     */
    private function pickThumbnailUrl(array $photos): string
    {
        $fallback = '';

        foreach ($photos as $photo) {
            $url = trim((string)($photo['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            if ($fallback === '') {
                $fallback = $url;
            }

            if (
                strtolower(trim((string)($photo['photo_type'] ?? '')))
                === 'full'
            ) {
                return $url;
            }
        }

        return $fallback;
    }
}
