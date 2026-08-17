<?php
declare(strict_types=1);

namespace App\PUB\Schedule;

/**
 * SCHEDULER POLICY
 *
 * Contains configurable rules used by Scheduler.
 *
 * Examples:
 *   - minimum spacing between items from the same playlist
 *   - avoid repeating the same format consecutively
 *   - avoid repeating the same transformation
 *   - channel cadence
 *   - priority / weighting rules
 *
 * Keeping policy separate allows scheduling behavior
 * to change without rewriting Scheduler itself.
 */
final class SchedulingRules
{
}