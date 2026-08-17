<?php
declare(strict_types=1);

namespace App\PUB\Package;

/**
 * YOUTUBE PACKAGER
 *
 * Converts a CreatedAsset into a YouTube-ready PublicationPackage.
 *
 * Responsibilities:
 *   - attach the correct REX / destination context where needed
 *   - title
 *   - description
 *   - YouTube-specific metadata
 *   - thumbnail / playlist metadata where applicable
 *
 * Input:
 *   CreatedAsset
 *
 * Output:
 *   PublicationPackage
 *
 * Must NOT:
 *   - render the video
 *   - choose publication timing
 *   - publish to YouTube
 */
final class YouTubePackager
{
}