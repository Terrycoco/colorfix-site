<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * PACKAGE STAGE CONTRACT
 *
 * Takes a CreatedAsset and prepares everything needed
 * for channel publication.
 *
 * This is where an asset becomes associated with:
 *   - REX destination
 *   - src
 *   - title
 *   - description
 *   - channel-specific metadata
 *
 * Output:
 *   PublicationPackage
 *
 * Must NOT decide when publication happens.
 */
interface PackagerInterface
{
}