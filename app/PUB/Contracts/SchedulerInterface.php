<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * SCHEDULER CONTRACT
 *
 * Decides WHICH queued asset should publish NEXT.
 *
 * Input:
 *   - available QueuedAssets
 *   - publication History
 *
 * The Scheduler may consider:
 *   - channel
 *   - format
 *   - source playlist/content
 *   - recent publication history
 *   - spacing/repetition rules
 *   - channel cadence
 *
 * Output:
 *   one selected QueuedAsset, or none
 *
 * IMPORTANT:
 * Assets are not assigned publication times when queued.
 * The Scheduler makes the publishing decision at runtime.
 *
 * The Scheduler does NOT create assets and does NOT
 * communicate with external publishing APIs.
 */
interface SchedulerInterface
{
}
