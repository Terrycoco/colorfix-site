<?php
declare(strict_types=1);

namespace App\PUB\Package\YouTube;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use RuntimeException;

/**
 * YOUTUBE VIDEO PACKAGER
 *
 * Packing specialist for finished YouTube video assets.
 *
 * CREATE has already produced and persisted on pub_assets:
 *   - the permanent MP4
 *   - the authored companion thumbnail JPEG
 *   - title / description metadata
 *
 * PACKAGE SHAPE:
 *
 *   title
 *   description
 *   video_file_path
 *   thumbnail_file_path
 *
 * Shipping policy such as privacy status, API endpoints, OAuth credentials,
 * retry rules, and resumable-upload behavior belongs to Dispatch/YouTube.
 */
final class YouTubeVideoPackager implements PubComWorkerContract
{
    private const CHANNEL =
        'youtube';

    private const MAX_VIDEO_BYTES =
        256 * 1024 * 1024 * 1024;

    private const MAX_THUMBNAIL_BYTES =
        2 * 1024 * 1024;

    private ?PubComChannel $pubComChannel = null;


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * Packaging has no external YouTube dependency.
     * Authentication and network availability belong to Shipping.
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'YouTube Video Packager is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Verify that CREATE has supplied the complete durable outbound set.
     *
     * Missing-but-expected pieces are PENDING. A physically unusable output
     * is INELIGIBLE and should not be sealed for Shipping.
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
                'youtube_video_wrong_channel',
                'YouTube Video Packager only accepts YouTube assets.',
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
            || !str_starts_with(
                $mimeType,
                'video/'
            )
        ) {
            return PubComSignal::ineligible(
                'youtube_video_wrong_media_type',
                'YouTube Video Packager only accepts video assets.',
                [
                    'worker' =>
                        self::class,

                    'mime_type' =>
                        $mimeType,
                ]
            );
        }


        $videoFile =
            trim(
                (string)(
                    $asset[
                        'file_path'
                    ]
                    ?? ''
                )
            );


        if ($videoFile === '') {
            return $this->pending(
                'youtube_video_file_path_pending',
                'Waiting for finished YouTube video file.',
                'file_path'
            );
        }


        if (
            !is_file(
                $videoFile
            )
            || !is_readable(
                $videoFile
            )
        ) {
            return $this->pending(
                'youtube_video_file_pending',
                'Waiting for finished YouTube video file to be available.',
                'file_path',
                [
                    'file_path' =>
                        $videoFile,
                ]
            );
        }


        $videoBytes =
            filesize(
                $videoFile
            );


        if (
            $videoBytes === false
            || $videoBytes <= 0
        ) {
            return PubComSignal::ineligible(
                'youtube_video_file_invalid',
                'Finished YouTube video file is empty or unreadable.',
                [
                    'worker' =>
                        self::class,

                    'file_path' =>
                        $videoFile,
                ]
            );
        }


        if ($videoBytes > self::MAX_VIDEO_BYTES) {
            return PubComSignal::ineligible(
                'youtube_video_file_too_large',
                'Finished YouTube video exceeds YouTube\'s 256 GB upload limit.',
                [
                    'worker' =>
                        self::class,

                    'file_size_bytes' =>
                        $videoBytes,
                ]
            );
        }


        $thumbnailFile =
            trim(
                (string)(
                    $asset[
                        'thumbnail_file_path'
                    ]
                    ?? ''
                )
            );


        if ($thumbnailFile === '') {
            return $this->pending(
                'youtube_video_thumbnail_file_path_pending',
                'Waiting for authored YouTube thumbnail file.',
                'thumbnail_file_path'
            );
        }


        if (
            !is_file(
                $thumbnailFile
            )
            || !is_readable(
                $thumbnailFile
            )
        ) {
            return $this->pending(
                'youtube_video_thumbnail_file_pending',
                'Waiting for authored YouTube thumbnail file to be available.',
                'thumbnail_file_path',
                [
                    'thumbnail_file_path' =>
                        $thumbnailFile,
                ]
            );
        }


        $thumbnailBytes =
            filesize(
                $thumbnailFile
            );


        if (
            $thumbnailBytes === false
            || $thumbnailBytes <= 0
        ) {
            return PubComSignal::ineligible(
                'youtube_video_thumbnail_invalid',
                'Authored YouTube thumbnail is empty or unreadable.',
                [
                    'worker' =>
                        self::class,

                    'thumbnail_file_path' =>
                        $thumbnailFile,
                ]
            );
        }


        if ($thumbnailBytes > self::MAX_THUMBNAIL_BYTES) {
            return PubComSignal::ineligible(
                'youtube_video_thumbnail_too_large',
                'Authored YouTube thumbnail exceeds YouTube\'s 2 MB upload limit.',
                [
                    'worker' =>
                        self::class,

                    'thumbnail_file_size_bytes' =>
                        $thumbnailBytes,
                ]
            );
        }


        $imageInfo =
            @getimagesize(
                $thumbnailFile
            );


        if (
            !is_array(
                $imageInfo
            )
            || (
                $imageInfo[
                    2
                ]
                ?? null
            ) !== IMAGETYPE_JPEG
        ) {
            return PubComSignal::ineligible(
                'youtube_video_thumbnail_not_jpeg',
                'Authored YouTube thumbnail must be a JPEG.',
                [
                    'worker' =>
                        self::class,

                    'thumbnail_file_path' =>
                        $thumbnailFile,
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
                'youtube_video_title_pending',
                'Waiting for YouTube title.',
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
                'youtube_video_description_pending',
                'Waiting for YouTube description.',
                'description'
            );
        }


        return PubComSignal::ready(
            'YouTube video asset is ready to pack.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Seal exactly the variable content YouTube Shipping consumes.
     *
     * @return array<string, mixed>
     */
    public function pack(
        array $asset
    ): array {
        $this->assertPackable(
            $asset
        );


        return [
            'title' =>
                trim(
                    (string)$asset[
                        'search_title'
                    ]
                ),

            'description' =>
                trim(
                    (string)$asset[
                        'description'
                    ]
                ),

            'video_file_path' =>
                trim(
                    (string)$asset[
                        'file_path'
                    ]
                ),

            'thumbnail_file_path' =>
                trim(
                    (string)$asset[
                        'thumbnail_file_path'
                    ]
                ),
        ];
    }


    /**
     * Defensive guard for direct/misordered calls to pack().
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
                'YouTube Video Packager received a non-YouTube asset.'
            );
        }


        if (
            $mimeType === ''
            || !str_starts_with(
                $mimeType,
                'video/'
            )
        ) {
            throw new RuntimeException(
                'YouTube Video Packager received a non-video asset.'
            );
        }


        foreach (
            [
                'file_path',
                'thumbnail_file_path',
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
                    "YouTube Video Packager cannot pack without {$field}."
                );
            }
        }


        $videoFile =
            trim(
                (string)$asset[
                    'file_path'
                ]
            );


        if (
            !is_file(
                $videoFile
            )
            || !is_readable(
                $videoFile
            )
        ) {
            throw new RuntimeException(
                "YouTube Video Packager cannot read finished video file: {$videoFile}"
            );
        }


        $videoBytes =
            filesize(
                $videoFile
            );


        if (
            $videoBytes === false
            || $videoBytes <= 0
        ) {
            throw new RuntimeException(
                'YouTube Video Packager received an empty video file.'
            );
        }


        if ($videoBytes > self::MAX_VIDEO_BYTES) {
            throw new RuntimeException(
                'YouTube Video Packager received a video larger than YouTube\'s 256 GB limit.'
            );
        }


        $thumbnailFile =
            trim(
                (string)$asset[
                    'thumbnail_file_path'
                ]
            );


        if (
            !is_file(
                $thumbnailFile
            )
            || !is_readable(
                $thumbnailFile
            )
        ) {
            throw new RuntimeException(
                "YouTube Video Packager cannot read authored thumbnail file: {$thumbnailFile}"
            );
        }


        $thumbnailBytes =
            filesize(
                $thumbnailFile
            );


        if (
            $thumbnailBytes === false
            || $thumbnailBytes <= 0
        ) {
            throw new RuntimeException(
                'YouTube Video Packager received an empty thumbnail file.'
            );
        }


        if ($thumbnailBytes > self::MAX_THUMBNAIL_BYTES) {
            throw new RuntimeException(
                'YouTube Video Packager received a thumbnail larger than YouTube\'s 2 MB limit.'
            );
        }


        $imageInfo =
            @getimagesize(
                $thumbnailFile
            );


        if (
            !is_array(
                $imageInfo
            )
            || (
                $imageInfo[
                    2
                ]
                ?? null
            ) !== IMAGETYPE_JPEG
        ) {
            throw new RuntimeException(
                'YouTube Video Packager received a thumbnail that is not JPEG.'
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
}
