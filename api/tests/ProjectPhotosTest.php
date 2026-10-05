<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Repos\PdoProjectPhotoRepository;
use App\Repos\PdoPaletteViewerPhotoRepository;

function project_photos_fixture(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach ([
        'projects' => 'id INTEGER PRIMARY KEY',
        'saved_palettes' => 'id INTEGER PRIMARY KEY, display_title TEXT, nickname TEXT',
        'project_palettes' => 'project_palette_id INTEGER PRIMARY KEY, project_id INTEGER,
            saved_palette_id INTEGER, area_label TEXT, order_index INTEGER, is_final INTEGER',
        'photo_library' => 'photo_library_id INTEGER PRIMARY KEY, rel_path TEXT, ai_alt_text TEXT,
            alt_text TEXT, updated_at TEXT',
        'project_photos' => 'project_id INTEGER, photo_library_id INTEGER, `use` INTEGER,
            palette_id INTEGER, zoom INTEGER, main INTEGER, before INTEGER, sort_order INTEGER, PRIMARY KEY(project_id, photo_library_id)',
        'palette_viewers' => 'palette_viewer_id INTEGER PRIMARY KEY, project_id INTEGER,
            saved_palette_id INTEGER, format TEXT, is_active INTEGER',
        'palette_viewer_photos' => 'palette_viewer_photo_id INTEGER PRIMARY KEY, palette_viewer_id INTEGER,
            photo_library_id INTEGER, rel_path TEXT, photo_type TEXT, order_index INTEGER',
        'playlists' => 'playlist_id INTEGER PRIMARY KEY, project_id INTEGER, hero_image_id INTEGER, hero_image_url TEXT',
        'playlist_items' => 'playlist_item_id INTEGER PRIMARY KEY, playlist_id INTEGER,
            saved_palette_id INTEGER, photo_library_id INTEGER, image_url TEXT, analyzer_role TEXT, order_index INTEGER',
        'rex_reservations' => 'id INTEGER PRIMARY KEY, resolver_key TEXT, resource_type TEXT, resource_id INTEGER',
        'rex_reservation_links' => 'parent_reservation_id INTEGER, child_reservation_id INTEGER, relationship_key TEXT',
    ] as $table => $columns) {
        $pdo->exec("CREATE TABLE {$table} ({$columns})");
    }
    $pdo->exec('INSERT INTO projects VALUES (7), (8)');
    $pdo->exec("INSERT INTO saved_palettes VALUES (11, 'Living Room', NULL), (12, 'Exterior', NULL), (13, 'Other Project', NULL)");
    $pdo->exec("INSERT INTO project_palettes VALUES (101, 7, 11, 'Living Room', 0, 1), (102, 7, 12, 'Exterior', 1, 1), (103, 8, 13, 'Other', 0, 1)");
    $pdo->exec("INSERT INTO photo_library VALUES (1, '/photos/main.jpg', 'Main', NULL, '2026-10-04'),
        (2, '/photos/zoom.jpg', NULL, 'Zoom', '2026-10-04'), (3, '/photos/before.jpg', 'Before', NULL, '2026-10-04'),
        (4, '/photos/exterior.jpg', 'Exterior', NULL, '2026-10-04')");
    $pdo->exec("INSERT INTO palette_viewers VALUES (78, NULL, 11, 'client', 1), (79, NULL, 11, 'concept', 1), (80, NULL, 11, 'painter', 1)");
    return $pdo;
}

function project_photo_rows(): array
{
    return [
        ['photo_library_id' => 3, 'use' => true, 'before' => true, 'palette_id' => 11],
        ['photo_library_id' => 1, 'use' => true, 'main' => true, 'palette_id' => 11],
        ['photo_library_id' => 2, 'use' => true, 'zoom' => true, 'palette_id' => 11],
        ['photo_library_id' => 4, 'use' => true, 'main' => true, 'palette_id' => 12],
    ];
}

test('project photos share selections across PV formats while painter excludes before photos', function () {
    $pdo = project_photos_fixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    assert_equals([], $repo->forViewer(78));
    $repo->save(7, project_photo_rows(), '');
    assert_true($repo->revision(7) !== '');
    assert_equals([3, 1, 2], array_column($repo->forViewer(78), 'photo_library_id'));
    assert_equals([3, 1, 2], array_column($repo->forViewer(79), 'photo_library_id'));
    assert_equals([1, 2], array_column($repo->forViewer(80), 'photo_library_id'));
    assert_equals([4], array_column($repo->forPalette(7, 12, true), 'photo_library_id'));
    assert_equals([], $repo->forPalette(8, 13));
    $entities = (new PdoPaletteViewerPhotoRepository($pdo))->findByViewerId(78);
    assert_equals('before', $entities[0]->photoType);
    assert_equals('Before', $entities[0]->caption);
    assert_true(str_starts_with($entities[1]->relPath, '/photos/main.jpg?v='));
    $disabled = project_photo_rows();
    foreach ($disabled as &$row) { $row['use'] = false; }
    $repo->save(7, $disabled, $repo->revision(7));
    assert_equals([], $repo->forViewer(78));
    assert_equals([], $repo->forPalette(7, 12, true));
});

test('invalid project photos and stale revisions cannot overwrite a saved collection', function () {
    $pdo = project_photos_fixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $repo->save(7, project_photo_rows(), '');
    $original = $repo->listForProject(7);
    $badRows = [];
    $foreign = project_photo_rows(); $foreign[0]['palette_id'] = 13; $badRows[] = $foreign;
    $duplicate = project_photo_rows(); $duplicate[] = $duplicate[0]; $badRows[] = $duplicate;
    $missingMain = project_photo_rows(); $missingMain[1]['main'] = false; $missingMain[1]['zoom'] = true; $badRows[] = $missingMain;
    $multipleMain = project_photo_rows(); $multipleMain[2]['zoom'] = false; $multipleMain[2]['main'] = true; $badRows[] = $multipleMain;
    $invalidRole = project_photo_rows(); $invalidRole[0]['before'] = false; $badRows[] = $invalidRole;
    foreach ($badRows as $rows) {
        try { $repo->save(7, $rows, $repo->revision(7)); throw new LogicException('Invalid save accepted'); }
        catch (InvalidArgumentException $e) {}
        assert_equals($original, $repo->listForProject(7));
    }
    try { $repo->save(7, [], ''); throw new LogicException('Stale save accepted'); }
    catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'another window'));
        assert_equals(409, $e->getCode());
    }
    assert_equals($original, $repo->listForProject(7));
    $recovered = project_photo_rows();
    $recovered[2]['use'] = false;
    $repo->save(7, $recovered, $repo->revision(7));
    assert_equals(false, $repo->listForProject(7)[2]['use']);
});

