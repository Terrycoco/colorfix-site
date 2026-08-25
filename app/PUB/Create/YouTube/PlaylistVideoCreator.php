<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

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
 *
 * CURRENT STATUS:
 *
 *   The YouTube Playlist Video production recipe
 *   has not been implemented yet.
 *
 * It is onboarded to PubCom so CREATE can recognize
 * the worker without treating it as production-ready.
 *
 * The actual YouTube production method will be
 * defined when the YouTube CREATE contract is built.
 */
final class PlaylistVideoCreator implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;


    /**
     * PUBCOM ONBOARDING
     */
    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * PUBCOM READINESS
     *
     * The worker exists, but its production recipe
     * has not yet been implemented.
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::unavailable(
            'youtube_playlist_video_creator_not_implemented',
            'YouTube Playlist Video Creator is not implemented yet.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM PREFLIGHT
     *
     * No YouTube assignment-level CREATE rules have
     * been defined yet.
     *
     * Do not invent eligibility rules until the
     * YouTube CREATE contract is implemented.
     */
    public function preflight(
        array $input
    ): PubComSignal {
        return PubComSignal::unavailable(
            'youtube_playlist_video_creator_not_implemented',
            'YouTube Playlist Video Creator is not implemented yet.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }
}