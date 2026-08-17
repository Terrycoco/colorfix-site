<?php
declare(strict_types=1);

namespace App\PUB\Publish;

/**
 * YOUTUBE PUBLISHER
 *
 * Sends one Scheduler-selected QueuedAsset to YouTube.
 *
 * Owns:
 *   - YouTube authentication
 *   - upload API requests
 *   - YouTube responses/errors
 *   - resulting external video ID / URL
 *
 * Output:
 *   PublishedAsset
 */
final class YouTubePublisher
{
}