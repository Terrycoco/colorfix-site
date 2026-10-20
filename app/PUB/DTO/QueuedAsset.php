<?php
declare(strict_types=1);

namespace App\PUB\DTO;

/**
 * CONNECTOR: QUEUE -> SCHEDULER -> PUBLISHER
 *
 * Represents a PublicationPackage waiting in the publishing hopper.
 *
 * IMPORTANT:
 * A QueuedAsset is NOT scheduled for a future date/time.
 *
 * The Scheduler decides dynamically whether and when
 * this asset should be selected for publication.
 */
final class QueuedAsset
{
}