<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * PUBLISH STAGE CONTRACT
 *
 * Sends the Scheduler-selected QueuedAsset
 * to an external publishing channel.
 *
 * Channel implementations own:
 *   - authentication
 *   - API requests
 *   - external IDs
 *   - external URLs
 *   - channel-specific failures
 *
 * Output:
 *   PublishedAsset
 */
interface PublisherInterface
{
}