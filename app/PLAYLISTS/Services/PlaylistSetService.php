<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Services;

use App\PLAYLISTS\Entities\PlaylistSet;
use App\PLAYLISTS\Entities\PlaylistSetItem;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Repos\PdoPlaylistSetRepository;
use App\Repos\PdoPhotoLibraryRepository;
use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PlaylistSetService
{
    private PdoPlaylistSetRepository $sets;
    private PdoPlaylistRepository $playlists;
    private PdoPhotoLibraryRepository $photos;
    private PdoRexReservationRepository $rex;

    public function __construct(
        private PDO $pdo
    ) {
        $this->sets =
            new PdoPlaylistSetRepository(
                $this->pdo
            );

        $this->playlists =
            new PdoPlaylistRepository(
                $this->pdo
            );

        $this->photos =
            new PdoPhotoLibraryRepository(
                $this->pdo
            );

        $this->rex =
            new PdoRexReservationRepository(
                $this->pdo
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSets(
        bool $includeRetired = false
    ): array {
        return array_map(
            fn(PlaylistSet $set): array =>
                $this->setToArray(
                    $set
                ),
            $this->sets
                ->listAll(
                    $includeRetired
                ),
        );
    }

    /**
     * Admin/internal representation.
     *
     * @return array{
     *   set:array<string,mixed>,
     *   items:array<int,array<string,mixed>>
     * }|null
     */
    public function getSet(
        int $setId
    ): ?array {
        $set =
            $this->sets
                ->getById(
                    $setId
                );

        if (!$set instanceof PlaylistSet) {
            return null;
        }

        return [
            'set' =>
                $this->setToArray(
                    $set
                ),

            'items' =>
                $this->hydrateItems(
                    $this->sets
                        ->listItems(
                            $setId
                        ),
                    false
                ),
        ];
    }

    /**
     * Public representation for Home, Playlist Picker, etc.
     *
     * Playlist members are included only when:
     *   - the playlist is active
     *   - the playlist is public
     *   - a public playlist REX exists
     *
     * No playlist_instance data is used.
     *
     * @return array<string,mixed>|null
     */
    public function getPublicSet(
        ?int $setId = null,
        ?string $handle = null
    ): ?array {
        $set = null;

        if (
            $setId !== null
            && $setId > 0
        ) {
            $set =
                $this->sets
                    ->getById(
                        $setId
                    );
        } elseif (
            $handle !== null
            && trim($handle) !== ''
        ) {
            $set =
                $this->sets
                    ->getByHandle(
                        trim($handle)
                    );
        }

        if (
            !$set instanceof PlaylistSet
            || $set->isRetired
        ) {
            return null;
        }

        $items =
            $this->hydrateItems(
                $this->sets
                    ->listItems(
                        (int)$set->id
                    ),
                true
            );

        $updatedAt =
            trim(
                (string)(
                    $set->updatedAt
                    ?? ''
                )
            );

        $stamp =
            $updatedAt !== ''
                ? strtotime(
                    $updatedAt
                )
                : false;

        return [
            'id' =>
                $set->id,

            'handle' =>
                $set->handle,

            'title' =>
                $set->title,

            'subtitle' =>
                $set->subtitle,

            'context' =>
                $set->context,

            'cover_photo_library_id' =>
                $set->coverPhotoLibraryId,

            'cover_photo_url' =>
                $this->photoUrl(
                    $set->coverPhotoLibraryId
                ),

            'updated_at' =>
                $set->updatedAt,

            'version' =>
                (
                    $stamp !== false
                    && $stamp > 0
                )
                    ? (string)$stamp
                    : '',

            'end_cta' => [
                'label' =>
                    $set->endCtaLabel
                    ?: 'Explore ColorFix',

                'url' =>
                    $set->endCtaUrl
                    ?: '/',

                'enabled' =>
                    $set->endCtaEnabled,
            ],

            'items' =>
                $items,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveSet(
        array $payload
    ): PlaylistSet {
        $id =
            isset($payload['id'])
                ? (int)$payload['id']
                : null;

        if (
            $id !== null
            && $id <= 0
        ) {
            $id = null;
        }

        $handle =
            trim(
                (string)(
                    $payload['handle']
                    ?? ''
                )
            );

        $title =
            trim(
                (string)(
                    $payload['title']
                    ?? ''
                )
            );

        if ($handle === '') {
            throw new InvalidArgumentException(
                'Playlist set handle is required.'
            );
        }

        if ($title === '') {
            throw new InvalidArgumentException(
                'Playlist set title is required.'
            );
        }

        $existing =
            $id !== null
                ? $this->sets
                    ->getById(
                        $id
                    )
                : null;

        $set =
            new PlaylistSet(
                id:
                    $id,

                handle:
                    $handle,

                title:
                    $title,

                subtitle:
                    $this->nullableString(
                        $payload['subtitle']
                        ?? null
                    ),

                context:
                    $this->nullableString(
                        $payload['context']
                        ?? null
                    ),

                coverPhotoLibraryId:
                    $this->positiveIntOrNull(
                        $payload[
                            'cover_photo_library_id'
                        ]
                        ?? null
                    ),

                endCtaLabel:
                    $this->nullableString(
                        $payload[
                            'end_cta_label'
                        ]
                        ?? null
                    ),

                endCtaUrl:
                    $this->nullableString(
                        $payload[
                            'end_cta_url'
                        ]
                        ?? null
                    ),

                endCtaEnabled:
                    array_key_exists(
                        'end_cta_enabled',
                        $payload
                    )
                        ? (bool)$payload[
                            'end_cta_enabled'
                        ]
                        : (
                            $existing
                                ? $existing->endCtaEnabled
                                : true
                        ),

                isRetired:
                    array_key_exists(
                        'is_retired',
                        $payload
                    )
                        ? (bool)$payload[
                            'is_retired'
                        ]
                        : (
                            $existing
                                ? $existing->isRetired
                                : false
                        ),

                retiredAt:
                    $existing
                        ? $existing->retiredAt
                        : null,
            );

        return $this->sets
            ->save(
                $set
            );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function replaceItems(
        int $setId,
        array $items
    ): void {
        if (
            !$this->sets
                ->getById(
                    $setId
                )
        ) {
            throw new RuntimeException(
                "Playlist set not found: {$setId}"
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
            $this->sets
                ->replaceItems(
                    $setId,
                    $items
                );

            if (
                $ownsTransaction
                && $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->commit();
            }

        } catch (Throwable $e) {
            if (
                $ownsTransaction
                && $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Return the next direct playlist member in this set.
     *
     * @return array<string,mixed>|null
     */
    public function nextPlaylist(
        int $setId,
        int $currentPlaylistId
    ): ?array {
        $items =
            array_values(
                array_filter(
                    $this->hydrateItems(
                        $this->sets
                            ->listItems(
                                $setId
                            ),
                        true
                    ),
                    static fn(array $item): bool =>
                        $item['item_type']
                            === 'playlist'
                        && (int)(
                            $item['playlist_id']
                            ?? 0
                        ) > 0,
                )
            );

        if ($items === []) {
            return null;
        }

        foreach (
            $items
            as $index => $item
        ) {
            if (
                (int)$item['playlist_id']
                !== $currentPlaylistId
            ) {
                continue;
            }

            return $items[
                $index + 1
            ] ?? null;
        }

        return $items[0] ?? null;
    }

    /**
     * @param PlaylistSetItem[] $items
     * @return array<int,array<string,mixed>>
     */
    private function hydrateItems(
        array $items,
        bool $publicOnly
    ): array {
        $playlistRepresentations = [];
        $playlistIds = [];

        foreach ($items as $item) {
            if (
                $item instanceof PlaylistSetItem
                && $item->itemType === 'playlist'
                && $item->playlistId !== null
            ) {
                $representation =
                    $this->playlistRepresentation(
                        $item->playlistId
                    );

                if ($representation !== null) {
                    $playlistRepresentations[
                        $item->playlistId
                    ] = $representation;

                    $playlistIds[] =
                        $item->playlistId;
                }
            }
        }

        $publicRexByPlaylistId = [];

        if (
            $publicOnly
            && $playlistIds !== []
        ) {
            $publicRexByPlaylistId =
                $this->rex
                    ->findActiveByResourceIdsAndExperience(
                        'playlist_experience',
                        'playlist',
                        array_values(
                            array_unique(
                                $playlistIds
                            )
                        ),
                        'public'
                    );
        }

        $result = [];

        foreach ($items as $item) {
            if (!$item instanceof PlaylistSetItem) {
                continue;
            }

            if (
                $item->itemType === 'set'
                && $item->targetSetId !== null
            ) {
                $target =
                    $this->sets
                        ->getById(
                            $item->targetSetId
                        );

                if (
                    !$target instanceof PlaylistSet
                    || (
                        $publicOnly
                        && $target->isRetired
                    )
                ) {
                    continue;
                }

                $updatedAt =
                    trim(
                        (string)(
                            $target->updatedAt
                            ?? ''
                        )
                    );

                $stamp =
                    $updatedAt !== ''
                        ? strtotime(
                            $updatedAt
                        )
                        : false;

                $result[] = [
                    'id' =>
                        $item->id,

                    'playlist_set_id' =>
                        $item->playlistSetId,

                    'item_type' =>
                        'set',

                    'target_set_id' =>
                        $target->id,

                    'target_set_version' =>
                        (
                            $stamp !== false
                            && $stamp > 0
                        )
                            ? (string)$stamp
                            : '',

                    'playlist_id' =>
                        null,

                    'playlist_slug' =>
                        null,

                    'rex_url' =>
                        null,

                    'player_url' =>
                        null,

                    'sort_order' =>
                        $item->sortOrder,

                    'title' =>
                        $target->title,

                    'subtitle' =>
                        $target->subtitle
                        ?? '',

                    'photo_library_id' =>
                        $target
                            ->coverPhotoLibraryId,

                    'photo_url' =>
                        $this->photoUrl(
                            $target
                                ->coverPhotoLibraryId
                        ),
                ];

                continue;
            }

            if (
                $item->itemType !== 'playlist'
                || $item->playlistId === null
            ) {
                continue;
            }

            $playlist =
                $playlistRepresentations[
                    $item->playlistId
                ] ?? null;

            if ($playlist === null) {
                continue;
            }

            if (
                $publicOnly
                && (
                    !$playlist['is_active']
                    || !$playlist['is_public']
                )
            ) {
                continue;
            }

            $publicRex =
                $publicOnly
                    ? (
                        $publicRexByPlaylistId[
                            $item->playlistId
                        ] ?? null
                    )
                    : null;

            if (
                $publicOnly
                && $publicRex === null
            ) {
                continue;
            }

            $rexUrl =
                $publicRex !== null
                    ? '/t/'
                        . $publicRex->token
                    : null;

            $result[] = [
                'id' =>
                    $item->id,

                'playlist_set_id' =>
                    $item->playlistSetId,

                'item_type' =>
                    'playlist',

                'playlist_id' =>
                    $item->playlistId,

                'target_set_id' =>
                    null,

                'target_set_version' =>
                    '',

                'playlist_slug' =>
                    $playlist['slug'],

                'rex_url' =>
                    $rexUrl,

                'player_url' =>
                    $rexUrl,

                'sort_order' =>
                    $item->sortOrder,

                'title' =>
                    $playlist['title'],

                'subtitle' =>
                    '',

                'photo_library_id' =>
                    $playlist[
                        'cover_photo_library_id'
                    ],

                'photo_url' =>
                    $playlist[
                        'photo_url'
                    ],
            ];
        }

        return $result;
    }

    /**
     * cover-image is the canonical representation of a playlist
     * when that playlist is shown as a card.
     *
     * @return array{
     *   title:string,
     *   slug:?string,
     *   is_active:bool,
     *   is_public:bool,
     *   cover_photo_library_id:?int,
     *   photo_url:string
     * }|null
     */
    private function playlistRepresentation(
        int $playlistId
    ): ?array {
        $playlist =
            $this->playlists
                ->getAdminRowById(
                    $playlistId
                );

        if ($playlist === null) {
            return null;
        }

        $playlistTitle =
            trim(
                (string)(
                    $playlist['title']
                    ?? ''
                )
            );

        $coverTitle = '';
        $coverPhotoLibraryId = null;
        $coverImageUrl = '';

        foreach (
            $this->playlists
                ->getAdminItemRows(
                    $playlistId
                )
            as $row
        ) {
            $itemType =
                strtolower(
                    trim(
                        (string)(
                            $row['item_type']
                            ?? ''
                        )
                    )
                );

            if ($itemType !== 'cover-image') {
                continue;
            }

            $coverTitle =
                trim(
                    (string)(
                        $row['title']
                        ?? ''
                    )
                );

            $coverPhotoLibraryId =
                $this->positiveIntOrNull(
                    $row[
                        'photo_library_id'
                    ]
                    ?? null
                );

            $coverImageUrl =
                $this->normalizeStoredImageUrl(
                    (string)(
                        $row['image_url']
                        ?? ''
                    )
                );

            break;
        }

        $photoUrl =
            $this->photoUrl(
                $coverPhotoLibraryId
            );

        if (
            $photoUrl === ''
            && $coverImageUrl !== ''
        ) {
            $photoUrl =
                $coverImageUrl;
        }

        return [
            'title' =>
                $coverTitle !== ''
                    ? $coverTitle
                    : $playlistTitle,

            'slug' =>
                $this->nullableString(
                    $playlist['slug']
                    ?? null
                ),

            'is_active' =>
                (bool)(
                    (int)(
                        $playlist['is_active']
                        ?? 0
                    )
                ),

            'is_public' =>
                (bool)(
                    (int)(
                        $playlist['is_public']
                        ?? 0
                    )
                ),

            'cover_photo_library_id' =>
                $coverPhotoLibraryId,

            'photo_url' =>
                $photoUrl,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function setToArray(
        PlaylistSet $set
    ): array {
        return [
            'id' =>
                $set->id,

            'handle' =>
                $set->handle,

            'title' =>
                $set->title,

            'subtitle' =>
                $set->subtitle,

            'context' =>
                $set->context,

            'cover_photo_library_id' =>
                $set->coverPhotoLibraryId,

            'cover_photo_url' =>
                $this->photoUrl(
                    $set->coverPhotoLibraryId
                ),

            'end_cta_label' =>
                $set->endCtaLabel,

            'end_cta_url' =>
                $set->endCtaUrl,

            'end_cta_enabled' =>
                $set->endCtaEnabled,

            'is_retired' =>
                $set->isRetired,

            'retired_at' =>
                $set->retiredAt,

            'created_at' =>
                $set->createdAt,

            'updated_at' =>
                $set->updatedAt,
        ];
    }

    private function photoUrl(
        ?int $photoLibraryId
    ): string {
        if (
            $photoLibraryId === null
            || $photoLibraryId <= 0
        ) {
            return '';
        }

        $photo =
            $this->photos
                ->findById(
                    $photoLibraryId
                );

        if (!$photo) {
            return '';
        }

        $url =
            trim(
                (string)(
                    $photo['rel_path']
                    ?? ''
                )
            );

        if ($url === '') {
            return '';
        }

        $updatedAt =
            trim(
                (string)(
                    $photo['updated_at']
                    ?? ''
                )
            );

        $stamp =
            $updatedAt !== ''
                ? strtotime(
                    $updatedAt
                )
                : false;

        if (
            $stamp === false
            || $stamp <= 0
        ) {
            return $url;
        }

        return $url
            . (
                str_contains(
                    $url,
                    '?'
                )
                    ? '&'
                    : '?'
            )
            . 'v='
            . $stamp;
    }

    private function normalizeStoredImageUrl(
        string $value
    ): string {
        $value =
            trim($value);

        if (
            $value === ''
            || !str_starts_with(
                $value,
                'photo:'
            )
        ) {
            return $value;
        }

        $parts =
            explode(
                '|',
                $value,
                2
            );

        return trim(
            (string)(
                $parts[1]
                ?? ''
            )
        );
    }

    private function nullableString(
        mixed $value
    ): ?string {
        $value =
            trim(
                (string)(
                    $value
                    ?? ''
                )
            );

        return $value !== ''
            ? $value
            : null;
    }

    private function positiveIntOrNull(
        mixed $value
    ): ?int {
        $value =
            (int)(
                $value
                ?? 0
            );

        return $value > 0
            ? $value
            : null;
    }
}
