<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * PINTEREST YOUTUBE TEASER CREATOR
 *
 * Owns ONLY the rendering/creation rules for this format.
 *
 * Input:
 *   approved AnalysisProposal
 *
 * Output:
 *   CreatedAsset
 *
 * This file may evolve independently without affecting
 * other Pinterest formats.
 *
 * Must NOT:
 *   - decide publication destination
 *   - queue
 *   - schedule
 *   - publish
 *
 * CURRENT STATUS:
 *
 *   This Creator has not been implemented yet.
 *
 * It is onboarded to PubCom so the Create Manager
 * can recognize that this production station exists
 * but is not yet available for work.
 */
final class YoutubeTeaserCreator implements PubComWorkerContract
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
            'youtube_teaser_creator_not_implemented',
            'YouTube Teaser Creator is not implemented yet.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM PREFLIGHT
     *
     * No assignment-specific CREATE rules have been
     * implemented yet.
     */
    public function preflight(
        array $input
    ): PubComSignal {
        return PubComSignal::unavailable(
            'youtube_teaser_creator_not_implemented',
            'YouTube Teaser Creator is not implemented yet.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }
}