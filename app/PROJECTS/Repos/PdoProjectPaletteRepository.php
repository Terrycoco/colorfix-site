<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;

final class PdoProjectPaletteRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }


    public function listForProject(
        int $projectId
    ): array {
        if ($projectId <= 0) {
            return [];
        }

        $stmt =
            $this->pdo->prepare(
                "
                SELECT
                    pp.project_palette_id,
                    pp.project_id,
                    pp.saved_palette_id,
                    pp.created_at,

                    sp.palette_hash,
                    sp.nickname,
                    sp.display_title

                FROM project_palettes pp

                INNER JOIN saved_palettes sp
                    ON sp.id = pp.saved_palette_id

                WHERE pp.project_id = :project_id

                ORDER BY
                    COALESCE(
                        NULLIF(TRIM(sp.display_title), ''),
                        NULLIF(TRIM(sp.nickname), ''),
                        CONCAT('Palette #', sp.id)
                    ) ASC,
                    sp.id ASC
                "
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,
        ]);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
            ?: [];

        if (!$rows) {
            return [];
        }

        $paletteIds =
            array_values(
                array_unique(
                    array_map(
                        static fn(array $row): int =>
                            (int)$row['saved_palette_id'],
                        $rows
                    )
                )
            );

        $colorsByPalette =
            $this->colorsForPalettes(
                $paletteIds
            );

        foreach (
            $rows
            as &$row
        ) {
            $savedPaletteId =
                (int)$row[
                    'saved_palette_id'
                ];

            $row[
                'project_palette_id'
            ] =
                (int)$row[
                    'project_palette_id'
                ];

            $row[
                'project_id'
            ] =
                (int)$row[
                    'project_id'
                ];

            $row[
                'saved_palette_id'
            ] =
                $savedPaletteId;

            $row[
                'colors'
            ] =
                $colorsByPalette[
                    $savedPaletteId
                ]
                ?? [];
        }

        unset($row);

        return $rows;
    }


    private function colorsForPalettes(
        array $paletteIds
    ): array {
        $paletteIds =
            array_values(
                array_filter(
                    array_map(
                        'intval',
                        $paletteIds
                    ),
                    static fn(int $id): bool =>
                        $id > 0
                )
            );

        if (!$paletteIds) {
            return [];
        }

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count(
                        $paletteIds
                    ),
                    '?'
                )
            );

        $stmt =
            $this->pdo->prepare(
                "
                SELECT
                    m.id AS member_id,
                    m.saved_palette_id,
                    m.color_id,
                    m.role_name AS role,
                    m.order_index,

                    c.name AS color_name,
                    c.brand AS color_brand,
                    c.brand_name AS color_brand_name,
                    c.code AS color_code,
                    c.hex6 AS color_hex6

                FROM saved_palette_members m

                LEFT JOIN swatch_view c
                    ON c.id = m.color_id

                WHERE m.saved_palette_id IN ({$placeholders})

                ORDER BY
                    m.saved_palette_id ASC,
                    m.order_index ASC,
                    m.id ASC
                "
            );

        $stmt->execute(
            $paletteIds
        );

        $map = [];

        foreach (
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
            ?: []
            as $row
        ) {
            $savedPaletteId =
                (int)$row[
                    'saved_palette_id'
                ];

            $map[
                $savedPaletteId
            ][] = [
                'member_id' =>
                    (int)$row[
                        'member_id'
                    ],

                'color_id' =>
                    (int)$row[
                        'color_id'
                    ],

                'role' =>
                    $row[
                        'role'
                    ]
                    ?? null,

                'order_index' =>
                    (int)$row[
                        'order_index'
                    ],

                'color_name' =>
                    $row[
                        'color_name'
                    ]
                    ?? null,

                'color_brand' =>
                    $row[
                        'color_brand'
                    ]
                    ?? null,

                'color_brand_name' =>
                    $row[
                        'color_brand_name'
                    ]
                    ?? null,

                'color_code' =>
                    $row[
                        'color_code'
                    ]
                    ?? null,

                'color_hex6' =>
                    $row[
                        'color_hex6'
                    ]
                    ?? null,
            ];
        }

        return $map;
    }
}
