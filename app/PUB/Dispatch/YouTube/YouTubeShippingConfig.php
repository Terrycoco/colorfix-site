<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\YouTube;

use RuntimeException;

/**
 * YOUTUBE SHIPPING CONFIG
 *
 * Shipping-station configuration only.
 * OAuth credentials and token refresh live in Dispatch/Auth.
 */
final class YouTubeShippingConfig
{
    public const VIDEOS_UPLOAD_URL =
        'https://www.googleapis.com/upload/youtube/v3/videos';

    public const THUMBNAILS_SET_URL =
        'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    public const REQUEST_TIMEOUT_SECONDS = 30;
    public const VIDEO_UPLOAD_TIMEOUT_SECONDS = 300;

    public const MAX_VIDEO_BYTES = 274877906944; // 256 GB
    public const MAX_THUMBNAIL_BYTES = 2097152;  // 2 MB

    public const VIDEO_MIME = 'video/mp4';
    public const THUMBNAIL_MIME = 'image/jpeg';

    /*
     * Publication policy belongs to Shipping, not the sealed package.
     * Keep private while the Google/YouTube API project is unaudited.
     * Change this one value to public after audit/production readiness.
     */
    public const VIDEO_PRIVACY_STATUS = 'private';

    public const RESUME_MAX_ATTEMPTS = 4;
    public const THUMBNAIL_MAX_ATTEMPTS = 3;

    public function videoPrivacyStatus(): string
    {
        $status = strtolower(
            trim(
                self::VIDEO_PRIVACY_STATUS
            )
        );

        if (!in_array(
            $status,
            [
                'private',
                'unlisted',
                'public',
            ],
            true
        )) {
            throw new RuntimeException(
                'YouTube shipping privacy status must be private, unlisted, or public.'
            );
        }

        return $status;
    }

    public function resumableVideoUrl(): string
    {
        return self::VIDEOS_UPLOAD_URL
            . '?'
            . http_build_query(
                [
                    'uploadType' =>
                        'resumable',

                    'part' =>
                        'snippet,status',
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }

    public function thumbnailUrl(
        string $videoId
    ): string {
        $videoId =
            trim(
                $videoId
            );

        if ($videoId === '') {
            throw new RuntimeException(
                'YouTube thumbnail upload requires a video ID.'
            );
        }

        return self::THUMBNAILS_SET_URL
            . '?'
            . http_build_query(
                [
                    'videoId' =>
                        $videoId,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }
}
