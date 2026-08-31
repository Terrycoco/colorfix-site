<?php
declare(strict_types=1);

namespace App\PUB\DTO;

/**
 * FINAL PUB OBJECT
 *
 * Represents an asset that has actually been published
 * to an external channel.
 *
 * This is permanent historical data.
 *
 * Typical information will include:
 *   - channel
 *   - format
 *   - external ID
 *   - external URL
 *   - published_at
 *   - publication status
 *   - originating package
 *   - originating destination/source context
 *
 * PublishedAsset records feed:
 *   - PUB Published admin
 *   - Scheduler history
 *   - later analytics/reporting
 */
final class PublishedAsset
{
}