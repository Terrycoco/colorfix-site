<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Repos\PdoProjectPhotoRepository;

function projectPhotosPlaylistFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY);
        CREATE TABLE project_photos (project_id INTEGER, photo_library_id INTEGER,
            `use` INTEGER, palette_id INTEGER, zoom INTEGER, main INTEGER, before INTEGER, sort_order INTEGER,
            PRIMARY KEY(project_id, photo_library_id));
        CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY, rel_path TEXT,
            ai_alt_text TEXT, alt_text TEXT, updated_at TEXT);
        CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY, project_id INTEGER,
            hero_image_id INTEGER, hero_image_url TEXT);
        CREATE TABLE playlist_items (playlist_item_id INTEGER PRIMARY KEY, playlist_id INTEGER,
            photo_library_id INTEGER, image_url TEXT, saved_palette_id INTEGER, analyzer_role TEXT, order_index INTEGER);
        CREATE TABLE project_palettes (project_palette_id INTEGER PRIMARY KEY, project_id INTEGER,
            saved_palette_id INTEGER, area_label TEXT, order_index INTEGER, is_final INTEGER);
        CREATE TABLE saved_palettes (id INTEGER PRIMARY KEY, display_title TEXT, nickname TEXT);
        CREATE TABLE palette_viewers (palette_viewer_id INTEGER PRIMARY KEY, saved_palette_id INTEGER,
            is_active INTEGER, format TEXT);
        CREATE TABLE palette_viewer_photos (palette_viewer_photo_id INTEGER PRIMARY KEY,
            palette_viewer_id INTEGER, photo_library_id INTEGER, rel_path TEXT, photo_type TEXT, order_index INTEGER);
        INSERT INTO projects VALUES (7);
        INSERT INTO saved_palettes VALUES (11, "Room", NULL);
        INSERT INTO project_palettes VALUES (1, 7, 11, "Room", 0, 1);
        INSERT INTO photo_library VALUES
            (1, "/photos/main.jpg", NULL, "Main", NULL),
            (2, "/photos/before.jpg", NULL, "Before", NULL),
            (3, "/photos/hero.jpg", NULL, "Hero", NULL),
            (4, "/photos/other.jpg", NULL, "Other", NULL),
            (5, "/photos/new.jpg", NULL, "New", NULL);
        INSERT INTO playlists VALUES (10, 7, 3, NULL), (20, 8, 4, NULL);
        INSERT INTO playlist_items VALUES
            (1, 10, 1, NULL, 11, "photo", 0),
            (2, 10, NULL, "https://example.com/photos/before.jpg?v=2", NULL, "before", 1),
            (3, 10, 1, NULL, NULL, "photo", 2),
            (4, 20, 4, NULL, NULL, "photo", 0);');
    return $pdo;
}

test('project photos include all playlist photos without palette assignments and deduplicate', function () {
    $repo = new PdoProjectPhotoRepository(projectPhotosPlaylistFixture());
    $photos = $repo->availablePhotos(7);
    assert_equals([1, 2, 3], array_column($photos, 'photo_library_id'));
    assert_equals(11, $photos[0]['palette_id']);
    assert_equals('before', $photos[1]['role']);
    assert_equals(null, $photos[1]['palette_id']);
    assert_equals([true, true, true], array_column($photos, 'from_playlist'));
});

test('playlist additions remain listed after saving while saved tags and viewer selections persist', function () {
    $pdo = projectPhotosPlaylistFixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $repo->save(7, [
        ['photo_library_id' => 1, 'main' => true, 'use' => true, 'palette_id' => 11],
        ['photo_library_id' => 2, 'zoom' => true, 'use' => false, 'palette_id' => null],
    ], '');
    $pdo->exec('INSERT INTO playlist_items VALUES (5, 10, 5, NULL, 11, "photo", 3)');
    $photos = $repo->availablePhotos(7);
    assert_equals([1, 2, 5, 3], array_column($photos, 'photo_library_id'));
    assert_equals('zoom', $photos[1]['role']);
    assert_equals(null, $photos[2]['palette_id']);
    assert_equals(false, $photos[2]['use']);
    assert_equals([1], array_column($repo->forPalette(7, 11), 'photo_library_id'));
    $repo->save(7, $photos, $repo->revision(7));
    assert_equals([1, 2, 5, 3], array_column($repo->availablePhotos(7), 'photo_library_id'));
    assert_equals([1], array_column($repo->forPalette(7, 11), 'photo_library_id'));
});
