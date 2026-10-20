<?php
declare(strict_types=1);

namespace App\PUB\DTO;

/**
 * CONNECTOR: PACKAGE -> QUEUE
 *
 * Represents a fully prepared publishing package.
 *
 * It contains everything Publisher will eventually need:
 *   - created asset
 *   - channel
 *   - format
 *   - REX destination
 *   - src
 *   - title
 *   - description
 *   - channel-specific metadata
 *
 * It is ready to enter the queue,
 * but no publication time has been assigned.
 */
final class PublicationPackage
{
}