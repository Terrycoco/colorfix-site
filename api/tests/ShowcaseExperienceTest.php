<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PLAYLISTS\Services\PlaylistExperienceService;
use App\REX\Resolvers\PlaylistExperienceResolver;

test('Showcase is accepted by the REX preview doorbell', function () {
    $supported = (new ReflectionClass(PlaylistExperienceResolver::class))->getConstant('SUPPORTED_EXPERIENCES');
    assert_true(in_array('showcase', $supported, true));
});

test('palette-free end screen retains regular CTAs and removes every color action', function () {
    $service = new PlaylistExperienceService(new PDO('sqlite::memory:'));
    $method = new ReflectionMethod($service, 'selectRexCtas');
    $ctas = array_map(fn ($key) => ['action_key' => $key], [
        'replay', 'see_colors_used', 'to_palette', 'to_thumbs', 'navigate'
    ]);
    $filtered = $method->invoke($service, $ctas, 'none');
    assert_equals(['replay', 'navigate'], array_column($filtered, 'action_key'));
    $public = $method->invoke($service, $ctas, 'viewer');
    assert_equals(['replay', 'to_palette', 'navigate'], array_column($public, 'action_key'));
});
