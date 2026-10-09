<?php
declare(strict_types=1);

require_once __DIR__ . '/ProjectPhotosTest.php';

use App\PROJECTS\Repos\PdoProjectPhotoRepository;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\Repos\PdoColorTriggerPhotoRepository;

function libraryPaletteFixture(): PDO
{
    $pdo = project_photos_fixture();
    $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-10-05 10:00:00');
    $pdo->exec('ALTER TABLE projects ADD COLUMN rooms TEXT');
    $pdo->exec("UPDATE projects SET rooms = '[{\"id\":\"living\",\"name\":\"Living Room\"},{\"id\":\"exterior\",\"name\":\"Exterior\"}]'");
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN palette_id INTEGER REFERENCES saved_palettes(id) ON DELETE SET NULL');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN has_palette INTEGER DEFAULT 0');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN show_in_gallery INTEGER DEFAULT 0');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN is_inactive INTEGER DEFAULT 0');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN is_retired INTEGER DEFAULT 0');
    $pdo->exec('ALTER TABLE project_photos ADD COLUMN room_id TEXT');
    $pdo->exec('ALTER TABLE project_photos DROP COLUMN palette_id');
    return $pdo;
}

test('project save writes Library palettes and independent Before rooms after the old column is dropped', function () {
    $pdo = libraryPaletteFixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $repo->save(7, project_photo_rows(), '');
    assert_equals([null, 11, 11, 12], array_column($repo->listForProject(7), 'palette_id'));
    assert_equals(['living', 'living', 'living', 'exterior'], array_column($repo->listForProject(7), 'room_id'));
    assert_equals([3, 1, 2], array_column($repo->forViewer(78), 'photo_library_id'));
    assert_equals([1, 2], array_column($repo->forViewer(80), 'photo_library_id'));
    assert_equals(0, (int)$pdo->query('SELECT SUM(show_in_gallery) FROM photo_library')->fetchColumn());

    $before = $pdo->query('SELECT palette_id, has_palette FROM photo_library WHERE photo_library_id = 3')->fetch(PDO::FETCH_ASSOC);
    assert_equals(null, $before['palette_id']);
    assert_equals(0, (int)$before['has_palette']);
    $pdo->exec('UPDATE project_photos SET `use` = 0 WHERE photo_library_id = 2');
    assert_equals([3, 1], array_column($repo->forViewer(78), 'photo_library_id'));
});

test('Library palette changes update project PVs and invalidate stale project saves', function () {
    $pdo = libraryPaletteFixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $repo->save(7, project_photo_rows(), '');
    $revision = $repo->revision(7);
    (new PdoPhotoLibraryRepository($pdo))->update(2, ['palette_id' => 12, 'expected_palette_id' => 11]);
    assert_equals([3, 1], array_column($repo->forViewer(78), 'photo_library_id'));
    assert_equals([2, 4], array_column($repo->forPalette(7, 12), 'photo_library_id'));
    try { $repo->save(7, project_photo_rows(), $revision); throw new LogicException('Stale save accepted'); }
    catch (RuntimeException $e) { assert_equals(409, $e->getCode()); }
    assert_equals(12, (int)$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id = 2')->fetchColumn());
    assert_equals(0, (int)$pdo->query('SELECT SUM(show_in_gallery) FROM photo_library')->fetchColumn());
});

test('Before photos reject Library palette links and stale Library edits cannot overwrite newer links', function () {
    $pdo = libraryPaletteFixture();
    (new PdoProjectPhotoRepository($pdo))->save(7, project_photo_rows(), '');
    $library = new PdoPhotoLibraryRepository($pdo);
    try { $library->update(3, ['palette_id' => 11]); throw new LogicException('Before palette accepted'); }
    catch (InvalidArgumentException $e) {}
    try { $library->update(2, ['palette_id' => 12, 'expected_palette_id' => null]); throw new LogicException('Stale edit accepted'); }
    catch (RuntimeException $e) {}
    assert_equals(11, (int)$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id = 2')->fetchColumn());
    assert_equals(false, $pdo->inTransaction());
});

test('See where used reads Library palettes and Gallery independently of project Use', function () {
    $pdo = libraryPaletteFixture();
    $repo = new PdoProjectPhotoRepository($pdo);
    $repo->save(7, project_photo_rows(), '');
    $pdo->exec('ALTER TABLE saved_palettes ADD COLUMN palette_hash TEXT');
    $pdo->exec('ALTER TABLE saved_palettes ADD COLUMN brand TEXT');
    $pdo->exec('CREATE TABLE saved_palette_members (saved_palette_id INTEGER, color_id INTEGER)');
    $pdo->exec('INSERT INTO saved_palette_members VALUES (11, 100), (12, 200)');
    $pdo->exec('UPDATE photo_library SET show_in_gallery = 1 WHERE photo_library_id IN (2, 3)');
    $pdo->exec('UPDATE project_photos SET `use` = 0 WHERE photo_library_id = 2');
    $gallery = new PdoColorTriggerPhotoRepository($pdo);
    assert_equals([2], array_column($gallery->findGalleryTriggerPhotosForColor(100), 'photo_library_id'));
    $pdo->exec('UPDATE photo_library SET palette_id = 12 WHERE photo_library_id = 2');
    assert_equals([], $gallery->findGalleryTriggerPhotosForColor(100));
    assert_equals([2], array_column($gallery->findGalleryTriggerPhotosForColor(200), 'photo_library_id'));
    $pdo->exec('UPDATE photo_library SET is_retired = 1 WHERE photo_library_id = 2');
    assert_equals([], $gallery->findGalleryTriggerPhotosForColor(200));
});

test('room assignment works before the Library palette migration and Before photos need no palette', function () {
    $pdo = project_photos_fixture();
    $pdo->exec('ALTER TABLE projects ADD COLUMN rooms TEXT');
    $pdo->exec("UPDATE projects SET rooms = '[{\"id\":\"living\",\"name\":\"Living Room\"},{\"id\":\"exterior\",\"name\":\"Exterior\"}]'");
    $pdo->exec('ALTER TABLE project_photos ADD COLUMN room_id TEXT');
    $repo = new PdoProjectPhotoRepository($pdo);
    assert_equals(false, $repo->libraryOwnsPalette());
    assert_equals(true, $repo->roomsAssignable());
    $photos = project_photo_rows();
    $photos[0]['palette_id'] = null;
    $photos[0]['room_id'] = 'living';
    $repo->save(7, $photos, '');
    assert_equals('living', $repo->listForProject(7)[0]['room_id']);
    assert_equals(null, $repo->listForProject(7)[0]['palette_id']);
    assert_equals([3, 1, 2], array_column($repo->forViewer(78), 'photo_library_id'));
    $photos[0]['room_id'] = 'exterior';
    $repo->save(7, $photos, $repo->revision(7));
    assert_equals([1, 2], array_column($repo->forViewer(78), 'photo_library_id'));
    assert_equals([3, 4], array_column($repo->forPalette(7, 12), 'photo_library_id'));
    $photos[0]['room_id'] = 'not-a-project-room';
    try { $repo->save(7, $photos, $repo->revision(7)); throw new LogicException('Foreign room accepted'); }
    catch (InvalidArgumentException $e) {}
    assert_equals('exterior', $repo->listForProject(7)[0]['room_id']);
});
