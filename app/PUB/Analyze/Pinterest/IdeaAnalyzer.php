<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

/**
 * PINTEREST IDEA ANALYZER
 *
 * Owns ONLY the eligibility/proposal rules for the
 * Pinterest Idea format.
 *
 * Input:
 *   Pinterest-eligible playlist items.
 *
 * Output:
 *   Idea AnalysisProposal-shaped arrays.
 *
 * An Idea proposal is created from each authored:
 *   - "after" item
 *   - "single" item
 *
 * Must NOT:
 *   - load playlists from the database
 *   - determine palette eligibility
 *   - render files
 *   - package URLs
 *   - queue
 *   - publish
 */
final class IdeaAnalyzer
{
    public function analyze(array $pinItems): array
    {
        $proposals = [];

        foreach ($pinItems as $item) {
            $role = $this->role($item);

            if ($role !== 'after' && $role !== 'single') {
                continue;
            }

            $itemId = (int)($item['playlist_item_id'] ?? 0);

            if ($itemId <= 0) {
                continue;
            }

            $proposals[] = [
                'proposal_key' => "idea-{$itemId}",
                'asset_type' => 'pin_idea',
                'pin_type' => 'idea',
                'playlist_item_id' => $itemId,
                'source_item' => $this->sourceItemPayload($item),
            ];
        }

        return [
            'proposals' => $proposals,
        ];
    }

    private function role(array $item): string
    {
        return strtolower(
            trim((string)($item['analyzer_role'] ?? 'ignore'))
        );
    }

    private function sourceItemPayload(array $item): array
    {
        return [
            'playlist_item_id' =>
                (int)($item['playlist_item_id'] ?? 0),

            'order_index' =>
                (float)($item['order_index'] ?? 0),

            'photo_library_id' =>
                isset($item['photo_library_id']) && $item['photo_library_id'] !== null
                    ? (int)$item['photo_library_id']
                    : null,

            'saved_palette_set_id' =>
                isset($item['saved_palette_set_id']) && $item['saved_palette_set_id'] !== null
                    ? (int)$item['saved_palette_set_id']
                    : null,

            'ap_id' =>
                isset($item['ap_id']) && $item['ap_id'] !== null
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
                $this->role($item),
        ];
    }
}