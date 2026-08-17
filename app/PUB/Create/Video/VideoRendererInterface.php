<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

/**
 * VIDEO RENDERER CONTRACT
 *
 * Converts a format-specific render plan into a physical video file.
 *
 * The renderer does NOT decide what the video should look like.
 * It only knows how to execute a render request.
 *
 * Input:
 *   - composition key
 *   - props / render plan
 *   - desired output path
 *
 * Output:
 *   - rendered file information
 *
 * Implementations may be:
 *   - local Remotion
 *   - server-side Remotion
 *   - another renderer in the future
 *
 * Format Creators depend on this interface, not on Remotion directly.
 */
interface VideoRendererInterface
{
}