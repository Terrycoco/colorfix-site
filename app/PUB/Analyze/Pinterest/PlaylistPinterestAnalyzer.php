<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\Repos\PdoPlaylistRepository;
use RuntimeException;

/**
 * PINTEREST PLAYLIST ANALYSIS COORDINATOR
 *
 * Entry point for analyzing a Playlist for the Pinterest channel.
 *
 * This class owns playlist-level preparation and coordination only.
 * It does NOT own the rules for individual Pinterest formats.
 *
 * Responsibilities:
 *   1. Load the Destination Object (Playlist) and its authored items.
 *   2. Restrict publishing analysis to the Public player experience.
 *      In playlist_items, "site" is the Public experience flag.
 *   3. Restrict analysis to items explicitly enabled for Pinterest.
 *   4. Build the common playlist context needed by Pinterest formats.
 *   5. Hand that context to the independent format analyzers.
 *   6. Collect their AnalysisProposals into one Pinterest analysis result.
 *   7. Return summary/debug information needed by the PUB Analyze UI.
 *
 * Current format analyzers:
 *
 *   CompositeAnalyzer
 *   IdeaAnalyzer
 *   PaletteAnalyzer
 *
 * Planned:
 *
 *   BeforeAfterVideoAnalyzer
 *   YoutubeTeaserAnalyzer
 *
 * Pipeline position:
 *
 *   Playlist Destination Object
 *            ↓
 *   PlaylistPinterestAnalyzer
 *            ↓
 *   Pinterest format analyzers
 *            ↓
 *   AnalysisProposal[]
 *            ↓
 *          CREATE
 *
 * Must NOT:
 *   - render or create Assets
 *   - contain format-specific rules
 *   - create or modify REX reservations
 *   - build final publication URLs
 *   - package Assets
 *   - queue Assets
 *   - make scheduling decisions
 *   - communicate with Pinterest
 */
final class PlaylistPinterestAnalyzer
{
    public function __construct(
        private PdoPlaylistRepository $playlists
    ) {}

    public function analyze(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new RuntimeException('Valid playlist ID required.');
        }

        $playlist = $this->playlists->getAdminRowById($playlistId);

        if ($playlist === null) {
            throw new RuntimeException('Playlist not found.');
        }

        $items = $this->playlists->getAdminItemRows($playlistId);

        /*
         * PUB publishes only from the Public player experience.
         */
        $publicItems = array_values(array_filter(
            $items,
            static fn(array $item): bool =>
                !empty($item['site'])
        ));

        /*
         * Pinterest eligibility:
         *
         * Public
         * + Pin enabled
         * + authored Pin Role is not ignore.
         */
        $pinItems = array_values(array_filter(
            $publicItems,
            static fn(array $item): bool =>
                !empty($item['pin'])
                && strtolower(
                    trim((string)($item['analyzer_role'] ?? 'ignore'))
                ) !== 'ignore'
        ));

        /*
         * Retained for UI/debug/summary only.
         */
        $pinBeforeItems = $this->itemsForRole($pinItems, 'before');
        $pinAfterItems = $this->itemsForRole($pinItems, 'after');
        $pinSingleItems = $this->itemsForRole($pinItems, 'single');

        /*
         * Independent Pinterest format analyzers.
         */
        $compositeResult = (new CompositeAnalyzer())->analyze($pinItems);
        $ideaResult = (new IdeaAnalyzer())->analyze($pinItems);
        $paletteResult = (new PaletteAnalyzer())->analyze($pinItems);
        $videoResult = (new BeforeAfterVideoAnalyzer())->analyze($pinItems);
        $teaserResult = (new YouTubeTeaserAnalyzer())->analyze($pinItems);

        $pinPairs = is_array($compositeResult['pairs'] ?? null)
            ? $compositeResult['pairs']
            : [];

        $compositeProposals = is_array($compositeResult['proposals'] ?? null)
            ? $compositeResult['proposals']
            : [];

