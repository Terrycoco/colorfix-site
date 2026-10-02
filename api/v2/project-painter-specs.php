<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\PROJECTS\Repos\PdoProjectRepository;
use App\Repos\PdoUrlReservationRepository;
use App\Services\UrlReservationService;
use App\Services\UrlReservations\UrlReservationRegistryFactory;

function painter_specs_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function painter_specs_members(PDO $pdo, int $savedPaletteId): array
{
    $stmt = $pdo->prepare(
        "
        SELECT
            m.id,
            m.saved_palette_id,
            m.color_id,
            m.role_name,
            m.sheen,
            m.note,
            m.order_index,

            c.name       AS color_name,
            c.code       AS color_code,
            c.brand      AS color_brand,
            c.brand_name AS color_brand_name,
            c.hex6       AS hex6,
            c.int_only   AS int_only

        FROM saved_palette_members m

        LEFT JOIN swatch_view c
          ON c.id = m.color_id

        WHERE m.saved_palette_id = :saved_palette_id

        ORDER BY
            m.order_index ASC,
            m.id ASC
        "
    );

    $stmt->execute([
        ':saved_palette_id' => $savedPaletteId,
    ]);

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'color_id' => (int)$row['color_id'],
                'role_name' => $row['role_name'] ?? null,
                'role' => $row['role_name'] ?? null,
                'sheen' => $row['sheen'] ?? null,
                'note' => $row['note'] ?? null,
                'order_index' => (int)($row['order_index'] ?? 0),

                'color_name' => $row['color_name'] ?? null,
                'name' => $row['color_name'] ?? null,

                'color_code' => $row['color_code'] ?? null,
                'code' => $row['color_code'] ?? null,

                'color_brand' => $row['color_brand'] ?? null,
                'brand' => $row['color_brand'] ?? null,

                'color_brand_name' => $row['color_brand_name'] ?? null,
                'brand_name' => $row['color_brand_name'] ?? null,

                'hex6' => $row['hex6'] ?? null,
                'int_only' => !empty($row['int_only']),
            ];
        },
        $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
}

function painter_specs_painter_viewer(PDO $pdo, int $savedPaletteId): ?array
{
    $stmt = $pdo->prepare(
        "
        SELECT
            palette_viewer_id,
            saved_palette_id,
            format,
            template_key,
            kicker_text,
            title,
            intro,
            notes,
            cta_label,
            is_active

        FROM palette_viewers

        WHERE saved_palette_id = :saved_palette_id
          AND LOWER(TRIM(COALESCE(format, ''))) = 'painter'

        ORDER BY
            is_active DESC,
            palette_viewer_id ASC

        LIMIT 1
        "
    );

    $stmt->execute([
        ':saved_palette_id' => $savedPaletteId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false
        ? $row
        : null;
}

function painter_specs_photos(PDO $pdo, int $paletteViewerId): array
{
    $stmt = $pdo->prepare(
        "
        SELECT
            pvp.palette_viewer_photo_id,
            pvp.photo_library_id,
            pvp.photo_type,
            pvp.order_index,
            pvp.caption,

            COALESCE(
                NULLIF(pl.rel_path, ''),
                NULLIF(pvp.rel_path, '')
            ) AS rel_path,

            COALESCE(
                NULLIF(pvp.alt_text, ''),
                NULLIF(pl.ai_alt_text, ''),
                NULLIF(pl.alt_text, '')
            ) AS alt_text,

            COALESCE(
                pl.updated_at,
                pvp.updated_at
            ) AS photo_updated_at

        FROM palette_viewer_photos pvp

        LEFT JOIN photo_library pl
          ON pl.photo_library_id = pvp.photo_library_id

        WHERE pvp.palette_viewer_id = :palette_viewer_id

        ORDER BY
            pvp.order_index ASC,
            pvp.palette_viewer_photo_id ASC
        "
    );

    $stmt->execute([
        ':palette_viewer_id' => $paletteViewerId,
    ]);

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int)$row['palette_viewer_photo_id'],
                'photo_library_id' =>
                    isset($row['photo_library_id'])
                    && $row['photo_library_id'] !== null
                        ? (int)$row['photo_library_id']
                        : null,
                'rel_path' => (string)($row['rel_path'] ?? ''),
                'photo_type' => (string)($row['photo_type'] ?? 'FULL'),
                'order_index' => (int)($row['order_index'] ?? 0),
                'photo_title' => (string)($row['alt_text'] ?? ''),
                'alt_text' => (string)($row['alt_text'] ?? ''),
                'caption' => (string)($row['caption'] ?? ''),
                'photo_updated_at' => $row['photo_updated_at'] ?? null,
            ];
        },
        $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
}

