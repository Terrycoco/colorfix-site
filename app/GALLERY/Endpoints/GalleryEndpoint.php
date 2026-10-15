<?php

declare(strict_types=1);

namespace App\GALLERY\Endpoints;

use App\GALLERY\Helpers\GalleryFilterHelper;
use App\COLORS\DTO\Swatch;
use App\Repos\PdoPaletteViewerRepository;
use App\REX\Repos\PdoRexReservationRepository;
use PDO;
use Throwable;


/**
 * Complete Gallery response: stored query + editorial inserts + one photo per color.
 * No HTTP call to another PHP endpoint; this endpoint coordinates existing repositories.
 */
final class GalleryEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::reply(405, ['error' => 'POST required']);
            return;
        }

        try {
            $request = json_decode(file_get_contents('php://input') ?: '', true);
            $data = is_array($request) ? $request : [];
            $queryId = (int) ($data['query_id'] ?? 0);
            if ($queryId <= 0) {
                self::reply(400, ['error' => 'Missing or invalid query_id']);
                return;
            }

            $insertsStmt = $pdo->prepare(
                'SELECT * FROM items WHERE (query_id = :qid OR query_id = 17) AND is_active = 1 '
                . 'ORDER BY CASE WHEN query_id = 17 THEN 1 ELSE 0 END, '
                . 'COALESCE(insert_position, 999999), id'
            );
            $insertsStmt->execute([':qid' => $queryId]);
            $inserts = $insertsStmt->fetchAll(PDO::FETCH_ASSOC);

            $metaStmt = $pdo->prepare('SELECT * FROM sql_queries WHERE query_id = ?');
            $metaStmt->execute([$queryId]);
            $queryRow = $metaStmt->fetch(PDO::FETCH_ASSOC);
            if (!$queryRow || trim((string) ($queryRow['query'] ?? '')) === '') {
                self::reply(404, ['error' => 'Query not found or missing SQL']);
                return;
            }

            // Apply the existing Gallery filters against the materialized swatch
            // source, then restore vw_gallery for every migrated stored query.
            // Keep Blacks/Whites compatible if their stored SQL still names mv_swatch_view.
            $searchFilters = is_array($data['searchFilters'] ?? null) ? $data['searchFilters'] : [];
            $storedSql = (string) $queryRow['query'];
            $storedUsesGalleryView = preg_match('/\bvw_gallery\b/i', $storedSql) === 1;
            $filterSql = preg_replace('/\bvw_gallery\b/i', 'mv_swatch_view', $storedSql);
            [$sql, $filterParams] = GalleryFilterHelper::apply($pdo, $searchFilters, $filterSql);
            if ($storedUsesGalleryView || in_array($queryId, [12, 15], true)) {
                $sql = preg_replace('/\bmv_swatch_view\b/i', 'vw_gallery', $sql);
            }
            $usesGalleryView = preg_match('/\bvw_gallery\b/i', $sql) === 1;
            $requestParams = is_array($data['params'] ?? null) ? $data['params'] : [];
            $params = $filterParams + $requestParams;
            $params['group_mode'] = $params['group_mode'] ?? 'hue';
            $groupMode = in_array($params['group_mode'], ['lightness', 'chroma'], true)
                ? $params['group_mode'] : 'hue';
            $isSwatch = trim((string) ($queryRow['item_type'] ?? '')) === 'swatch' || $usesGalleryView;

            if ($isSwatch) {
                [$inner, $limit] = self::normalizeForOuterOrder($sql);
                $sql = 'SELECT q.*, cd.name AS __light_cat_name_outer, '
                    . 'cd.sort_order AS __light_cat_order_outer '
                    . "FROM ($inner) AS q LEFT JOIN category_definitions cd "
                    . "ON cd.id = q.light_cat_id AND cd.type = 'Lightness'";
                if ($usesGalleryView) {
                    // Keep the photo association in vw_gallery. Join only to
                    // obtain visibility flags and palette display metadata.
                    $sql .= ' LEFT JOIN photo_library gallery_photo '
                        . 'ON gallery_photo.photo_library_id = q.photo_id '
                        . 'LEFT JOIN saved_palettes gallery_palette '
                        . 'ON gallery_palette.id = q.photo_palette_id';
                    $sql = preg_replace('/^SELECT q\.\*,/i',
                        'SELECT q.*, '
                        . 'gallery_photo.show_in_gallery AS __photo_visible, '
                        . 'gallery_photo.is_inactive AS __photo_inactive, '
                        . 'gallery_photo.is_retired AS __photo_retired, '
                        . 'gallery_palette.palette_hash AS __palette_hash, '
                        . 'gallery_palette.nickname AS __palette_name, '
                        . 'gallery_palette.brand AS __palette_brand,', $sql, 1);
                }
                if ($groupMode === 'lightness') {
                    $sql .= ' ORDER BY COALESCE(q.light_cat_order, __light_cat_order_outer, 999), '
                        . 'q.hcl_l DESC, q.hcl_c ASC, q.hcl_h ASC';
                } elseif ($groupMode === 'chroma') {
                    $sql .= ' ORDER BY q.hcl_c DESC, q.hcl_h ASC, q.hcl_l DESC';
                } else {
                    $sql .= ' ORDER BY q.hue_cat_order, q.hcl_h ASC, q.hcl_l DESC';
                }
                $sql .= $limit;
            }

            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
            $names = array_unique($matches[1] ?? []);
            $bindings = array_intersect_key($params, array_flip($names));
            $stmt = $pdo->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // One joined result set: one SWATCH, plus at most one picture per
            // matching color ID. Ignore duplicate photo associations.
            $enrichSwatches = $isSwatch;
            $photoAssociations = 0;
            $photoInserted = 0;
            if ($usesGalleryView && $rows) {
                // Bulk resolve links, no extra photo association query.
                $paletteIds = array_values(array_unique(array_filter(
                    array_map('intval', array_column($rows, 'photo_palette_id')),
                    static fn (int $id): bool => $id > 0
                )));
                $rexByPalette = self::viewerRexUrls($pdo, $paletteIds);
                $seenColors = [];
                $selectedColors = [];
                $usedPhotoIds = [];
                $combined = [];
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? $row['color_id'] ?? 0);
                    if ($id <= 0) continue;
                    if (!isset($seenColors[$id])) {
                        $seenColors[$id] = true;
                        $swatch = array_replace($row, Swatch::fromRow($row)->toArray());
                        unset($swatch['photo_id'], $swatch['photo_url'], $swatch['photo_palette_id']);
                        foreach (array_keys($swatch) as $key) {
                            if (str_starts_with($key, '__photo_') || str_starts_with($key, '__palette_')) {
                                unset($swatch[$key]);
                            }
                        }
                        if (!isset($swatch['item_type'])) $swatch['item_type'] = 'swatch';
                        $combined[] = $swatch;
                    }
                    $photoId = (int) ($row['photo_id'] ?? 0);
                    if ($photoId > 0) ++$photoAssociations;
                    if ($photoId <= 0 || empty($row['photo_url'])
                        || (int) ($row['__photo_visible'] ?? 0) !== 1
                        || (int) ($row['__photo_inactive'] ?? 0) !== 0
                        || (int) ($row['__photo_retired'] ?? 0) !== 0
                        || isset($selectedColors[$id]) || isset($usedPhotoIds[$photoId])) {
                        continue;
                    }
                    $selectedColors[$id] = true;
                    $usedPhotoIds[$photoId] = true;
                    ++$photoInserted;
                    $paletteId = (int) ($row['photo_palette_id'] ?? 0);
                    $combined[] = [
                        'id' => 'ps_' . $id . '_' . $photoId,
                        'item_type' => 'picture-swatch',
                        'photo_library_id' => $photoId,
                        'photo_url' => $row['photo_url'],
                        'photo_type' => 'main',
                        'palette_id' => $paletteId,
                        'saved_palette_id' => $paletteId,
                        'palette_hash' => $row['__palette_hash'] ?? null,
                        'saved_palette_set_id' => null,
                        'rex_url' => $rexByPalette[$paletteId] ?? null,
                        'palette_name' => $row['__palette_name'] ?? null,
                        'palette_brand' => $row['__palette_brand'] ?? null,
                        'source_color_id' => $id,
                        'hue_cats' => $row['hue_cats'] ?? null,
                        'light_cat_name' => $row['light_cat_name'] ?? $row['__light_cat_name_outer'] ?? null,
                        'light_cat_order' => $row['light_cat_order'] ?? $row['__light_cat_order_outer'] ?? null,
                        'hcl_h' => (int) round((float) ($row['hcl_h'] ?? 0)),
                        'hcl_c' => (int) round((float) ($row['hcl_c'] ?? 0)),
                        'hcl_l' => (int) round((float) ($row['hcl_l'] ?? 0)),
                    ];
                }
                $rows = $combined;
            } else {
                foreach ($rows as &$row) {
                    if ($enrichSwatches && ($row['item_type'] ?? '') === 'swatch') {
                        $row = array_replace($row, Swatch::fromRow($row)->toArray());
                    }
                    if (!isset($row['item_type'])) $row['item_type'] = 'unknown';
                }
                unset($row);
            }
            foreach ($inserts as &$insert) {
                if (!isset($insert['item_type'])) $insert['item_type'] = 'unknown';
            }
            unset($insert);

            self::reply(200, [
                'success' => true,
                'meta' => [
                    'meta_id' => $queryRow['query_id'],
                    'display' => $queryRow['display'] ?? ($queryRow['name'] ?? ''),
                    'description' => $queryRow['description'] ?? '',
                    'item_type' => $queryRow['item_type'] ?? '',
                    'type' => $queryRow['type'] ?? '',
                    'on_click_query' => $queryRow['on_click_query'] ?? '',
                    'has_header' => $queryRow['has_header'] ?? '',
                    'header_title' => $queryRow['header_title'] ?? '',
                    'header_subtitle' => $queryRow['header_subtitle'] ?? '',
                    'header_content' => $queryRow['header_content'] ?? '',
                    'params' => $params,
                ],
                'results' => $rows,
                'inserts' => $inserts,
                'rowCount' => count($rows),
                'insertCount' => count($inserts),
                // Temporary pilot diagnostics: verify photos are returned here,
                // not via a second browser request.
                'gallery_photo_debug' => [
                    'swatch_detected' => $enrichSwatches,
                    'photo_associations' => $photoAssociations,
                    'photos_inserted' => $photoInserted,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('GalleryEndpoint: ' . $e->getMessage());
            $publicMessage = $e instanceof \PDOException ? 'Database error' : 'Unexpected error';
            $message = $publicMessage;
            self::reply(500, ['error' => $message]);
        }
    }

    private static function normalizeForOuterOrder(string $sql): array
    {
        $limit = '';
        if (preg_match('/\sLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', $sql, $match)) {
            $limit = $match[0];
            $sql = preg_replace('/\sLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', '', $sql);
        }
        $sql = preg_replace('/\sORDER\s+BY\s+.+$/is', '', $sql);
        return [$sql, $limit];
    }

    /** @param list<int> $paletteIds @return array<int,string> */
    private static function viewerRexUrls(PDO $pdo, array $paletteIds): array
    {
        if (!$paletteIds) return [];
        $viewers = (new PdoPaletteViewerRepository($pdo))->findActivePublicBySavedPaletteIds($paletteIds);
        $viewerByPalette = [];
        $viewerIds = [];
        foreach ($viewers as $paletteId => $entries) {
            $viewer = $entries[0] ?? null;
            $id = $viewer === null ? 0 : (int) $viewer->paletteViewerId;
            if ($id <= 0) continue;
            $viewerByPalette[(int) $paletteId] = $id;
            $viewerIds[$id] = true;
        }
        if (!$viewerIds) return [];
        $reservations = (new PdoRexReservationRepository($pdo))->findActiveByResourceIds(
            'viewer', 'palette_viewer', array_keys($viewerIds)
        );
        $urls = [];
        foreach ($viewerByPalette as $paletteId => $viewerId) {
            $reservation = $reservations[$viewerId][0] ?? null;
            if ($reservation !== null) $urls[$paletteId] = '/t/' . $reservation->token;
        }
        return $urls;
    }

    private static function reply(int $code, array $payload): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
