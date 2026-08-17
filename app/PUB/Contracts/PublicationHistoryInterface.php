<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * PUBLICATION HISTORY CONTRACT
 *
 * Provides the Scheduler with authoritative information
 * about what PUB has already published.
 *
 * History answers questions such as:
 *   - What was published last?
 *   - Which formats were published recently?
 *   - Which playlist/source was published recently?
 *   - When was a particular asset last published?
 *   - What has been published to a particular channel?
 *
 * History is factual.
 * It records/retrieves publication events.
 *
 * History does NOT decide what should publish next.
 * That decision belongs exclusively to the Scheduler.
 */
interface PublicationHistoryInterface
{
}