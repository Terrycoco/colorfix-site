<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

/**
 * YOUTUBE PLAYLIST VIDEO CREATOR
 *
 * Owns ONLY the rendering/creation rules for the
 * full YouTube playlist video format.
 *
 * Input:
 *   approved AnalysisProposal
 *
 * Output:
 *   CreatedAsset
 *
 * Must NOT:
 *   - decide destination URLs
 *   - queue
 *   - schedule
 *   - publish
 */
final class PlaylistVideoCreator
{
}