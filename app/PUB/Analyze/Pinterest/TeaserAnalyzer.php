<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * PINTEREST TEASER ANALYZER
 *
 * A teaser is an explicitly authored Playlist item, not a transformation
 * pair and not a derived "best Before" guess.
 *
 * Selection rule:
 *
 *   pin = 1
 *   analyzer_role = teaser
 *
 * Zero teaser items is valid and produces zero proposals.
 * Every usable teaser item produces exactly one teaser proposal.
 * The eventual destination URL is NOT an ANALYZE concern; PACKAGE fills
 * the asset's pingback later.
 */
final class TeaserAnalyzer implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel = $channel;
    }


    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Teaser Analyzer is ready.',
            [
                'worker' => self::class,
            ]
        );
    }


    public function preflight(
        array $source
    ): PubComSignal {
        $teaserItems = $this->teaserItems(
            $source
        );

        $usableCount = 0;
        $unusableCount = 0;

        foreach ($teaserItems as $item) {
            if ($this->isUsableTeaserItem($item)) {
                $usableCount++;
            } else {
                $unusableCount++;
            }
        }

        /*
         * No teaser slides is explicitly allowed. This product line simply
         * contributes zero Boxes to the mixed Analyze run.
         */
        if ($teaserItems === []) {
            return PubComSignal::ready(
                'No authored teaser items were requested.',
                [
                    'worker' => self::class,
                    'teaser_item_count' => 0,
                    'usable_count' => 0,
                ]
            );
        }

        if ($usableCount === 0) {
            return PubComSignal::ineligible(
                'teaser_no_usable_items',
                'Teaser items were requested, but none has both a usable prepared photo and a title.',
                [
                    'worker' => self::class,
                    'teaser_item_count' => count($teaserItems),
                    'usable_count' => 0,
                    'unusable_count' => $unusableCount,
                ]
            );
        }

        return PubComSignal::ready(
            'Teaser assignment is eligible.',
            [
                'worker' => self::class,
                'teaser_item_count' => count($teaserItems),
                'usable_count' => $usableCount,
                'unusable_count' => $unusableCount,
            ]
        );
    }


    /**
     * One authored teaser item -> one teaser proposal.
     */
    public function analyze(
        array $source
    ): array {
        $proposals = [];
        $sortOrder = 0;

        foreach ($this->teaserItems($source) as $item) {
            if (!$this->isUsableTeaserItem($item)) {
                continue;
            }

            $photo = is_array(
                $item['photo']
                ?? null
            )
                ? $item['photo']
                : [];

            $title = trim(
                (string)(
                    $item['title']
                    ?? ''
                )
            );

            $proposals[] = [
                'asset_type' => 'pin_teaser',
                'sort_order' => $sortOrder++,
                'search_title' => $title,
                'ingredients' => [
                    'source' => [
                        'file_path' => trim(
                            (string)(
                                $photo['file_path']
                                ?? ''
                            )
                        ),
                        'image_url' => trim(
                            (string)(
                                $photo['image_url']
                                ?? ''
                            )
                        ),
                    ],
                    /*
                     * The authored title is production copy for the static
                     * teaser image, so it belongs inside ingredients too.
                     */
                    'search_title' => $title,
                ],
            ];
        }

        return [
            'proposals' => $proposals,
        ];
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function teaserItems(
        array $source
    ): array {
        $items = is_array(
            $source['items']
            ?? null
        )
            ? $source['items']
            : [];

        return array_values(
            array_filter(
                $items,
                static function (mixed $item): bool {
                    if (!is_array($item) || empty($item['pin'])) {
                        return false;
                    }

                    $role = strtolower(
                        trim(
                            (string)(
                                $item['analyzer_role']
                                ?? 'ignore'
                            )
                        )
                    );

                    return $role === 'teaser';
                }
            )
        );
    }


    private function isUsableTeaserItem(
        array $item
    ): bool {
        $photo = is_array(
            $item['photo']
            ?? null
        )
            ? $item['photo']
            : [];

        return
            trim(
                (string)(
                    $photo['file_path']
                    ?? ''
                )
            ) !== ''
            && trim(
                (string)(
                    $photo['image_url']
                    ?? ''
                )
            ) !== ''
            && trim(
                (string)(
                    $item['title']
                    ?? ''
                )
            ) !== '';
    }
}
