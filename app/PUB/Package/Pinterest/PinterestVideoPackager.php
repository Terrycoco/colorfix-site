<?php
declare(strict_types=1);

namespace App\PUB\Package\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use RuntimeException;

/**
 * PINTEREST VIDEO PACKAGER
 *
 * Packing specialist for finished Pinterest video assets.
 *
 * CREATE has already produced:
 *   - the permanent MP4
 *   - the companion thumbnail JPEG
 *   - per-asset title/description/pingback metadata
 *
 * Shipping will later:
 *   1. register media with Pinterest
 *   2. upload the MP4 bytes
 *   3. receive/poll the Pinterest media_id
 *   4. create the Pin using that media_id + this package's cover image URL
 *
 * PACKAGE SHAPE:
 *
 *   title
 *   description
 *   link
 *   video_file_path
 *   media_source.source_type = video_id
 *   media_source.cover_image_url
 *
 * media_id is deliberately NOT part of PACKAGE. It does not exist until
 * Shipping talks to Pinterest.
 *
 * This Packager DOES:
 *   - verify this is a Pinterest video asset
 *   - verify the finished MP4 exists
 *   - verify the companion thumbnail exists
 *   - verify required per-asset metadata is present
 *   - resolve the thumbnail URL to an absolute public URL
 *   - attach src=pin to the outbound REX destination
 *   - seal the variable information Shipping will need
 *
 * This Packager DOES NOT:
 *   - render or alter the video/thumbnail
 *   - authenticate with Pinterest
 *   - choose a Pinterest board
 *   - register/upload media
 *   - invent a media_id
 *   - call the Pinterest API
 *   - persist pub_assets.package
 */
final class PinterestVideoPackager implements PubComWorkerContract
{
    private const CHANNEL =
        'pinterest';

    private const MEDIA_SOURCE_TYPE =
        'video_id';

    private const SOURCE_TAG_KEY =
        'src';

