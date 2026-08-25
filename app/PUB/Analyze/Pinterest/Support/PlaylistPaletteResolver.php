<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest\Support;

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\Services\ViewerService;
use RuntimeException;

/**
 * PLAYLIST PALETTE RESOLVER
 *
 * PUB support service for Idea + Palette analysis.
 *
 * Starting point:
 *
 *   playlist_id
 *
 * Resolution path:
 *
 *   Playlist
 *      ↓
 *   Public Playlist REX
 *      ↓
 *   child REX relationships: viewer
 *      ↓
 *   Viewer REX reservation
 *      ↓
 *   ViewerService
 *      ↓
 *   canonical Viewer payload
 *
 * Returns creator-ready ingredients:
 *
 *   source_photo
 *   palette_colors
 *   description
 *
 * This service intentionally does NOT use:
 *
 *   saved_palette_set_id
 *   playlist-item palette_hash
 *   AFTER-role photo discovery
 *
 * REX relationships are authoritative for determining
 * which Palette Viewers belong to the Playlist.
 */
final class PlaylistPaletteResolver
{
    public function __construct(
        private RexReservationRepositoryInterface $reservations,
        private RexReservationRelationships $relationships,
        private RexReserver $reserver,
        private ViewerService $viewers,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolve(
        int $playlistId
    ): array {
        if ($playlistId <= 0) {
            throw new RuntimeException(
                'Valid playlist ID required.'
            );
        }

        /*
         * Ensure the Public Playlist REX exists.
         *
         * This is infrastructure preparation,
         * not the PUB Pingback handoff value.
         */
        $playlistRex =
            $this->findOrCreatePublicPlaylistRex(
                $playlistId
            );

        /*
         * The Viewer children are the candidate
         * list for Idea + Palette pins.
         */
        $viewerReservations =
            $this->relationships->children(
                $playlistRex->id,
                'viewer'
            );

        $resolved = [];

        foreach (
            $viewerReservations
            as $viewerRex
        ) {
            if (
                !$viewerRex
                instanceof RexReservation
            ) {
                continue;
            }

            if (
                strtolower(
                    trim(
                        $viewerRex->status
                    )
                ) !== 'active'
            ) {
                continue;
            }

            if (
                strtolower(
                    trim(
                        $viewerRex
                            ->resolverKey
                    )
                ) !== 'viewer'
            ) {
                continue;
            }

            /*
             * Resolve exactly what the Viewer
             * REX reservation points to.
             *
             * ViewerService handles:
             *
             *   resource_type
             *   resource_id
             *   context
             *
             * PUB does not reconstruct the
             * Viewer relationship itself.
             */
            $viewer =
                $this->viewers->resolve(
                    $viewerRex
                        ->resourceType,

                    $viewerRex
                        ->resourceId,

                    $viewerRex
                        ->context
                );

            $meta =
                is_array(
                    $viewer['meta'] ??
                    null
                )
                    ? $viewer['meta']
                    : [];

            $swatches =
                is_array(
                    $viewer['swatches'] ??
                    null
                )
                    ? $viewer['swatches']
                    : [];

            /*
             * PaletteViewerService already
             * chooses the FULL photo, with its
             * own fallback when necessary.
             */
            $photoUrl =
                trim(
                    (string)(
                        $meta[
                            'photo_url'
                        ] ??
                        ''
                    )
                );

            /*
             * The Pinterest creator supports
             * 1–4 paint-can lids.
             */
            $colors =
                array_values(
                    array_slice(
                        $swatches,
                        0,
                        4
                    )
                );

            /*
             * A Viewer without both ingredients
             * cannot produce Idea + Palette.
             */
            if (
                $photoUrl === '' ||
                count($colors) === 0
            ) {
                continue;
            }

            $description =
                $this->firstNonEmpty([
                    $meta['notes'] ??
                        null,

                    $meta['intro'] ??
                        null,
                ]);

            $paletteViewerId =
                (int)(
                    $meta[
                        'palette_viewer_id'
                    ] ??
                    0
                );

            /*
             * Canonical Palette Viewer REX
             * normally has:
             *
             * resource_type = palette_viewer
             * resource_id   = palette_viewer_id
             *
             * Keep the resource ID as fallback
             * in case meta does not repeat it.
             */
            if (
                $paletteViewerId <= 0 &&
                strtolower(
                    trim(
                        $viewerRex
                            ->resourceType
                    )
                ) ===
                'palette_viewer'
            ) {
                $paletteViewerId =
                    $viewerRex
                        ->resourceId;
            }

            $resolved[] = [
                /*
                 * REX provenance.
                 */
                'viewer_rex_id' =>
                    $viewerRex->id,

                'viewer_rex_url' =>
                    $this->relationships
                        ->publicUrl(
                            $viewerRex
                        ),

                'palette_viewer_id' =>
                    $paletteViewerId > 0
                        ? $paletteViewerId
                        : null,

                /*
                 * Creator-ready FULL photo.
                 */
                'source_photo' => [
                    'image_url' =>
                        $photoUrl,

                    'alt_text' =>
                        $meta[
                            'photo_alt'
                        ] ??
                        null,
                ],

                /*
                 * Creator-ready ordered colors.
                 */
                'palette_colors' =>
                    $colors,

                /*
                 * Existing Viewer copy may
                 * seed the description.
                 *
                 * Search title intentionally
                 * does not come from Viewer;
                 * Mark supplies that later.
                 */
                'description' =>
                    $description,
            ];
        }

        return $resolved;
    }


    private function findOrCreatePublicPlaylistRex(
        int $playlistId
    ): RexReservation {
        $byPlaylist =
            $this->reservations
                ->findActiveByResourceIds(
                    'playlist_experience',
                    'playlist',
                    [$playlistId]
                );

        $existing =
            $byPlaylist[
                $playlistId
            ] ?? [];

        $public = [];

        foreach (
            $existing
            as $reservation
        ) {
            if (
                !$reservation
                instanceof RexReservation
            ) {
                continue;
            }

            if (
                strtolower(
                    trim(
                        $reservation
                            ->status
                    )
                ) !== 'active'
            ) {
                continue;
            }

            if (
                strtolower(
                    trim(
                        $reservation
                            ->resolverKey
                    )
                ) !==
                'playlist_experience'
            ) {
                continue;
            }

            if (
                strtolower(
                    trim(
                        $reservation
                            ->resourceType
                    )
                ) !==
                'playlist'
            ) {
                continue;
            }

            $experienceKey =
                strtolower(
                    trim(
                        (string)(
                            $reservation
                                ->context[
                                    'experience_key'
                                ] ??
                            ''
                        )
                    )
                );

            if (
                $experienceKey !==
                'public'
            ) {
                continue;
            }

            $public[] =
                $reservation;
        }

        /*
         * Same canonical rule already used
         * elsewhere in REX:
         *
         * oldest active Public reservation.
         */
        if ($public) {
            usort(
                $public,

                static fn(
                    RexReservation $a,
                    RexReservation $b
                ): int =>
                    $a->id <=>
                    $b->id
            );

            return $public[0];
        }

        /*
         * No Public Playlist REX yet.
         *
         * Create it so analysis can proceed.
         */
        return $this->reserver->reserve(
            new RexCreateReservationRequest(
                label:
                    "Public Playlist #{$playlistId}",

                resolverKey:
                    'playlist_experience',

                resourceType:
                    'playlist',

                resourceId:
                    $playlistId,

                adminNote:
                    'Auto-created by PUB Idea + Palette analysis.',

                context: [
                    'experience_key' =>
                        'public',
                ],
            )
        );
    }


    private function firstNonEmpty(
        array $values
    ): string {
        foreach (
            $values
            as $value
        ) {
            $text =
                trim(
                    (string)(
                        $value ??
                        ''
                    )
                );

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}