<?php
declare(strict_types=1);

namespace App\PUB\Queue;

/**
 * QUEUE PERSISTENCE
 *
 * Owns database access for queued PUB assets.
 *
 * Implements the QueueRepositoryInterface once the
 * contract methods are defined.
 *
 * Must contain persistence only.
 * Scheduling decisions do NOT belong here.
 */
final class PdoQueueRepository
{
}