<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\ANA\DTO\ANAEvent;
use App\ANA\Repos\PdoANAEventRepository;
use App\ANA\Repos\PdoANAReportRepository;
use App\ANA\Repos\PdoANASourceRepository;
use App\ANA\Services\ANAService;

test('ANA src_params preserves sources and reports, with immediate database updates', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->sqliteCreateFunction('NOW', fn () => '2026-10-08 12:00:00');
    $pdo->exec('CREATE TABLE analytics_sources (
        source_key TEXT PRIMARY KEY, label TEXT NOT NULL,
        is_active INTEGER NOT NULL, created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE src_params (
        id INTEGER PRIMARY KEY AUTOINCREMENT, src VARCHAR(20) NOT NULL UNIQUE,
        label VARCHAR(100) NOT NULL, description VARCHAR(255),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    // Exact source keys found in the existing production lookup; fixtures only.
    $existing = ['admin', 'bc', 'client', 'pin', 'share', 'sign1', 'site', 'yt'];
    $insert = $pdo->prepare('INSERT INTO analytics_sources VALUES (?, ?, ?, ?)');
    foreach ($existing as $src) {
        $insert->execute([$src, 'Label ' . $src, 1, '2026-10-05 10:00:00']);
    }
    $insert->execute(['inactive_fixture', 'Inactive fixture', 0, '2026-10-05 10:00:00']);

    $migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_08_001_src_params.sql');
    $seedStart = strpos($migration, 'INSERT INTO src_params');
    $seedEnd = strpos($migration, ';', $seedStart);
    $seed = substr($migration, $seedStart, $seedEnd - $seedStart + 1);
    $pdo->exec($seed);
    $pdo->exec($seed);
    assert_equals(9, (int)$pdo->query('SELECT COUNT(*) FROM src_params')->fetchColumn());
    assert_equals('2026-10-05 10:00:00', $pdo->query("SELECT created_at FROM src_params WHERE src = 'client'")->fetchColumn());
    assert_equals('Label client', $pdo->query("SELECT label FROM src_params WHERE src = 'client'")->fetchColumn());
    assert_true(!str_contains($migration, 'MODIFY source_key'));

    $pdo->exec('CREATE TABLE analytics_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT, is_test INTEGER,
        reservation_id INTEGER, resolver_key TEXT, resource_type TEXT,
        resource_id INTEGER, experience_key TEXT, source_key TEXT,
        session_id TEXT, referrer TEXT, viewer_id TEXT, path TEXT,
        payload_json TEXT, created_at TEXT,
        FOREIGN KEY (source_key) REFERENCES src_params(src)
    )');
    $sources = new PdoANASourceRepository($pdo);
    $service = new ANAService(new PdoANAEventRepository($pdo, $sources));
    $event = fn (?string $src) => new ANAEvent(
        eventKey: 'visit', reservationId: 162, resolverKey: 'playlist_experience',
        resourceType: 'playlist', resourceId: 56, experienceKey: 'client', src: $src,
        sessionId: 'unchanged-session', referrer: 'https://example.test/',
        viewerId: 'unchanged-viewer', path: '/t/fixture', payload: ['test' => true]
    );
    foreach ($existing as $src) {
        assert_true($sources->isValidSource($src));
        $service->record($event($src));
    }
    assert_true($sources->isValidSource('inactive_fixture'));
    $service->record($event(null));
    $report = new PdoANAReportRepository($pdo);
    $before = $report->countEventsByResourceType('playlist', 'visit');
    assert_equals(9, count($before));
    foreach ($before as $group) { assert_equals(1, (int)$group['event_count']); }

    assert_true(!$sources->isValidSource('new_fixture'));
    $pdo->exec("INSERT INTO src_params(src, label) VALUES ('new_fixture', 'New fixture')");
    assert_true($sources->isValidSource('new_fixture'));
    $id = $service->record($event('new_fixture'));
    $row = $pdo->query('SELECT * FROM analytics_events WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    assert_equals('new_fixture', $row['source_key']);
    assert_equals('client', $row['experience_key']);
    assert_equals(162, (int)$row['reservation_id']);
    assert_equals('unchanged-session', $row['session_id']);
    assert_equals('unchanged-viewer', $row['viewer_id']);
    assert_equals('https://example.test/', $row['referrer']);
    assert_equals('/t/fixture', $row['path']);
    assert_equals(['test' => true], json_decode($row['payload_json'], true));
    assert_equals('2026-10-08 12:00:00', $row['created_at']);

    foreach (['unknown_fixture', '', "client' OR 1=1 --"] as $src) {
        $rejected = false;
        try { $service->record($event($src)); }
        catch (RuntimeException $e) { $rejected = $e->getMessage() === 'Unrecognized analytics source.'; }
        assert_true($rejected);
    }
    assert_equals(10, (int)$pdo->query('SELECT COUNT(*) FROM analytics_events')->fetchColumn());
    $after = array_values(array_filter(
        $report->countEventsByResourceType('playlist', 'visit'),
        fn (array $group) => $group['source_key'] !== 'new_fixture'
    ));
    assert_equals($before, $after);
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM analytics_events WHERE source_key IS NULL')->fetchColumn());
});
