<?php
declare(strict_types=1);

namespace App\GALLERY\Endpoints;

use App\GALLERY\Repos\PdoGalleryResultsRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\REX\Repos\PdoRexReservationRepository;
use PDO;
use Throwable;

final class GalleryResultsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::reply(405, ['ok' => false, 'error' => 'POST required']);
            return;
        }
        try {
            $request = json_decode(file_get_contents('php://input') ?: '', true);
            $rawIds = is_array($request) ? ($request['color_ids'] ?? null) : null;
            if (!is_array($rawIds) || count($rawIds) > 500) {
                self::reply(400, ['ok' => false, 'error' => 'color_ids array (maximum 500) required']);
                return;
            }
            $colorIds = [];
            foreach ($rawIds as $rawId) {
                if (!is_int($rawId) || $rawId <= 0) {
                    self::reply(400, ['ok' => false, 'error' => 'Invalid color ID']);
                    return;
                }
                $colorIds[$rawId] = $rawId;
            }
            if ($colorIds === []) {
                self::reply(200, ['ok' => true, 'items' => []]);
                return;
            }

            $grouped = (new PdoGalleryResultsRepository($pdo))->findByColorIds(array_values($colorIds));
            $paletteIds = [];
            foreach ($grouped as $rows) {
                foreach ($rows as $row) $paletteIds[(int)$row['saved_palette_id']] = true;
            }
            $rexByPalette = self::getViewerRexUrls($pdo, array_keys($paletteIds));

            $items = [];
            $usedPhotos = [];
            // Follow caller's color order, selecting one Gallery photo per color.
            foreach ($colorIds as $colorId) {
                foreach ($grouped[$colorId] ?? [] as $photo) {
                    $photoId = (int)$photo['photo_library_id'];
                    if (isset($usedPhotos[$photoId])) continue;
                    $usedPhotos[$photoId] = true;
                    $paletteId = (int)$photo['saved_palette_id'];
                    $items[] = [
                        'id' => 'ps_' . $colorId . '_' . $photoId,
                        'item_type' => 'picture-swatch',
                        'photo_library_id' => $photoId,
                        'photo_url' => $photo['rel_path'],
                        'photo_type' => 'main',
                        'palette_id' => $paletteId,
                        'saved_palette_id' => $paletteId,
                        'palette_hash' => $photo['palette_hash'],
                        'saved_palette_set_id' => null,
                        'rex_url' => $rexByPalette[$paletteId] ?? null,
                        'palette_name' => $photo['palette_name'],
                        'palette_brand' => $photo['palette_brand'],
                        'source_color_id' => $colorId,
                    ];
                    break;
                }
            }
            self::reply(200, ['ok' => true, 'items' => $items]);
        } catch (Throwable $e) {
            error_log('GalleryResultsEndpoint: ' . $e->getMessage());
            self::reply(500, ['ok' => false, 'error' => 'Gallery lookup failed']);
        }
    }

    /** @param list<int> $savedPaletteIds @return array<int,string> */
    private static function getViewerRexUrls(PDO $pdo, array $savedPaletteIds): array
    {
        if (!$savedPaletteIds) return [];
        $viewers = (new PdoPaletteViewerRepository($pdo))->findActivePublicBySavedPaletteIds($savedPaletteIds);
        $viewerByPalette = [];
        $viewerIds = [];
        foreach ($viewers as $savedPaletteId => $entries) {
            $viewer = $entries[0] ?? null;
            $id = $viewer !== null ? (int)$viewer->paletteViewerId : 0;
            if ($id > 0) {
                $viewerByPalette[(int)$savedPaletteId] = $id;
                $viewerIds[$id] = true;
            }
        }
        if (!$viewerIds) return [];
        $reservations = (new PdoRexReservationRepository($pdo))->findActiveByResourceIds('viewer', 'palette_viewer', array_keys($viewerIds));
        $urls = [];
        foreach ($viewerByPalette as $savedPaletteId => $viewerId) {
            $reservation = $reservations[$viewerId][0] ?? null;
            if ($reservation !== null) $urls[$savedPaletteId] = '/t/' . $reservation->token;
        }
        return $urls;
    }

    private static function reply(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
