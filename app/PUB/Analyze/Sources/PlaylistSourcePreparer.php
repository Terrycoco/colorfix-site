<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Sources;

use App\PUB\Entities\PhotoEntity;
use App\PALETTES\PV\PVService;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use RuntimeException;

/**
 * PLAYLIST SOURCE PREPARER
 *
 * Converts a ColorFix Playlist into the canonical
 * source shape consumed by PUB Analyze.
 *
 * Responsibilities:
 *
 *   - load the Playlist
 *   - load active Public publishing slides
 *   - convert every usable source photo into the
 *     canonical PUB PhotoEntity shape
 *   - load linked PV publishing data
 *   - return a PUB-neutral source payload
 *
 * Must NOT:
 *
 *   - apply Pinterest eligibility
 *   - apply YouTube eligibility
 *   - pair Before / After slides
 *   - choose output types
 *   - create assets
 *   - package, schedule, or publish
 */
final class PlaylistSourcePreparer
{
    public function __construct(
        private PdoPlaylistRepository $playlists,
        private PVService $pvService
    ) {}


    /**
     * @return array{
     *   source_type: string,
     *   source_id: int,
     *   title: string,
     *   items: array<int, array<string, mixed>>,
     *   linked_pvs: array<int, array<string, mixed>>
     * }
     */
    public function prepare(
        int $playlistId
    ): array {
        if ($playlistId <= 0) {
            throw new RuntimeException(
                'PlaylistSourcePreparer requires a valid playlist ID.'
            );
        }


        /*
         * PLAYLIST IDENTITY
         */
        $playlist =
            $this->playlists
                ->getAdminRowById(
                    $playlistId
                );

        if ($playlist === null) {
            throw new RuntimeException(
                'Playlist not found.'
            );
        }


        /*
         * SOURCE SLIDES
         */
        $items =
            $this->playlists
                ->getActivePublishingSlides(
                    $playlistId
                );


        /*
         * PROJECT ROOT
         */
        $projectRoot =
            dirname(
                __DIR__,
                4
            );


        /*
         * CANONICAL PUB PHOTOS
         */
        $items =
            array_map(
                static function (
                    array $item
                ) use (
                    $projectRoot
                ): array {
                    $photoLibraryId =
                        (int)(
                            $item[
                                'photo_library_id'
                            ]
                            ?? 0
                        );

                    $imageUrl =
                        trim(
                            (string)(
                                $item[
                                    'image_url'
                                ]
                                ?? ''
                            )
                        );

                    if (
                        $photoLibraryId <= 0
                        || $imageUrl === ''
                    ) {
                        return $item;
                    }


                    $filePath =
                        rtrim(
                            $projectRoot,
                            DIRECTORY_SEPARATOR
                        )
                        . DIRECTORY_SEPARATOR
                        . ltrim(
                            $imageUrl,
                            '/'
                        );


                    $photo =
                        new PhotoEntity(
                            photoLibraryId:
                                $photoLibraryId,

                            imageUrl:
                                $imageUrl,

                            filePath:
                                $filePath,

                            title:
                                trim(
                                    (string)(
                                        $item[
                                            'title'
                                        ]
                                        ?? ''
                                    )
                                ) ?: null,
                        );


                    $item['photo'] =
                        $photo->toArray();

                    return $item;
                },

                $items
            );


        /*
         * LINKED PV DATA
         *
         * Shape is defined by PUBContract.
         */
        $linkedPVs =
            $this->pvService
                ->getLinkedPubData(
                    $playlistId
                );


        return [
            'source_type' =>
                'playlist',

            'source_id' =>
                $playlistId,

            'title' =>
                (string)(
                    $playlist[
                        'title'
                    ]
                    ?? ''
                ),

            'items' =>
                $items,

            'linked_pvs' =>
                $linkedPVs,
        ];
    }
}