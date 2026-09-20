<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Repos;

use PDO;

final class PdoPlaylistItemPaletteRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }


    /**
     * @return array<string,mixed>|null
     */
    public function getContext(
        int $playlistItemId
    ): ?array {
        if ($playlistItemId <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                "
                SELECT
                    pi.playlist_item_id,
                    pi.playlist_id,
                    pi.saved_palette_id,
                    p.project_id

                FROM playlist_items pi

                INNER JOIN playlists p
                    ON p.playlist_id = pi.playlist_id

                WHERE pi.playlist_item_id = :playlist_item_id

                LIMIT 1
                "
            );

        $stmt->execute([
            ':playlist_item_id' =>
                $playlistItemId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$row) {
            return null;
        }

        return [
            'playlist_item_id' =>
                (int)$row[
                    'playlist_item_id'
                ],

            'playlist_id' =>
                (int)$row[
                    'playlist_id'
                ],

            'project_id' =>
                isset(
                    $row[
                        'project_id'
                    ]
                )
                &&
                $row[
                    'project_id'
                ] !== null
                    ? (int)$row[
                        'project_id'
                    ]
                    : null,

            'saved_palette_id' =>
                isset(
                    $row[
                        'saved_palette_id'
                    ]
                )
                &&
                $row[
                    'saved_palette_id'
                ] !== null
                    ? (int)$row[
                        'saved_palette_id'
                    ]
                    : null,
        ];
    }


    public function setSavedPaletteId(
        int $playlistItemId,
        ?int $savedPaletteId
    ): void {
        if ($playlistItemId <= 0) {
            return;
        }

        $stmt =
            $this->pdo->prepare(
                "
                UPDATE playlist_items

                SET saved_palette_id =
                    :saved_palette_id

                WHERE playlist_item_id =
                    :playlist_item_id
                "
            );

        if (
            $savedPaletteId !== null
            &&
            $savedPaletteId > 0
        ) {
            $stmt->bindValue(
                ':saved_palette_id',
                $savedPaletteId,
                PDO::PARAM_INT
            );
        } else {
            $stmt->bindValue(
                ':saved_palette_id',
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ':playlist_item_id',
            $playlistItemId,
            PDO::PARAM_INT
        );

        $stmt->execute();
    }
}
