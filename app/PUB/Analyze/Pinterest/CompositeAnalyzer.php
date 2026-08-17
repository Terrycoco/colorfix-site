<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

/**
 * PINTEREST COMPOSITE ANALYZER
 *
 * Owns ONLY the eligibility/proposal rules for the
 * Pinterest Composite format.
 *
 * Shared Before -> After pairing is provided by
 * TransformationPairFinder.
 *
 * Input:
 *   Pinterest-eligible playlist items.
 *
 * Output:
 *   Composite AnalysisProposal-shaped arrays.
 *
 * Must NOT:
 *   - define transformation pairing rules
 *   - load playlists from the database
 *   - render files
 *   - package URLs
 *   - queue
 *   - publish
 */
final class CompositeAnalyzer
{
    public function __construct(
        private ?TransformationPairFinder $pairFinder = null
    ) {}

    public function analyze(array $pinItems): array
    {
        $pairFinder = $this->pairFinder ?? new TransformationPairFinder();

        $transformationPairs = $pairFinder->find($pinItems);

        $pairs = [];
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

            /*
             * Pair data is still returned because the coordinator/UI
             * currently exposes it for debug/summary purposes.
             */
            $pairs[] = [
                'before_playlist_item_id' => $beforeId,
                'after_playlist_item_id' => $afterId,
            ];

            /*
             * Composite-specific proposal.
             */
            $proposals[] = [
                'proposal_key' => "composite-{$beforeId}-{$afterId}",
                'asset_type' => 'pin_composite',
                'pin_type' => 'composite',

                'before_playlist_item_id' => $beforeId,
                'after_playlist_item_id' => $afterId,

                'before' => $this->sourceItemPayload($before),
                'after' => $this->sourceItemPayload($after),
            ];
        }

        return [
            'pairs' => $pairs,
            'proposals' => $proposals,
        ];
    }

    /**
     * Composite-specific shaping of an authored playlist item
     * into the source payload needed by the CREATE stage.
     *
     * This is NOT transformation-pairing logic.
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