    private const SOURCE_TAG_VALUE =
        'pin';

    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private string $publicBaseUrl,
    ) {
        $this->publicBaseUrl =
            rtrim(
                trim(
                    $this->publicBaseUrl
                ),
                '/'
            );
    }


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * Packaging itself has no Pinterest service dependency.
     * Authentication and network availability belong to Shipping.
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Pinterest Video Packager is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


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
                'pinterest_video_wrong_channel',
                'Pinterest Video Packager only accepts Pinterest assets.',
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
                'video/'
            )
        ) {
            return PubComSignal::ineligible(
                'pinterest_video_wrong_media_type',
                'Pinterest Video Packager only accepts video assets.',
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
                'pinterest_video_file_path_pending',
                'Waiting for finished Pinterest video file.',
                'file_path'
            );
        }


        if (!is_file($filePath)) {
            return $this->pending(
                'pinterest_video_file_pending',
                'Waiting for finished Pinterest video file to be available.',
                'file_path',
                [
                    'file_path' =>
                        $filePath,
                ]
            );
        }


        $thumbnailFilePath =
            trim(
                (string)(
                    $asset[
                        'thumbnail_file_path'
                    ]
                    ?? ''
                )
            );


        if ($thumbnailFilePath === '') {
            return $this->pending(
                'pinterest_video_thumbnail_file_path_pending',
                'Waiting for Pinterest video thumbnail file.',
                'thumbnail_file_path'
            );
        }


        if (!is_file($thumbnailFilePath)) {
            return $this->pending(
                'pinterest_video_thumbnail_file_pending',
                'Waiting for Pinterest video thumbnail file to be available.',
                'thumbnail_file_path',
                [
                    'thumbnail_file_path' =>
                        $thumbnailFilePath,
                ]
            );
        }


        $thumbnailUrl =
            trim(
                (string)(
                    $asset[
                        'thumbnail_url'
                    ]
                    ?? ''
                )
            );


        if ($thumbnailUrl === '') {
            return $this->pending(
                'pinterest_video_thumbnail_url_pending',
                'Waiting for Pinterest video thumbnail public URL.',
                'thumbnail_url'
            );
        }


        $coverImageUrl =
            $this->absoluteUrl(
                $thumbnailUrl
            );


        if (!$this->isAbsoluteHttpUrl($coverImageUrl)) {
            return PubComSignal::ineligible(
                'pinterest_video_thumbnail_url_invalid',
                'Pinterest video thumbnail public URL is not usable.',
                [
                    'worker' =>
                        self::class,

                    'thumbnail_url' =>
                        $thumbnailUrl,
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
                'pinterest_video_title_pending',
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
                'pinterest_video_description_pending',
                'Waiting for Pinterest description.',
                'description'
            );
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


        if ($pingback === '') {
            return $this->pending(
                'pinterest_video_destination_pending',
                'Waiting for Pinterest destination URL.',
                'pingback'
            );
        }


        $destinationUrl =
            $this->absoluteUrl(
                $pingback
            );


        if (!$this->isAbsoluteHttpUrl($destinationUrl)) {
            return PubComSignal::ineligible(
                'pinterest_video_destination_invalid',
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
            'Pinterest video asset is ready to pack.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Seal one finished Pinterest video for Shipping.
     *
     * The local MP4 path is package data because Pinterest video shipping
     * uploads the actual bytes. The companion thumbnail is public URL data
     * because Pinterest's final Pin create call references cover_image_url.
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

        $videoFilePath =
            trim(
                (string)$asset[
                    'file_path'
                ]
            );

        $coverImageUrl =
            $this->absoluteUrl(
                (string)$asset[
                    'thumbnail_url'
                ]
            );

        $destinationUrl =
            $this->withSourceTag(
                $this->absoluteUrl(
                    (string)$asset[
                        'pingback'
                    ]
                )
            );


        return [
            'title' =>
                $title,

            'description' =>
                $description,

            'link' =>
                $destinationUrl,

            'video_file_path' =>
                $videoFilePath,

            'media_source' => [
                'source_type' =>
                    self::MEDIA_SOURCE_TYPE,

                'cover_image_url' =>
                    $coverImageUrl,
            ],
        ];
    }


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
                'Pinterest Video Packager received a non-Pinterest asset.'
            );
        }


        if (
            $mimeType === ''
            ||
            !str_starts_with(
                $mimeType,
                'video/'
            )
        ) {
            throw new RuntimeException(
                'Pinterest Video Packager received a non-video asset.'
            );
        }


        foreach (
            [
                'file_path',
                'thumbnail_file_path',
                'thumbnail_url',
                'search_title',
                'description',
                'pingback',
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
                    "Pinterest Video Packager cannot pack without {$field}."
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
                "Pinterest Video Packager cannot find finished video file: {$filePath}"
            );
        }


        $thumbnailFilePath =
            trim(
                (string)$asset[
                    'thumbnail_file_path'
                ]
            );


        if (!is_file($thumbnailFilePath)) {
            throw new RuntimeException(
                "Pinterest Video Packager cannot find thumbnail file: {$thumbnailFilePath}"
            );
        }


        $coverImageUrl =
            $this->absoluteUrl(
                (string)$asset[
                    'thumbnail_url'
                ]
            );

        $destinationUrl =
            $this->absoluteUrl(
                (string)$asset[
                    'pingback'
                ]
            );


        if (!$this->isAbsoluteHttpUrl($coverImageUrl)) {
            throw new RuntimeException(
                'Pinterest Video Packager cannot resolve a valid public thumbnail URL.'
            );
        }


        if (!$this->isAbsoluteHttpUrl($destinationUrl)) {
            throw new RuntimeException(
                'Pinterest Video Packager cannot resolve a valid destination URL.'
            );
        }
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
     * Pinterest traffic must carry src=pin through the stable REX URL.
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