test('existing PV and playlist photos stage once without changing published sources', function () {
    $pdo = project_photos_fixture();
    $pdo->exec("INSERT INTO palette_viewer_photos VALUES (1, 78, 1, NULL, 'full', 0),
        (2, 79, NULL, '/photos/main.jpg?v=12', 'zoom', 0), (3, 78, 3, NULL, 'before', 1)");
    $pdo->exec('INSERT INTO playlists (playlist_id, project_id) VALUES (56, 7), (57, 8)');
    $pdo->exec("INSERT INTO playlist_items VALUES (1, 56, 11, 2, NULL, 'normal', 1),
        (2, 56, 12, 4, NULL, 'normal', 2), (3, 57, 11, 4, NULL, 'normal', 0)");
    $repo = new PdoProjectPhotoRepository($pdo);
    $photos = $repo->legacyPhotos(7);
    assert_equals([1, 3, 2, 4], array_column($photos, 'photo_library_id'));
    assert_equals(['full', 'before', 'zoom', 'full'], array_column($photos, 'role'));
    assert_equals('', $repo->revision(7));
    assert_equals([], $repo->forViewer(78));
    $repo->save(7, $photos, '');
    assert_equals([1, 2], array_column($repo->forViewer(80), 'photo_library_id'));
});

test('ambiguous shared palettes do not inherit another projects photos', function () {
    $pdo = project_photos_fixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $repo->save(7, project_photo_rows(), '');
    $pdo->exec("INSERT INTO project_palettes VALUES (104, 8, 11, 'Other Living Room', 1, 1)");
    assert_equals([], $repo->forViewer(78));
    $pdo->exec('UPDATE palette_viewers SET project_id = 7 WHERE palette_viewer_id = 78');
    assert_equals([3, 1, 2], array_column($repo->forViewer(78), 'photo_library_id'));
});

test('every PV format follows FINAL and Use live without restoring legacy photos', function () {
    $pdo = project_photos_fixture();
    $pdo->exec("INSERT INTO palette_viewers VALUES (81, 7, 11, 'public', 1)");
    $pdo->exec("INSERT INTO palette_viewers VALUES (82, 7, 11, 'showcase', 1)");
    $pdo->exec("INSERT INTO palette_viewer_photos VALUES (1, 78, 4, '/photos/exterior.jpg', 'full', 0)");
    $repo = new PdoProjectPhotoRepository($pdo);
    $reader = new PdoPaletteViewerPhotoRepository($pdo);
    assert_equals([], $reader->findByViewerId(78));
    $repo->save(7, project_photo_rows(), '');
    $pdo->exec('UPDATE project_palettes SET is_final = 0 WHERE project_palette_id = 101');
    foreach ([78, 79, 80, 81, 82] as $id) {
        assert_equals([], $repo->forViewer($id));
        assert_equals([], $reader->findByViewerId($id));
    }
    assert_equals([4], array_column($repo->forPalette(7, 12), 'photo_library_id'));
    assert_equals(4, count($repo->listForProject(7)));
    $pdo->exec('UPDATE project_palettes SET is_final = 1 WHERE project_palette_id = 101');
    $pdo->exec('UPDATE project_photos SET `use` = 0 WHERE photo_library_id = 2');
    foreach ([78, 79, 81, 82] as $id) {
        assert_equals([3, 1], array_column($repo->forViewer($id), 'photo_library_id'));
    }
    assert_equals([1], array_column($repo->forViewer(80), 'photo_library_id'));
});

test('project viewers with unavailable selections never restore legacy images while standalone public viewers keep their source', function () {
    $pdo = project_photos_fixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $pdo->exec("INSERT INTO palette_viewers VALUES (90,NULL,99,'public',1)");
    assert_equals(null, $repo->forViewer(90));
    assert_equals(null, $repo->forPalette(0, 99));
    $pdo->exec('DROP TABLE project_photos');
    $repo = new PdoProjectPhotoRepository($pdo);
    assert_equals([], $repo->forViewer(78));
    assert_equals(null, $repo->forViewer(90));
});

test('old project viewers linked through a playlist or REX remain subject to Use instead of becoming standalone', function () {
    $pdo = project_photos_fixture();
    $pdo->exec("INSERT INTO palette_viewers VALUES (91,NULL,98,'concept',1),(92,NULL,99,'client',1)");
    $pdo->exec('INSERT INTO playlists VALUES (56,7,NULL,NULL)');
    $pdo->exec("INSERT INTO playlist_items VALUES (526,56,98,4,'/photos/exterior.jpg','after',0)");
    $pdo->exec("INSERT INTO rex_reservations VALUES (1,'playlist_experience','playlist',56),(2,'viewer','palette_viewer',92)");
    $pdo->exec("INSERT INTO rex_reservation_links VALUES (1,2,'viewer')");
    $repo = new PdoProjectPhotoRepository($pdo);
    assert_equals(7, $repo->viewerProjectId(91));
    assert_equals(7, $repo->viewerProjectId(92));
    assert_equals([], $repo->forViewer(91));
    assert_equals([], $repo->forViewer(92));
});
