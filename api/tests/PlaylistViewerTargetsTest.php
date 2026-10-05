<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PLAYLISTS\Services\PlaylistExperienceService;

test('client thumbnails include each client palette once and exclude painter and concept viewers', function () {
    $service = new PlaylistExperienceService(new PDO('sqlite::memory:'));
    $method = new ReflectionMethod($service, 'buildPVTargets');
    $viewers = [];
    foreach ([
        [1, 11, 'client', 'Living Room'],
        [2, 11, 'painter', 'Living Room Painter'],
        [3, 12, 'client', 'Wine Room'],
        [4, 13, 'client', 'Exterior'],
        [5, 11, 'client', 'Duplicate Living Room'],
        [6, 12, 'concept', 'Wine Room Concept'],
        [7, null, 'painter', 'Project Painter'],
    ] as [$id, $paletteId, $format, $title]) {
        $viewers[] = [
            'rex_url' => '/t/viewer-' . $id,
            'meta' => [
                'palette_viewer_id' => $id,
                'saved_palette_id' => $paletteId,
                'format' => $format,
                'title' => $title,
                'photo_url' => '/photos/' . $id . '.jpg',
            ],
        ];
    }
    $client = $method->invoke($service, $viewers, 'client');
    assert_equals(3, $client['count']);
    assert_equals(['Living Room', 'Wine Room', 'Exterior'], array_column($client['targets'], 'title'));
    assert_equals(['/t/viewer-1', '/t/viewer-3', '/t/viewer-4'], $client['urls']);
    assert_equals([11, 12, 13], array_column($client['targets'], 'saved_palette_id'));
    $concept = $method->invoke($service, $viewers, 'concept');
    assert_equals(1, $concept['count']);
    assert_equals('Wine Room Concept', $concept['targets'][0]['title']);
});
