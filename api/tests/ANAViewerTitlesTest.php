<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\ANA\Repos\PdoANAReportRepository;

test('ANA viewer titles preserve source groups, counts and missing viewers', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE palette_viewers (palette_viewer_id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE analytics_events (
        resource_id INTEGER, resource_type TEXT, source_key TEXT,
        event_key TEXT, created_at TEXT
    )');
    $pdo->exec("INSERT INTO palette_viewers VALUES (84, '  Ulloa Exterior  '), (85, '   ')");
    $insert = $pdo->prepare('INSERT INTO analytics_events VALUES (?, ?, ?, ?, ?)');
    foreach ([[84, 'client'], [84, 'client'], [84, null], [85, null], [99, null]] as [$id, $source]) {
        $insert->execute([$id, 'palette_viewer', $source, 'visit', '2026-10-05 18:00:00']);
    }
    $repo = new PdoANAReportRepository($pdo);
    $rows = $repo->countEventsByResourceType('palette_viewer', 'visit');
    assert_equals(4, count($rows));
    foreach ($rows as $row) {
        $id = (int)$row['resource_id'];
        assert_equals($id === 84 ? 'Ulloa Exterior' : "Viewer #{$id}", $row['title']);
        assert_equals($id === 84 && $row['source_key'] === 'client' ? 2 : 1, (int)$row['event_count']);
        assert_equals('2026-10-05 18:00:00', $row['last_visit']);
    }
    assert_equals([], $repo->countEventsByResourceType('palette_viewer', 'missing'));

    $insert->execute([56, 'playlist', 'client', 'visit', '2026-10-05 18:00:00']);
    $playlist = $repo->countEventsByResourceType('playlist', 'visit');
    assert_equals(1, count($playlist));
    assert_true(!array_key_exists('title', $playlist[0]));
});
