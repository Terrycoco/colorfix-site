<?php
declare(strict_types=1);

namespace App\PUB\Package\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use PDO;
use RuntimeException;

/**
 * PINTEREST IMAGE PACKAGER
 *
 * Packing specialist for finished Pinterest image assets.
 *
 * By the time this worker receives an asset, CREATE is done.
 * It does not care how the JPEG was rendered. It may inspect
 * outside-of-box asset_type when a Pinterest image product has
 * special packaging behavior (for example pin_teaser).
 *
 * INPUT:
 *   one durable pub_assets row
 *
 * OUTPUT:
 *   one self-contained Pinterest image package array
 *   ready for Shipping to combine with its own fixed
 *   connection/destination configuration and send.
 *
 * PACKAGE SHAPE:
 *
 *   title
 *   description
 *   link
 *   media_source.source_type
 *   media_source.url
 *
 * This Packager DOES:
 *   - verify this is a Pinterest image asset
 *   - verify required per-asset metadata is present
 *   - verify the finished physical image still exists
 *   - resolve browser-facing URLs to absolute public URLs
 *   - recover a missing normal Pinterest REX destination
 *   - clear an inherited source REX for pin_teaser and hold it PENDING
 *   - attach src=pin to normal outbound REX destinations
 *   - build the exact variable Pinterest image payload
 *
 * This Packager DOES NOT:
 *   - render or alter the creative asset
 *   - choose publication timing
 *   - authenticate with Pinterest
 *   - own Pinterest secrets
 *   - choose the fixed Pinterest board
 *   - call the Pinterest API
 *   - persist pub_assets.package
 *
 * PubCom:
 *   - READY means the assignment can be packed now
 *   - PENDING means a required piece is not available yet
 *   - INELIGIBLE means the assignment does not belong on
 *     this packing line or contains unusable data
 */
final class PinterestImagePackager implements PubComWorkerContract
{
    private const CHANNEL =
        'pinterest';

    private const MEDIA_SOURCE_TYPE =
        'image_url';

    private const SOURCE_TAG_KEY =
        'src';

    private const SOURCE_TAG_VALUE =
        'pin';

    private ?PubComChannel $pubComChannel = null;

    private PdoPubAssetRepository $assets;
    private PdoRexReservationRepository $rexReservations;
    private RexReservationRelationships $rexRelationships;

    /**
     * Effective destinations resolved during this PackageManager pass.
     * The same Packager instance is reused across the batch, so this
     * prevents duplicate REX lookups between preflight() and pack().
     *
     * @var array<int, string>
     */
    private array $effectivePingbacks = [];


    public function __construct(
        private string $publicBaseUrl,
        PDO $pdo,
    ) {
        $this->publicBaseUrl =
            rtrim(
                trim(
                    $this->publicBaseUrl
                ),
                '/'
            );

        $this->assets =
            new PdoPubAssetRepository(
                $pdo
            );

        $this->rexReservations =
            new PdoRexReservationRepository(
                $pdo
            );

        $this->rexRelationships =
            new RexReservationRelationships(
                $this->rexReservations
            );
    }


    /**
     * PUBCOM ONBOARDING
     */
    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * PUBCOM READINESS
     *
     * The worker itself has no external service dependency.
     * Pinterest authentication belongs to Shipping.
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Pinterest Image Packager is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM PREFLIGHT
     *
     * Inspect one durable asset before the Manager authorizes
     * packing. Missing pieces are normal PENDING conditions:
     * leave the row at pipeline_stage=packing and try again
     * on a later PackageManager pass.
     */
    public function preflight(
        array $asset
    ): PubComSignal {
        $channel =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            );


        if ($channel !== self::CHANNEL) {
            return PubComSignal::ineligible(
                'pinterest_image_wrong_channel',
                'Pinterest Image Packager only accepts Pinterest assets.',
                [
                    'worker' =>
                        self::class,

                    'channel' =>
                        $channel,
                ]
            );
        }


