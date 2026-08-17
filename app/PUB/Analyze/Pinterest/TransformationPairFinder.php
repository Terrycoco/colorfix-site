<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

/**
 * PINTEREST TRANSFORMATION PAIR FINDER
 *
 * Shared Pinterest analysis helper.
 *
 * Defines ONE canonical rule for finding authored
 * Before -> After transformation pairs.
 *
 * Used by any Pinterest format that depends on the same
 * transformation relationship, including:
 *
 *   - CompositeAnalyzer
 *   - BeforeAfterVideoAnalyzer
 *
 * Pairing rule:
 *
 *   1. Find an authored "before" item.
 *   2. Scan forward through Pinterest-eligible items.
 *   3. Collect each authored "after" item.
 *   4. Stop when another "before" or "single" begins
 *      a new authored group.
 *
 * Example:
 *
 *   before
 *   after
 *   after
 *   after
 *
 * produces three transformation pairs.
 *
 * This class owns pairing logic ONLY.
 *
 * Must NOT:
 *   - decide what format should be created
 *   - build AnalysisProposals
 *   - render assets
 *   - package
 *   - queue
 *   - publish
 */
final class TransformationPairFinder
{
    public function find(array $pinItems): array
    {
        $pairs = [];

        foreach ($pinItems as $before) {
            if ($this->role($before) !== 'before') {
                continue;
            }

            foreach ($this->followingAfterItems($pinItems, $before) as $after) {
                $beforeId = (int)($before['playlist_item_id'] ?? 0);
                $afterId = (int)($after['playlist_item_id'] ?? 0);

                if ($beforeId <= 0 || $afterId <= 0) {
                    continue;
                }

                $pairs[] = [
                    'before_playlist_item_id' => $beforeId,
                    'after_playlist_item_id' => $afterId,

                    /*
                     * Carry the actual authored items too.
                     *
                     * Format analyzers can shape these however
                     * they need without re-querying or re-pairing.
                     */
                    'before_item' => $before,
                    'after_item' => $after,
                ];
            }
        }

        return $pairs;
    }

    private function followingAfterItems(
        array $items,
        array $before
    ): array {
        $afterItems = [];

        $beforeOrder = (float)($before['order_index'] ?? -1);
        $beforeId = (int)($before['playlist_item_id'] ?? 0);

        foreach ($items as $item) {
            $order = (float)($item['order_index'] ?? -1);
            $id = (int)($item['playlist_item_id'] ?? 0);

            /*
             * Skip the Before itself and anything before it.
             */
            if (
                $order < $beforeOrder
                || ($order === $beforeOrder && $id <= $beforeId)
            ) {
                continue;
            }

            $role = $this->role($item);

            /*
             * Another Before or Single starts a new authored group.
             */
            if ($role === 'before' || $role === 'single') {
                break;
            }

            if ($role === 'after') {
                $afterItems[] = $item;
            }
        }

        return $afterItems;
    }

    private function role(array $item): string
    {
        return strtolower(
            trim((string)($item['analyzer_role'] ?? 'ignore'))
        );
    }
}