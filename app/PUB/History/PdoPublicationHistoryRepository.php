<?php
declare(strict_types=1);

namespace App\PUB\History;

/**
 * PUBLICATION HISTORY PERSISTENCE
 *
 * Provides factual read access to what PUB has published.
 *
 * Scheduler uses this data to make future decisions.
 *
 * Typical queries will include:
 *   - most recent publications for a channel
 *   - last publication from a playlist
 *   - last publication of a format
 *   - last publication of a source transformation
 *
 * This class does NOT make scheduling decisions.
 */
final class PdoPublicationHistoryRepository
{
}