        $mimeType =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'mime_type'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $mimeType === ''
            ||
            !str_starts_with(
                $mimeType,
                'image/'
            )
        ) {
            return PubComSignal::ineligible(
                'pinterest_image_wrong_media_type',
                'Pinterest Image Packager only accepts image assets.',
                [
                    'worker' =>
                        self::class,

                    'mime_type' =>
                        $mimeType,
                ]
            );
        }


        $filePath =
            trim(
                (string)(
                    $asset[
                        'file_path'
                    ]
                    ?? ''
                )
            );


        if ($filePath === '') {
            return $this->pending(
                'pinterest_image_file_path_pending',
                'Waiting for finished Pinterest image file.',
                'file_path'
            );
        }


        if (!is_file($filePath)) {
            return $this->pending(
                'pinterest_image_file_pending',
                'Waiting for finished Pinterest image file to be available.',
                'file_path',
                [
                    'file_path' =>
                        $filePath,
                ]
            );
        }


        $title =
            trim(
                (string)(
                    $asset[
                        'search_title'
                    ]
                    ?? ''
                )
            );


        if ($title === '') {
            return $this->pending(
                'pinterest_image_title_pending',
                'Waiting for Pinterest title.',
                'search_title'
            );
        }


        $description =
            trim(
                (string)(
                    $asset[
                        'description'
                    ]
                    ?? ''
                )
            );


        if ($description === '') {
            return $this->pending(
                'pinterest_image_description_pending',
                'Waiting for Pinterest description.',
                'description'
            );
        }


        $assetUrl =
            trim(
                (string)(
                    $asset[
                        'url'
                    ]
                    ?? ''
                )
            );


        if ($assetUrl === '') {
            return $this->pending(
                'pinterest_image_public_url_pending',
                'Waiting for Pinterest image public URL.',
                'url'
            );
        }


        $mediaUrl =
            $this->absoluteUrl(
                $assetUrl
            );


        if (!$this->isAbsoluteHttpUrl($mediaUrl)) {
            return PubComSignal::ineligible(
                'pinterest_image_public_url_invalid',
                'Pinterest image public URL is not usable.',
                [
                    'worker' =>
                        self::class,

                    'url' =>
                        $assetUrl,
                ]
            );
        }


        $pingback =
            $this->effectivePingback(
                $asset
            );


        if ($pingback === '') {
            return $this->pending(
                'pinterest_image_destination_pending',
                $this->isTeaserAsset(
                    $asset
                )
                    ? 'Waiting for teaser destination URL.'
                    : 'Waiting for Pinterest destination URL.',
                'pingback'
            );
        }


        $destinationUrl =
            $this->absoluteUrl(
                $pingback
            );


        if (!$this->isAbsoluteHttpUrl($destinationUrl)) {
            return PubComSignal::ineligible(
                'pinterest_image_destination_invalid',
                'Pinterest destination URL is not usable.',
                [
                    'worker' =>
                        self::class,

                    'pingback' =>
                        $pingback,
                ]
            );
        }


        return PubComSignal::ready(
            'Pinterest image asset is ready to pack.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PACK ONE PINTEREST IMAGE
     *
     * The Manager has already accepted this assignment's
     * PubCom preflight result before calling pack().
     *
     * Returns a normal PHP array. PackageManager hands this
     * array unchanged to PdoPubAssetRepository::markPacked(),
     * where it is serialized into pub_assets.package.
     *
     * @return array<string, mixed>
     */
    public function pack(
        array $asset
    ): array {
        $this->assertPackable(
            $asset
        );


        $title =
            trim(
                (string)$asset[
                    'search_title'
                ]
            );

        $description =
            trim(
                (string)$asset[
                    'description'
                ]
            );

        $mediaUrl =
            $this->absoluteUrl(
                (string)$asset[
                    'url'
                ]
            );

        $destinationUrl =
            $this->absoluteUrl(
                $this->effectivePingback(
                    $asset
                )
            );


        /*
         * Normal ColorFix Pinterest destinations retain src=pin.
         * Teasers deliberately point outside the normal REX path
         * (currently YouTube), so preserve the operator's URL exactly.
         */
        if (!$this->isTeaserAsset($asset)) {
            $destinationUrl =
                $this->withSourceTag(
                    $destinationUrl
                );
        }


        return [
            'title' =>
                $title,

            'description' =>
                $description,

            'link' =>
                $destinationUrl,

            'media_source' => [
                'source_type' =>
                    self::MEDIA_SOURCE_TYPE,

                'url' =>
                    $mediaUrl,
            ],
        ];
    }


    /**
     * Defensive guard for direct/misordered calls to pack().
     * Normal expected incompleteness should already have been
     * reported through preflight() as PENDING.
     */
    private function assertPackable(
        array $asset
    ): void {
        $channel =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            );

        $mimeType =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'mime_type'
                        ]
                        ?? ''
                    )
                )
            );


        if ($channel !== self::CHANNEL) {
            throw new RuntimeException(
                'Pinterest Image Packager received a non-Pinterest asset.'
            );
        }


        if (
            $mimeType === ''
            ||
            !str_starts_with(
                $mimeType,
                'image/'
            )
        ) {
            throw new RuntimeException(
                'Pinterest Image Packager received a non-image asset.'
            );
        }


        foreach (
            [
                'file_path',
                'url',
                'search_title',
                'description',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $asset[
                            $field
                        ]
                        ?? ''
                    )
                ) === ''
            ) {
                throw new RuntimeException(
                    "Pinterest Image Packager cannot pack without {$field}."
                );
            }
        }


        $filePath =
            trim(
                (string)$asset[
                    'file_path'
                ]
            );


        if (!is_file($filePath)) {
            throw new RuntimeException(
                "Pinterest Image Packager cannot find finished image file: {$filePath}"
            );
        }


        $mediaUrl =
            $this->absoluteUrl(
                (string)$asset[
                    'url'
                ]
            );

        $destinationUrl =
            $this->absoluteUrl(
                $this->effectivePingback(
                    $asset
                )
            );


        if ($destinationUrl === '') {
            throw new RuntimeException(
                'Pinterest Image Packager cannot pack without pingback.'
            );
        }


        if (!$this->isAbsoluteHttpUrl($mediaUrl)) {
            throw new RuntimeException(
                'Pinterest Image Packager cannot resolve a valid public image URL.'
            );
        }


        if (!$this->isAbsoluteHttpUrl($destinationUrl)) {
            throw new RuntimeException(
                'Pinterest Image Packager cannot resolve a valid destination URL.'
            );
        }
    }


    /**
     * Resolve the outside-of-box destination for this Pinterest image.
     *
     * Normal Pinterest images:
     *   - preserve an explicit pingback
     *   - if blank, recover the source Playlist's canonical Public REX
     *     and persist it on pub_assets
     *
     * Teasers:
     *   - if the inherited pingback is the source Playlist REX, clear it
     *   - if blank, remain PENDING until the operator supplies a target
     *   - preserve any deliberate non-REX destination (for example YouTube)
     */
    private function effectivePingback(
        array $asset
    ): string {
        $pubAssetId =
            (int)(
                $asset[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if (
            $pubAssetId > 0
            && array_key_exists(
                $pubAssetId,
                $this->effectivePingbacks
            )
        ) {
            return $this->effectivePingbacks[
                $pubAssetId
            ];
        }


        $pingback =
            trim(
                (string)(
                    $asset[
                        'pingback'
                    ]
                    ?? ''
                )
            );


        $sourceType =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            );

        $sourceId =
            (int)(
                $asset[
                    'source_id'
                ]
                ?? 0
            );


        if ($this->isTeaserAsset($asset)) {
            /*
             * Blank is the expected teaser state until the operator knows
             * the outbound destination. Do not wake REX merely to confirm
             * that an already-blank value should stay blank.
             */
            if ($pingback === '') {
                if ($pubAssetId > 0) {
                    $this->effectivePingbacks[
                        $pubAssetId
                    ] = '';
                }


                return '';
            }


            $sourceRex =
                $sourceType === 'playlist'
                && $sourceId > 0
                    ? $this->canonicalPublicPlaylistRexUrl(
                        $sourceId
                    )
                    : '';


            if (
                $sourceRex !== ''
                && $this->sameDestinationIgnoringQuery(
                    $pingback,
                    $sourceRex
                )
            ) {
                if ($pubAssetId > 0) {
                    $this->assets
                        ->updatePackagePingback(
                            $pubAssetId,
                            null
                        );
                }

                $pingback = '';
            }


            if ($pubAssetId > 0) {
                $this->effectivePingbacks[
                    $pubAssetId
                ] =
                    $pingback;
            }


            return $pingback;
        }


        /*
         * Explicit destinations always win for normal Pinterest images.
         * Only a genuinely blank destination needs the source REX fallback.
         */
        if ($pingback !== '') {
            if ($pubAssetId > 0) {
                $this->effectivePingbacks[
                    $pubAssetId
                ] =
                    $pingback;
            }


            return $pingback;
        }


        $sourceRex =
            $sourceType === 'playlist'
            && $sourceId > 0
                ? $this->canonicalPublicPlaylistRexUrl(
                    $sourceId
                )
                : '';


        if ($sourceRex !== '') {
            $pingback =
                $sourceRex;


            if ($pubAssetId > 0) {
                $this->assets
                    ->updatePackagePingback(
                        $pubAssetId,
                        $pingback
                    );
            }
        }


        if ($pubAssetId > 0) {
            $this->effectivePingbacks[
                $pubAssetId
            ] =
                $pingback;
        }


        return $pingback;
    }


    private function isTeaserAsset(
        array $asset
    ): bool {
        return strtolower(
            trim(
                (string)(
                    $asset[
                        'asset_type'
                    ]
                    ?? ''
                )
            )
        ) === 'pin_teaser';
    }


    /**
     * Same canonical Public Playlist REX selection used by the admin
     * playlist-url helper: active public playlist_experience rows,
     * preferring a reservation with viewer children and then the oldest.
     *
     * PACKAGE deliberately does not create a REX. If none exists, the
     * normal Pinterest image remains PENDING rather than manufacturing
     * routing resources as a side effect of packing.
     */
    private function canonicalPublicPlaylistRexUrl(
        int $playlistId
    ): string {
        if ($playlistId <= 0) {
            return '';
        }


        $reservations =
            $this->rexReservations
                ->search(
                    new RexReservationSearchCriteria(
                        resolverKey: 'playlist_experience',
                        resourceType: 'playlist',
                        resourceId: $playlistId,
                        status: 'active',
                        limit: 500,
                        experienceKey: 'public',
                    )
                );


        $eligible =
            array_values(
                array_filter(
                    $reservations,
                    static fn (
                        RexReservation $reservation
                    ): bool =>
                        strtolower(
                            trim(
                                $reservation->resolverKey
                            )
                        ) === 'playlist_experience'
                        && strtolower(
                            trim(
                                $reservation->resourceType
                            )
                        ) === 'playlist'
                        && strtolower(
                            trim(
                                (string)(
                                    $reservation->experienceKey
                                    ?? ''
                                )
                            )
                        ) === 'public'
                        && strtolower(
                            trim(
                                $reservation->status
                            )
                        ) === 'active'
                )
            );


        if ($eligible === []) {
            return '';
        }


        usort(
            $eligible,
            function (
                RexReservation $a,
                RexReservation $b
            ): int {
                $aChildren =
                    count(
                        $this->rexRelationships
                            ->children(
                                $a->id,
                                'viewer'
                            )
                    );

                $bChildren =
                    count(
                        $this->rexRelationships
                            ->children(
                                $b->id,
                                'viewer'
                            )
                    );


                if ($aChildren !== $bChildren) {
                    return $bChildren <=> $aChildren;
                }


                return $a->id <=> $b->id;
            }
        );


        $selected =
            $eligible[0]
            ?? null;


        if (!$selected instanceof RexReservation) {
            return '';
        }


        $token =
            trim(
                $selected->token
            );


        if ($token === '') {
            return '';
        }


        return $this->absoluteUrl(
            '/t/'
            . $token
        );
    }


    private function sameDestinationIgnoringQuery(
        string $a,
        string $b
    ): bool {
        $a =
            $this->absoluteUrl(
                $a
            );

        $b =
            $this->absoluteUrl(
                $b
            );


        if (
            $a === ''
            || $b === ''
        ) {
            return false;
        }


        $strip =
            static fn (
                string $url
            ): string =>
                rtrim(
                    (string)(
                        preg_replace(
                            '/[?#].*$/',
                            '',
                            $url
                        )
                    ),
                    '/'
                );


        return $strip($a) === $strip($b);
    }


    private function pending(
        string $code,
        string $message,
        string $missingField,
        array $context = []
    ): PubComSignal {
        return PubComSignal::pending(
            $code,
            $message,
            [
                'worker' =>
                    self::class,

                'missing' =>
                    $missingField,

                ...$context,
            ]
        );
    }


    /**
     * Resolve an asset-relative browser URL against the
     * public ColorFix base URL supplied by PackageManager.
     */
    private function absoluteUrl(
        string $url
    ): string {
        $url =
            trim(
                $url
            );


        if ($url === '') {
            return '';
        }


        if ($this->isAbsoluteHttpUrl($url)) {
            return $url;
        }


        if (
            $this->publicBaseUrl === ''
            ||
            !$this->isAbsoluteHttpUrl(
                $this->publicBaseUrl
            )
        ) {
            return '';
        }


        return
            $this->publicBaseUrl
            . '/'
            . ltrim(
                $url,
                '/'
            );
    }


    private function isAbsoluteHttpUrl(
        string $url
    ): bool {
        return (bool)preg_match(
            '#^https?://[^\s]+$#i',
            trim(
                $url
            )
        );
    }


    /**
     * Pinterest traffic must carry src=pin through the
     * stable REX destination. Replace an existing src value
     * rather than stacking duplicate source parameters.
     */
    private function withSourceTag(
        string $url
    ): string {
        $url =
            trim(
                $url
            );


        if ($url === '') {
            return '';
        }


        $fragment =
            '';

        $hashPosition =
            strpos(
                $url,
                '#'
            );


        if ($hashPosition !== false) {
            $fragment =
                substr(
                    $url,
                    $hashPosition
                );

            $url =
                substr(
                    $url,
                    0,
                    $hashPosition
                );
        }


        $pattern =
            '/([?&])'
            . preg_quote(
                self::SOURCE_TAG_KEY,
                '/'
            )
            . '=[^&]*/i';


        if (preg_match($pattern, $url)) {
            $replaced =
                preg_replace(
                    $pattern,
                    '$1'
                    . self::SOURCE_TAG_KEY
                    . '='
                    . rawurlencode(
                        self::SOURCE_TAG_VALUE
                    ),
                    $url,
                    1
                );

            return
                (string)$replaced
                . $fragment;
        }


        $separator =
            str_contains(
                $url,
                '?'
            )
                ? '&'
                : '?';


        return
            $url
            . $separator
            . self::SOURCE_TAG_KEY
            . '='
            . rawurlencode(
                self::SOURCE_TAG_VALUE
            )
            . $fragment;
    }
}
