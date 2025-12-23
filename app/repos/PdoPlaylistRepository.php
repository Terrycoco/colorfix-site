<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\Playlist;
use App\Entities\PlaylistItem;
use App\Entities\PlaylistStep;

class PdoPlaylistRepository
{
    public function getById(string $playlistId): ?Playlist
    {
        foreach ($this->getPlaylistDefinitions() as $definition) {
            if ($definition['playlist_id'] !== $playlistId) {
                continue;
            }

            $steps = [];
            foreach ($definition['steps'] as $stepDef) {
                $items = [];
                foreach ($stepDef['items'] as $itemDef) {
                    $items[] = new PlaylistItem(
                        (string)($itemDef['ap_id'] ?? ''),
                        $itemDef['image_url'],
                        $itemDef['title'] ?? null,
                        $itemDef['transition'] ?? null,
                        $itemDef['duration_ms'] ?? null,
                        $itemDef['title_mode'] ?? null
                    );
                }

                $steps[] = new PlaylistStep(
                    $stepDef['step_id'],
                    (bool) $stepDef['is_group'],
                    $items
                );
            }

            return new Playlist(
                $definition['playlist_id'],
                $definition['type'],
                $definition['title'],
                $steps
            );
        }

        return null;
    }

    /**
     * ==========================================================
     * PLAYLIST DEFINITIONS
     * EDIT HERE TO ADD / MODIFY PLAYLISTS
     * ==========================================================
     */
    private function getPlaylistDefinitions(): array
    {
        return [
            [
                'playlist_id' => '1',
                'type' => 'test',
                'title' => 'Test Four',
                'steps' => [
                    [
                        'step_id' => 'all',
                        'is_group' => false,
                        'items' => [
                            [
                                'ap_id' => null,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/exteriors/cottage/PHO_1L6697/prepared/base.jpg',
                                'title' => 'Cottage Palettes -- Tap screen for more',
                                'title_mode' => 'static'
                            ],
                            [
                                'ap_id' => 43,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_43.jpg',
                                'title' => 'Early Bloomer',
                                'title_mode' => 'animate'
                            ],
                             [
                                'ap_id' => 44,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_44.jpg',
                                'title' => 'Audrey',
                                'title_mode' => 'animate'
                            ],

                            [
                                'ap_id' => 37,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_37.jpg',
                                'title' => 'Betsy Ross',
                                'title_mode' => 'animate'
                            ],
                            [
                                'ap_id' => 38,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_38.jpg',
                                'title' => "Tiffany's Cottage",
                                'title_mode' => 'animate'
                            ],
                            [
                                'ap_id' => 39,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_39.jpg',
                                'title' => 'The Diva',
                                'title_mode' => 'animate'
                            ],
                       
                        ],
                    ],
                ],
            ],
        ];
    }
}
