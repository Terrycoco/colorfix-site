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
                        $itemDef['subtitle'] ?? null,
                        $itemDef['type'] ?? null,
                        isset($itemDef['star']) ? (bool)$itemDef['star'] : null,
                        $itemDef['layout'] ?? null,
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
                'end_screen_layout' => 'default',
                'steps' => [
                    [
                        'step_id' => 'all',
                        'is_group' => false,
                        'items' => [
                            [
                                'ap_id' => null,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/exteriors/cottage/PHO_1L6697/prepared/base.jpg',
                                'title' => 'Cottage Palettes',
                                'title_mode' => 'static',
                                'type' => 'intro',
                                'layout' => 'default',
                                'star' => false
                            ],
                            [
                                'ap_id' => 43,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_43.jpg',
                                'title' => 'Early Bloomer',
                                'title_mode' => 'animate',
                                'star' => true
                            
                            ],
                             [
                                'ap_id' => 44,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_44.jpg',
                                'title' => 'Audrey',
                                'title_mode' => 'animate',
                                'star' => true,
                            ],

                            [
                                'ap_id' => 37,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_37.jpg',
                                'title' => 'Betsy Ross',
                                'title_mode' => 'animate',
                                'star' => true,
                            ],
                            [
                                'ap_id' => 38,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_38.jpg',
                                'title' => "Tiffany's Cottage",
                                'title_mode' => 'animate',
                                'star' => true,
                            ],
                            [
                                'ap_id' => 39,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_39.jpg',
                                'title' => 'The Diva',
                                'title_mode' => 'animate',
                                'star' => true
                            ],
                       
                        ],
                    ],
                ],
            ],
            [
                'playlist_id' => '2',
                'type' => 'test',
                'title' => 'Test One',
                'end_screen_layout' => 'default',
                'steps' => [
                    [
                        'step_id' => 'all',
                        'is_group' => false,
                        'items' => [
                            [
                                'ap_id' => null,
                                'image_url' => null,
                                'title' => 'Adobe Transformation',
                                'subtitle' => 'Getting rid of muddy palettes of the Southwest',
                                'title_mode' => 'static',
                                'type' => 'intro',
                                'layout' => 'text',
                                'star' => false
                            ],
                            [
                                'ap_id' => null,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/exteriors/adobe/PHO_19XGNY/prepared/base.jpg',
                                'title' => 'Before',
                                'subtitle' => 'Typical adobe tract house with muddy palette',
                                'title_mode' => 'animated',
                                'type' => 'normal',
                                'layout' => 'default',
                                'star' => false
            
            
                            ],
                            [
                                'ap_id' => 45,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_45.jpg',
                                'title' => 'After',
                                'subtitle' => 'Palette: Oasis -- A calm and refreshing respite from orange',
                                'title_mode' => 'animate',
                                'star' => true,
                            ],
                            
                       
                        ],
                    ],
                ],
            ],
        ];
    }
}
