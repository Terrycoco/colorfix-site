<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Managers;

use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Services\PlaylistLandingService;
use App\PLAYLISTS\Services\PlaylistPhotoLibrarySyncService;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexPlaylistExperienceSyncService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PlaylistManager
{
    private PdoPlaylistRepository $playlists;
    private PlaylistLandingService $landing;

    public function __construct(
        private PDO $pdo
    ) {
        $this->playlists =
            new PdoPlaylistRepository(
                $this->pdo
            );

        $this->landing =
            new PlaylistLandingService(
                $this->playlists
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAdminPlaylists(): array
    {
        return $this->playlists
            ->listAdminRows();
    }

    /**
     * @return array{
     *   playlist: array<string, mixed>,
     *   items: array<int, array<string, mixed>>
     * }|null
     */
    public function getAdminPlaylist(
        int $playlistId
    ): ?array {
        if ($playlistId <= 0) {
            return null;
        }

        $playlist =
            $this->playlists
                ->getAdminRowById(
                    $playlistId
                );

        if ($playlist === null) {
            return null;
        }

        return [
            'playlist' =>
                $playlist,

            'items' =>
                $this->playlists
                    ->getAdminItemRows(
                        $playlistId
                    ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{playlist_id:int,slug:string}
     */
    public function saveAdminPlaylist(
        array $payload
    ): array {
        $playlistId =
            isset($payload['playlist_id'])
                ? (int)$payload['playlist_id']
                : 0;

        $title =
            trim(
                (string)($payload['title'] ?? '')
            );

        $type =
            trim(
                (string)($payload['type'] ?? '')
            );

        if ($title === '') {
            throw new InvalidArgumentException(
                'title required'
            );
        }

        if ($type === '') {
            throw new InvalidArgumentException(
                'type required'
            );
        }

        $existing =
            $playlistId > 0
                ? $this->playlists
                    ->getAdminRowById(
                        $playlistId
                    )
                : null;

        $headline =
            trim(
                (string)($payload['headline'] ?? '')
            );

        $requestedSlug =
            trim(
                (string)($payload['slug'] ?? '')
            );

        $slugSource =
            $headline !== ''
                ? $headline
                : $title;

        if ($requestedSlug !== '') {
            $slug =
                $this->landing
                    ->generateSlug(
                        $requestedSlug,
                        $playlistId > 0
                            ? $playlistId
                            : null
                    );

        } elseif (!empty($existing['slug'])) {
            $slug =
                (string)$existing['slug'];

        } else {
            $slug =
                $this->landing
                    ->generateSlug(
                        $slugSource,
                        $playlistId > 0
                            ? $playlistId
                            : null
                    );
        }

        $publishedAt =
            $this->normalizePublishedAt(
                (string)($payload['published_at'] ?? '')
            );

        $row = [
            'title' =>
                $title,

            'type' =>
                $type,

            'is_active' =>
                isset($payload['is_active'])
                    ? (int)(bool)$payload['is_active']
                    : 1,

            'is_public' =>
                isset($payload['is_public'])
                    ? (int)(bool)$payload['is_public']
                    : 0,

            'slug' =>
                $slug !== ''
                    ? $slug
                    : null,

            'headline' =>
                $this->nullableString(
                    $headline
                ),

            'page_title' =>
                $this->nullableString(
                    (string)($payload['page_title'] ?? '')
                ),

            'meta_description' =>
                $this->nullableString(
                    (string)($payload['meta_description'] ?? '')
                ),

            'dek' =>
                $this->nullableString(
                    (string)($payload['dek'] ?? '')
                ),

            'intro_html' =>
                $this->nullableString(
                    (string)($payload['intro_html'] ?? '')
                ),

            'body_html' =>
                $this->nullableString(
                    (string)($payload['body_html'] ?? '')
                ),

            'hero_image_id' =>
                isset($payload['hero_image_id'])
                && is_numeric($payload['hero_image_id'])
                && (int)$payload['hero_image_id'] > 0
                    ? (int)$payload['hero_image_id']
                    : null,

            'hero_image_url' =>
                $this->nullableString(
                    (string)($payload['hero_image_url'] ?? '')
                ),

            'hero_alt' =>
                $this->nullableString(
                    (string)($payload['hero_alt'] ?? '')
                ),

            'indexable' =>
                isset($payload['indexable'])
                    ? (int)(bool)$payload['indexable']
                    : 1,

            'published_at' =>
                $publishedAt,
        ];

        $savedId =
            $this->playlists
                ->saveAdminRow(
                    $playlistId,
                    $row
                );

        return [
            'playlist_id' =>
                $savedId,

            'slug' =>
                $slug,
        ];
    }

    /**
     * Save the playlist's current slide set, then synchronize
     * the REX relationship graph to the saved playlist source.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{rex_sync:array<string,mixed>}
     */
    public function saveAdminItems(
        int $playlistId,
        array $items
    ): array {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException(
                'playlist_id required'
            );
        }

        $ownsTransaction =
            !$this->pdo
                ->inTransaction();

        if ($ownsTransaction) {
            $this->pdo
                ->beginTransaction();
        }

        try {
            $photoSync =
                new PlaylistPhotoLibrarySyncService(
                    $this->pdo
                );

            $this->playlists
                ->saveAdminItems(
                    $playlistId,
                    $items,
                    [
                        $photoSync,
                        'normalizeItemForSave',
                    ]
                );

            /*
             * PLAYLISTS owns the source mutation.
             * REX owns REX identity/relationship behavior.
             *
             * The Manager coordinates the cross-domain consequence
             * after the playlist items have been persisted.
             */
            $rexSync =
                new RexPlaylistExperienceSyncService(
                    $this->pdo
                );

            $rexResult =
                $rexSync->syncPlaylist(
                    $playlistId
                );

            if (
                $ownsTransaction
                &&
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->commit();
            }

            return [
                'rex_sync' =>
                    $rexResult,
            ];

        } catch (Throwable $e) {
            if (
                $ownsTransaction
                &&
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Permanently delete one Playlist and its Playlist-owned rows.
     *
     * Reservations follow object lifetime; links follow relationships.
     * Deleting the Playlist removes its REX reservations and their links.
     * PV reservations remain because the PV objects still exist.
     */
    public function deletePlaylist(
        int $playlistId
    ): bool {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException(
                'playlist_id required'
            );
        }

        $existing =
            $this->playlists
                ->getAdminRowById(
                    $playlistId
                );

        if ($existing === null) {
            return false;
        }

        $ownsTransaction =
            !$this->pdo
                ->inTransaction();

        if ($ownsTransaction) {
            $this->pdo
                ->beginTransaction();
        }

        try {
            $rex =
                new PdoRexReservationRepository(
                    $this->pdo
                );

            /*
             * REX is the deletion gate.
             *
             * A Playlist may own several permanent REX identities:
             * Public, Concept, Client, Thumbs, etc.
             *
             * Delete each specific Playlist-owned REX reservation first.
             * If ANY reservation is locked, deleteREX() refuses it and this
             * outer transaction rolls every earlier REX deletion back.
             *
             * No Playlist-owned rows are touched until every applicable
             * REX identity has passed the gate.
             */
            $reservations =
                $rex->findByResource(
                    'playlist',
                    $playlistId,
                    500
                );

            foreach ($reservations as $reservation) {
                $rexDelete =
                    $rex->deleteREX(
                        (int)$reservation->id
                    );

                if (
                    ($rexDelete['ok'] ?? false)
                    !== true
                ) {
                    throw new RuntimeException(
                        (string)(
                            $rexDelete['message']
                            ?? "Playlist {$playlistId} cannot be deleted because its REX identity is protected."
                        )
                    );
                }
            }

            $this->playlists
                ->deleteItemsByPlaylistId(
                    $playlistId
                );

            $deleted =
                $this->playlists
                    ->deleteById(
                        $playlistId
                    );

            if ($deleted !== 1) {
                throw new RuntimeException(
                    "Playlist {$playlistId} was not deleted."
                );
            }

            if (
                $ownsTransaction
                &&
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->commit();
            }

            return true;

        } catch (Throwable $e) {
            if (
                $ownsTransaction
                &&
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setPublic(
        int $playlistId
    ): ?array {
        if ($playlistId <= 0) {
            return null;
        }

        $row =
            $this->playlists
                ->getAdminRowById(
                    $playlistId
                );

        if ($row === null) {
            return null;
        }

        $this->playlists
            ->setPublic(
                $playlistId,
                true
            );

        return [
            'playlist_id' =>
                $playlistId,

            'title' =>
                (string)($row['title'] ?? ''),

            'is_active' =>
                (int)($row['is_active'] ?? 0),

            'is_public' =>
                1,
        ];
    }

    private function nullableString(
        string $value
    ): ?string {
        $value =
            trim(
                $value
            );

        return $value !== ''
            ? $value
            : null;
    }

    private function normalizePublishedAt(
        string $value
    ): ?string {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            return null;
        }

        $value =
            str_replace(
                'T',
                ' ',
                $value
            );

        if (strlen($value) === 16) {
            $value .= ':00';
        }

        return $value;
    }
}
