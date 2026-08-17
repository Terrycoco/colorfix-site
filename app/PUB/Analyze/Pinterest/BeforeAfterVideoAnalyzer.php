<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

/**
 * PINTEREST BEFORE/AFTER VIDEO ANALYZER
 *
 * Owns ONLY the eligibility/proposal rules for the
 * Pinterest Before/After Video format.
 *
 * Shared Before -> After pairing is provided by
 * TransformationPairFinder.
 *
 * Input:
 *   Pinterest-eligible playlist items.
 *
 * Output:
 *   Before/After Video AnalysisProposal-shaped arrays.
 *
 * One valid transformation pair produces one possible video.
 *
 * IMPORTANT:
 * This analyzer does NOT decide what the video looks like.
 *
 * Timing, transitions, labels, logo treatment, looping,
 * aspect ratio, and animation belong exclusively to:
 *
 *   App\PUB\Create\Pinterest\BeforeAfterVideoCreator
 *
 * Must NOT:
 *   - define transformation pairing rules
 *   - render video
 *   - decide visual treatment
 *   - package URLs
 *   - queue
 *   - publish
 */
final class BeforeAfterVideoAnalyzer
{
    public function __construct(
        private ?TransformationPairFinder $pairFinder = null
    ) {}

    public function analyze(array $pinItems): array
    {
        $pairFinder = $this->pairFinder ?? new TransformationPairFinder();

        $transformationPairs = $pairFinder->find($pinItems);

        $proposals = [];

        foreach ($transformationPairs as $pair) {
            $beforeId = (int)($pair['before_playlist_item_id'] ?? 0);
            $afterId = (int)($pair['after_playlist_item_id'] ?? 0);

            $before = is_array($pair['before_item'] ?? null)
                ? $pair['before_item']
                : null;

            $after = is_array($pair['after_item'] ?? null)
                ? $pair['after_item']
                : null;

            if (
                $beforeId <= 0
                || $afterId <= 0
                || !$before
                || !$after
            ) {
                continue;
            }

            $proposals[] = [
                'proposal_key' => "before-after-video-{$beforeId}-{$afterId}",

                'asset_type' => 'pin_before_after_video',
                'pin_type' => 'before_after_video',

                'before_playlist_item_id' => $beforeId,
                'after_playlist_item_id' => $afterId,

                'before' => $this->sourceItemPayload($before),
                'after' => $this->sourceItemPayload($after),
            ];
        }

        return [
            'proposals' => $proposals,
        ];
    }

    /**
     * Shapes the authored playlist item into the source
     * payload needed later by the Video Creator.
     */
    private function sourceItemPayload(array $item): array
    {
        return [
            'playlist_item_id' =>
                (int)($item['playlist_item_id'] ?? 0),

            'order_index' =>
                (float)($item['order_index'] ?? 0),

            'photo_library_id' =>
                isset($item['photo_library_id'])
                && $item['photo_library_id'] !== null
                    ? (int)$item['photo_library_id']
                    : null,

            'saved_palette_set_id' =>
                isset($item['saved_palette_set_id'])
                && $item['saved_palette_set_id'] !== null
                    ? (int)$item['saved_palette_set_id']
                    : null,

            'ap_id' =>
                isset($item['ap_id'])
                && $item['ap_id'] !== null
                    ? (int)$item['ap_id']
                    : null,

            'palette_hash' =>
                trim((string)($item['palette_hash'] ?? '')) ?: null,

            'image_url' =>
                (string)($item['image_url'] ?? ''),

            'title' =>
                (string)($item['title'] ?? ''),

            'subtitle' =>
                (string)($item['subtitle'] ?? ''),

            'item_type' =>
                (string)($item['item_type'] ?? ''),

            'pin_role' =>
                strtolower(
                    trim((string)($item['analyzer_role'] ?? 'ignore'))
                ),
        ];
    }
}