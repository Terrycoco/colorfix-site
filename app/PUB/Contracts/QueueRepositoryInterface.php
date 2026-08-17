<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * QUEUE CONTRACT
 *
 * Stores PublicationPackages that are ready to publish.
 *
 * A queued asset has NO predetermined publication date/time.
 *
 * The Queue owns readiness/state only.
 * It does NOT decide when or in what order assets publish.
 *
 * Responsibilities:
 *   - add a PublicationPackage to the queue
 *   - retrieve queued assets
 *   - mark queue state changes
 *   - remove/disable assets when appropriate
 *
 * The Scheduler consumes this queue.
 */
interface QueueRepositoryInterface
{
}