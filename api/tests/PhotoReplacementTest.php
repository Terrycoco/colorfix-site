<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\PHOTOS\Services\PhotoReplacementService;

function photo_replacement_fixture(): array
{
    $root = sys_get_temp_dir() . '/photo-replace-' . bin2hex(random_bytes(6));
    mkdir($root . '/photos/cache/thumbs', 0775, true);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9S8AAAAASUVORK5CYII=');
    file_put_contents($root . '/old.png', $png);
    file_put_contents($root . '/source.png', $png);
    file_put_contents($root . '/photos/cache/thumbs/pl-736-w480-old.png', $png);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-10-04 23:00:00');
    $pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY, asset_library_id INTEGER, source_type TEXT, source_id INTEGER, client_id INTEGER, photo_permission_status TEXT, rel_path TEXT, title TEXT, tags TEXT, alt_text TEXT, show_in_gallery INTEGER, has_palette INTEGER, is_inactive INTEGER, updated_at TEXT)');
    $pdo->exec("INSERT INTO photo_library VALUES (736, NULL, 'extra_photo', NULL, NULL, 'unknown', '/old.png', 'Back', 'exterior', '', 0, 1, 0, NULL), (900, NULL, 'extra_photo', NULL, NULL, 'unknown', '/source.png', 'New', 'exterior', '', 0, 0, 0, NULL)");
    $pdo->exec('CREATE TABLE asset_library (asset_library_id INTEGER PRIMARY KEY, rel_path TEXT, mime_type TEXT, width INTEGER, height INTEGER, file_size_bytes INTEGER, checksum TEXT, updated_at TEXT)');
    $pdo->exec("INSERT INTO asset_library (asset_library_id,rel_path) VALUES (10,'/old.png')");
    $pdo->exec('UPDATE photo_library SET asset_library_id=10 WHERE photo_library_id=736');
    $pdo->exec('CREATE TABLE playlist_items (photo_library_id INTEGER, image_url TEXT)');
    $pdo->exec("INSERT INTO playlist_items VALUES (736,'photo:736|/old.png'), (NULL,'photo:736|/old.png'), (900,'photo:900|/source.png')");
    $pdo->exec('CREATE TABLE project_photos (photo_library_id INTEGER, palette_id INTEGER, `use` INTEGER, zoom INTEGER, main INTEGER, `before` INTEGER, sort_order INTEGER)');
    $pdo->exec('INSERT INTO project_photos VALUES (736, 162, 1, 1, 0, 0, 5)');
    return [$pdo, $root, new PhotoReplacementService($pdo, $root)];
}

function cleanup_photo_replacement_fixture(string $root): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}

test('photo replacement preserves identity and project settings, refreshes references and deletes superseded bytes', function () {
    [$pdo, $root, $service] = photo_replacement_fixture();
    try {
        $settings = $pdo->query('SELECT * FROM project_photos')->fetchAll(PDO::FETCH_ASSOC);
        $photo = $service->replace(736, $root . '/source.png');
        assert_equals(736, (int)$photo['photo_library_id']);
        assert_equals(2, (int)$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn());
        assert_equals($settings, $pdo->query('SELECT * FROM project_photos')->fetchAll(PDO::FETCH_ASSOC));
        assert_true(!file_exists($root . '/old.png'));
        assert_true(file_exists($root . '/source.png'));
        assert_true(file_exists($root . $photo['rel_path']));
        assert_equals($photo['rel_path'], $pdo->query('SELECT rel_path FROM asset_library WHERE asset_library_id=10')->fetchColumn());
        assert_true(!file_exists($root . '/photos/cache/thumbs/pl-736-w480-old.png'));
        $refs = $pdo->query('SELECT image_url FROM playlist_items WHERE photo_library_id=736')->fetchAll(PDO::FETCH_COLUMN);
        assert_equals(2, count($refs));
        assert_true(str_contains($refs[0], $photo['rel_path']));
        assert_true($service->localPath('/../outside.png') === null);
        assert_true($service->localPath('https://other.test/source.png') === null);
    } finally { cleanup_photo_replacement_fixture($root); }
});

test('replacement failure preserves the original photo and rolls back references', function () {
    [$pdo, $root, $service] = photo_replacement_fixture();
    try {
        $pdo->exec('DROP TABLE playlist_items');
        try { $service->replace(736, $root . '/source.png'); throw new LogicException('Expected failure'); }
        catch (PDOException $e) {}
        assert_equals('/old.png', $pdo->query('SELECT rel_path FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        assert_true(file_exists($root . '/old.png'));
        assert_equals([], glob($root . '/photos/replacements/*'));
    } finally { cleanup_photo_replacement_fixture($root); }
});

test('a separate library photo sharing the original file is not damaged', function () {
    [$pdo, $root, $service] = photo_replacement_fixture();
    try {
        $pdo->exec("UPDATE photo_library SET rel_path='/old.png' WHERE photo_library_id=900");
        $service->replace(736, $root . '/source.png');
        assert_true(file_exists($root . '/old.png'));
    } finally { cleanup_photo_replacement_fixture($root); }
});
