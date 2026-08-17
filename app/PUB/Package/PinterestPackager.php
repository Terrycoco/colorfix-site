<?php
declare(strict_types=1);

namespace App\PUB\Package;

/**
 * PINTEREST PACKAGER
 *
 * Converts a CreatedAsset into a Pinterest-ready PublicationPackage.
 *
 * Responsibilities:
 *   - attach the correct REX destination
 *   - attach src=pin
 *   - title
 *   - description
 *   - board / Pinterest metadata
 *   - any Pinterest-specific publication fields
 *
 * Input:
 *   CreatedAsset
 *
 * Output:
 *   PublicationPackage
 *
 * Must NOT:
 *   - render the asset
 *   - choose publication timing
 *   - publish to Pinterest
 */
final class PinterestPackager
{
}