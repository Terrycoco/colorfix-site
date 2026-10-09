<?php
declare(strict_types=1);

test('photo palette backfill ignores obsolete sets but retains real conflict guards', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE project_photos (photo_library_id INTEGER, palette_id INTEGER, `before` INTEGER)');
    $pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER, palette_id INTEGER, source_type TEXT, source_id INTEGER)');
    $pdo->exec('CREATE TABLE saved_palette_photos (id INTEGER, saved_palette_id INTEGER, photo_type TEXT)');
    $pdo->exec('CREATE TABLE saved_palette_sets (id INTEGER, saved_palette_id INTEGER)');
    $pdo->exec('CREATE TABLE saved_palette_set_photos (photo_library_id INTEGER, saved_palette_set_id INTEGER, photo_type TEXT)');
    $pdo->exec("INSERT INTO photo_library VALUES (72, NULL, 'saved_palette_photo', 21)");
    $pdo->exec("INSERT INTO saved_palette_photos VALUES (21, 18, 'full')");
    $pdo->exec('INSERT INTO saved_palette_sets VALUES (1, 144)');
    $pdo->exec("INSERT INTO saved_palette_set_photos VALUES (72, 1, 'full')");

    $migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_05_001_photo_library_palette.sql');
    assert_true(!str_contains($migration, 'saved_palette_set_photos'));
    $start = strpos($migration, 'INSERT INTO photo_palette_conflicts_must_be_resolved');
    $end = strpos($migration, 'START TRANSACTION;', $start);
    $backfill = substr($migration, $start, $end - $start);
    $create = 'CREATE TEMPORARY TABLE photo_palette_conflicts_must_be_resolved (photo_library_id INTEGER PRIMARY KEY, palette_id INTEGER NOT NULL)';
    $pdo->exec($create);
    $pdo->exec($backfill);
    assert_equals(18, (int)$pdo->query('SELECT palette_id FROM photo_palette_conflicts_must_be_resolved WHERE photo_library_id = 72')->fetchColumn());

    foreach ([[72, 99, 0], [72, null, 1]] as $conflict) {
        $pdo->exec('DROP TABLE photo_palette_conflicts_must_be_resolved');
        $pdo->exec($create);
        $pdo->exec('DELETE FROM project_photos');
        $pdo->prepare('INSERT INTO project_photos VALUES (?, ?, ?)')->execute($conflict);
        $rejected = false;
        try { $pdo->exec($backfill); }
        catch (PDOException $e) { $rejected = true; }
        assert_true($rejected, 'Real palette and Before/After conflicts must still stop the migration');
    }
});
