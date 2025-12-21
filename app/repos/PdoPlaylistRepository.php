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
                        $itemDef['duration_ms'] ?? null
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
                                'title' => 'Before',
                            ],
                            [
                                'ap_id' => 37,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_37.jpg',
                                'title' => 'Betsy Ross',
                            ],
                            [
                                'ap_id' => 38,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_38.jpg',
                                'title' => "Tiffany's Cottage",
                            ],
                            [
                                'ap_id' => 39,
                                'image_url' => 'https://colorfix.terrymarr.com/photos/rendered/ap_39.jpg',
                                'title' => 'The Diva',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
