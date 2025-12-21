<?php
declare(strict_types=1);

use App\Services\PlayerExperienceService;
use App\Entities\PlaylistItem;

test('player-experience builds playback plan', function () {

    $svc = new PlayerExperienceService();

    // must exist in PdoPlaylistRepository
    $playlistId = 'cottage_makeover_01';

    $plan = $svc->buildPlaybackPlan($playlistId, null);

    if (!is_array($plan)) {
        throw new RuntimeException('playback plan is not an array');
    }

    if (($plan['playlist_id'] ?? '') !== $playlistId) {
        throw new RuntimeException('playlist_id mismatch');
    }

    if (empty($plan['title'])) {
        throw new RuntimeException('playlist title missing');
    }

    if (empty($plan['type'])) {
        throw new RuntimeException('playlist type missing');
    }

    if (!isset($plan['items']) || !is_array($plan['items'])) {
        throw new RuntimeException('items missing or not array');
    }

    if (count($plan['items']) === 0) {
        throw new RuntimeException('no playback items returned');
    }

    foreach ($plan['items'] as $item) {
        if (!$item instanceof PlaylistItem) {
            throw new RuntimeException('item is not PlaylistItem');
        }
        if ($item->ap_id === '') {
            throw new RuntimeException('item ap_id missing');
        }
        if ($item->image_url === '') {
            throw new RuntimeException('item image_url missing');
        }
    }
});

test('player-experience applies start offset', function () {

    $svc = new PlayerExperienceService();
    $playlistId = 'cottage_makeover_01';

    $full = $svc->buildPlaybackPlan($playlistId, null);
    $offset = $svc->buildPlaybackPlan($playlistId, 1);

    $fullItems = $full['items'] ?? null;
    $offsetItems = $offset['items'] ?? null;

    if (!is_array($fullItems) || !is_array($offsetItems)) {
        throw new RuntimeException('items missing from plan');
    }

    if (count($offsetItems) >= count($fullItems)) {
        throw new RuntimeException('start offset did not reduce item count');
    }

    if ($offsetItems[0]->ap_id !== $fullItems[1]->ap_id) {
        throw new RuntimeException('start offset did not skip first item');
    }
});

test('player-experience normalizes invalid start offset', function () {

    $svc = new PlayerExperienceService();
    $playlistId = 'cottage_makeover_01';

    $full = $svc->buildPlaybackPlan($playlistId, null);
    $invalid = $svc->buildPlaybackPlan($playlistId, 999);

    $fullItems = $full['items'] ?? null;
    $invalidItems = $invalid['items'] ?? null;

    if (!is_array($fullItems) || !is_array($invalidItems)) {
        throw new RuntimeException('items missing from plan');
    }

    if (count($fullItems) !== count($invalidItems)) {
        throw new RuntimeException('invalid start offset was not normalized');
    }
});

test('player-experience throws for unknown playlist', function () {

    $svc = new PlayerExperienceService();

    try {
        $svc->buildPlaybackPlan('does_not_exist_123');
    } catch (RuntimeException $e) {
        return; // expected
    }

    throw new RuntimeException('expected exception not thrown for unknown playlist');
});
