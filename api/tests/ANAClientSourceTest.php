<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\ANA\DTO\ANAEvent;
use App\ANA\Contracts\ANASourceRepositoryInterface;
use App\ANA\Repos\PdoANAEventRepository;

test('client source migration allows tagged playlist visits and is repeatable', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->sqliteCreateFunction('NOW', fn () => '2026-10-05 15:00:00');
    $pdo->exec('CREATE TABLE analytics_sources (
        source_key TEXT PRIMARY KEY, label TEXT NOT NULL, is_active INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE analytics_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT, is_test INTEGER,
        reservation_id INTEGER, resolver_key TEXT, resource_type TEXT,
        resource_id INTEGER, experience_key TEXT, source_key TEXT,
        session_id TEXT, referrer TEXT, viewer_id TEXT, path TEXT,
        payload_json TEXT, created_at TEXT,
        FOREIGN KEY (source_key) REFERENCES analytics_sources(source_key)
    )');
    // This historical test exercises the old FK migration, not current source lookup.
    $repo = new PdoANAEventRepository($pdo, new class implements ANASourceRepositoryInterface {
        public function isValidSource(string $src): bool { return true; }
    });
    $event = new ANAEvent(
        eventKey: 'visit', reservationId: 162,
        resolverKey: 'playlist_experience', resourceType: 'playlist',
        resourceId: 56, experienceKey: 'client', src: 'client'
    );
    $rejected = false;
    try {
        $repo->record($event);
    } catch (PDOException $e) {
        $rejected = true;
    }
    assert_true($rejected);

    $sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_05_002_analytics_client_source.sql');
    $pdo->exec($sql);
    $pdo->exec($sql);
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM analytics_sources')->fetchColumn());
    $id = $repo->record($event);
    $row = $pdo->query('SELECT * FROM analytics_events WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    assert_equals('client', $row['source_key']);
    assert_equals('client', $row['experience_key']);
    assert_equals(56, (int)$row['resource_id']);
    assert_equals(162, (int)$row['reservation_id']);
    assert_equals('2026-10-05 15:00:00', $row['created_at']);
});
