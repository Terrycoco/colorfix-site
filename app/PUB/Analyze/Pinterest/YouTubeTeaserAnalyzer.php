<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

/**
 * PINTEREST YOUTUBE TEASER ANALYZER
 *
 * Owns ONLY the eligibility/proposal rules for the
 * Pinterest YouTube Teaser format.
 *
 * Shared Before -> After pairing is provided by
 * TransformationPairFinder.
 *
 * One transformation pair produces one possible teaser.
 *
 * IMPORTANT:
 * The published teaser visually reveals ONLY the Before.
 *
 * The After is carried as context so CREATE/PACKAGE can:
 *   - identify the transformation being promoted
 *   - generate transformation-specific teaser copy
 *   - associate the teaser with the correct YouTube content
 *
 * The actual YouTube destination is NOT decided here.
 * That belongs to PACKAGE.
 *
 * Input:
 *   Pinterest-eligible playlist items.
 *
 * Output:
 *   YouTube Teaser AnalysisProposal-shaped arrays.
 *
 * Must NOT:
 *   - define transformation pairing rules
 *   - render the teaser
 *   - reveal the After visually
 *   - choose the YouTube URL
 *   - package
 *   - queue
 *   - publish
 */
final class YouTubeTeaserAnalyzer
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
                'proposal_key' =>
                    "youtube-teaser-{$beforeId}-{$afterId}",

                'asset_type' => 'pin_youtube_teaser',
                'pin_type' => 'youtube_teaser',

                'before_playlist_item_id' => $beforeId,
                'after_playlist_item_id' => $afterId,

                /*
                 * BEFORE is the visual source.
                 */
                'before' => $this->sourceItemPayload($before),

                /*
                 * AFTER is context only.
                 * The teaser creator must not visually reveal it.
                 */
                'after_context' => $this->sourceItemPayload($after),
            ];
        }

        return [
            'proposals' => $proposals,
        ];
    }

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