function painter_specs_project_palettes(PDO $pdo, int $projectId): array
{
    $stmt = $pdo->prepare(
        "
        SELECT
            pp.project_palette_id,
            pp.project_id,
            pp.saved_palette_id,
            pp.area_label,
            pp.note AS project_note,
            pp.is_final,
            pp.order_index,

            sp.nickname,
            sp.display_title,
            sp.palette_type

        FROM project_palettes pp

        INNER JOIN saved_palettes sp
          ON sp.id = pp.saved_palette_id

        WHERE pp.project_id = :project_id

        ORDER BY
            pp.order_index ASC,
            pp.project_palette_id ASC
        "
    );

    $stmt->execute([
        ':project_id' => $projectId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $plans = [];

    foreach ($rows as $row) {
        $savedPaletteId = (int)$row['saved_palette_id'];

        $viewer = painter_specs_painter_viewer(
            $pdo,
            $savedPaletteId
        );

        if (!$viewer) {
            continue;
        }

        $viewerId = (int)$viewer['palette_viewer_id'];

        $areaLabel = trim(
            (string)($row['area_label'] ?? '')
        );

        if ($areaLabel === '') {
            $areaLabel = trim(
                (string)(
                    $row['display_title']
                    ?? $row['nickname']
                    ?? ''
                )
            );
        }

        if ($areaLabel === '') {
            $areaLabel = 'Paint Specifications';
        }

        $painterNote = trim(
            (string)($viewer['intro'] ?? '')
        );

        $plans[] = [
            // Keep the old public payload shape so the current
            // ProjectPainterSpecsPage does not need to change yet.
            'id' => (int)$row['project_palette_id'],
            'project_id' => (int)$row['project_id'],
            'saved_palette_id' => $savedPaletteId,

            'area_name' => $areaLabel,
            'area_label' => $areaLabel,

            'nickname' => (string)($row['nickname'] ?? ''),
            'scheme_title' => $areaLabel,
            'palette_type' => (string)($row['palette_type'] ?? ''),
            'revision_number' => 1,
            'issued_at' => null,
            'is_final' => (int)($row['is_final'] ?? 0),

            'members' => painter_specs_members(
                $pdo,
                $savedPaletteId
            ),

            'painter_viewer' => [
                'form' => [
                    'scheme_title' => $areaLabel,

                    // IMPORTANT:
                    // palette_viewers.intro is the painter-facing note.
                    // palette_viewers.notes remains internal/admin-only.
                    'overall_painter_note' => $painterNote,
                    'intro' => $painterNote,
                ],

                'photos' => painter_specs_photos(
                    $pdo,
                    $viewerId
                ),

                'row' => [
                    'palette_viewer_id' => $viewerId,
                    'saved_palette_id' => $savedPaletteId,
                    'format' => 'painter',
                    'intro' => $painterNote,
                    'is_active' => (int)($viewer['is_active'] ?? 0),
                ],
            ],
        ];
    }

    return $plans;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        painter_specs_respond(
            [
                'ok' => false,
                'error' => 'GET only',
            ],
            405
        );
    }

    $token = trim(
        (string)(
            $_GET['reservation_token']
            ?? $_GET['token']
            ?? ''
        )
    );

    if ($token === '') {
        painter_specs_respond(
            [
                'ok' => false,
                'error' => 'reservation_token required',
            ],
            400
        );
    }

    $reservationService = new UrlReservationService(
        new PdoUrlReservationRepository($pdo),
        UrlReservationRegistryFactory::create($pdo)
    );

    $reserved =
        $reservationService
            ->resolveReservation($token);

    $reservation =
        is_array($reserved['reservation'] ?? null)
            ? $reserved['reservation']
            : [];

    $resolution =
        is_array($reserved['resolution'] ?? null)
            ? $reserved['resolution']
            : [];

    if (
        ($reservation['type_key'] ?? '')
        !== 'project_experience'
    ) {
        painter_specs_respond(
            [
                'ok' => false,
                'error' => 'Painter specs unavailable',
            ],
            404
        );
    }

    $experienceKey = strtolower(
        trim(
            (string)(
                $resolution['experience_key']
                ?? $reservation['experience_key']
                ?? ''
            )
        )
    );

    if ($experienceKey !== 'painter') {
        painter_specs_respond(
            [
                'ok' => false,
                'error' => 'Painter specs unavailable',
            ],
            404
        );
    }

    $projectId = (int)(
        $resolution['project_id']
        ?? $resolution['destination']['project_id']
        ?? 0
    );

    if ($projectId <= 0) {
        painter_specs_respond(
            [
                'ok' => false,
                'error' => 'Project not found',
            ],
            404
        );
    }

    $projectRepo =
        new PdoProjectRepository($pdo);

    $project =
        $projectRepo->findById($projectId);

    if (!$project) {
        painter_specs_respond(
            [
                'ok' => false,
                'error' => 'Project not found',
            ],
            404
        );
    }

    $plans =
        painter_specs_project_palettes(
            $pdo,
            $projectId
        );

    painter_specs_respond([
        'ok' => true,

        'data' => [
            'reservation' => [
                'token' => $token,
                'public_url' =>
                    $reservation['public_url']
                    ?? '',
            ],

            'project' => [
                'id' =>
                    (int)$project['id'],

                'name' =>
                    (string)(
                        $project['project_name']
                        ?? ''
                    ),

                'property_name' =>
                    (string)(
                        $project['property_name']
                        ?? ''
                    ),

                'client_name' =>
                    (string)(
                        $project['client_name']
                        ?? ''
                    ),

                'street_1' =>
                    (string)(
                        $project['street_1']
                        ?? ''
                    ),

                'street_2' =>
                    (string)(
                        $project['street_2']
                        ?? ''
                    ),

                'city' =>
                    (string)(
                        $project['city']
                        ?? ''
                    ),

                'state' =>
                    (string)(
                        $project['state']
                        ?? ''
                    ),

                'postal_code' =>
                    (string)(
                        $project['postal_code']
                        ?? ''
                    ),

                'country_code' =>
                    (string)(
                        $project['country_code']
                        ?? ''
                    ),
            ],

            'plans' => $plans,
        ],
    ]);
} catch (Throwable $e) {
    painter_specs_respond(
        [
            'ok' => false,
            'error' => 'Painter specs unavailable',
        ],
        404
    );
}
