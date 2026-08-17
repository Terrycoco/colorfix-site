<?php
declare(strict_types=1);

namespace App\PUB\Schedule;

/**
 * PUB SCHEDULER
 *
 * Chooses which QueuedAsset should publish next.
 *
 * Inputs:
 *   - queued assets
 *   - publication history
 *   - SchedulingRules
 *
 * Responsibilities:
 *   - mix playlists / Destination Objects
 *   - mix formats
 *   - avoid repeating the same transformation too closely
 *   - enforce channel cadence
 *   - select the next eligible asset
 *
 * Scheduler does NOT publish.
 */
final class Scheduler
{
}