        $ideaProposals = is_array($ideaResult['proposals'] ?? null)
            ? $ideaResult['proposals']
            : [];

        $paletteProposals = is_array($paletteResult['proposals'] ?? null)
            ? $paletteResult['proposals']
            : [];

        $videoProposals = is_array($videoResult['proposals'] ?? null)
            ? $videoResult['proposals']
            : [];

        $teaserProposals = is_array($teaserResult['proposals'] ?? null)
            ? $teaserResult['proposals']
            : [];

        /*
         * Coordinator owns combined presentation/order only.
         *
         * Preserve current behavior:
         *
         *   composites first
         *   then for each standalone source:
         *      idea
         *      idea + palette
         */
        $assetProposals = [];
        $sortOrder = 1;

        //composites
        foreach ($compositeProposals as $proposal) {
            $assetProposals[] = [
                ...$proposal,
                'sort_order' => $sortOrder++,
            ];
        }
        //beforeaftervideos
        foreach ($videoProposals as $proposal) {
            $assetProposals[] = [
                ...$proposal,
                'sort_order' => $sortOrder++,
            ];
        }
        
        //teasers
        foreach ($teaserProposals as $proposal) {
            $assetProposals[] = [
                ...$proposal,
                'sort_order' => $sortOrder++,
            ];
        }
        
        $ideaByItemId = $this->proposalsByItemId($ideaProposals);
        $paletteByItemId = $this->proposalsByItemId($paletteProposals);

        $standaloneItems = array_merge(
            $pinAfterItems,
            $pinSingleItems
        );

        foreach ($standaloneItems as $item) {
            $itemId = (int)($item['playlist_item_id'] ?? 0);

            if ($itemId <= 0) {
                continue;
            }

            if (isset($ideaByItemId[$itemId])) {
                $assetProposals[] = [
                    ...$ideaByItemId[$itemId],
                    'sort_order' => $sortOrder++,
                ];
            }

            if (isset($paletteByItemId[$itemId])) {
                $assetProposals[] = [
                    ...$paletteByItemId[$itemId],
                    'sort_order' => $sortOrder++,
                ];
            }
        }

        return [
            'source' => [
                'type' => 'playlist',
                'id' => $playlistId,
                'title' => (string)($playlist['title'] ?? ''),
            ],

            'items' => $publicItems,

            'pinterest' => [
                'items' => $pinItems,
                'before_items' => $pinBeforeItems,
                'after_items' => $pinAfterItems,
                'single_items' => $pinSingleItems,
                'pairs' => $pinPairs,
                'asset_proposals' => $assetProposals,
            ],

            'summary' => [
                'total_items' => count($items),
                'public_items' => count($publicItems),
                'pin_items' => count($pinItems),
                'pin_before_items' => count($pinBeforeItems),
                'pin_after_items' => count($pinAfterItems),
                'pin_single_items' => count($pinSingleItems),
                'pin_pairs' => count($pinPairs),

                'composite_proposals' => count($compositeProposals),
                'before_after_video_proposals' => count($videoProposals),
                'idea_proposals' => count($ideaProposals),
                'palette_proposals' => count($paletteProposals),
                'youtube_teaser_proposals' => count($teaserProposals),

                'asset_proposals' => count($assetProposals),

            ],
        ];
    }

    private function itemsForRole(
        array $items,
        string $wantedRole
    ): array {
        return array_values(array_filter(
            $items,
            static fn(array $item): bool =>
                strtolower(
                    trim((string)($item['analyzer_role'] ?? 'ignore'))
                ) === $wantedRole
        ));
    }

    private function proposalsByItemId(array $proposals): array
    {
        $byItemId = [];

        foreach ($proposals as $proposal) {
            $itemId = (int)($proposal['playlist_item_id'] ?? 0);

            if ($itemId > 0) {
                $byItemId[$itemId] = $proposal;
            }
        }

        return $byItemId;
    }
}