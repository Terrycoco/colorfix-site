<?php
declare(strict_types=1);

namespace App\PUB\Queue;

/**
 * QUEUE SERVICE
 *
 * Places completed PublicationPackages into the publishing hopper.
 *
 * Important:
 *   Queueing does NOT assign a future publish time.
 *
 * Responsibilities:
 *   - accept ready PublicationPackages
 *   - create QueuedAsset records
 *   - prevent inappropriate duplicate queue entries
 *
 * Scheduler later chooses what publishes next.
 */
final class QueueService
{
}