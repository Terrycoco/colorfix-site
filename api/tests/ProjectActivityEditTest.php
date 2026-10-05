<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Repos\PdoProjectActivityRepository;

function activity_edit_fixture(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE project_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, activity_date TEXT,
        description TEXT, hours REAL, miles REAL, amount REAL, entry_type TEXT,
        event_type TEXT, resource_type TEXT, resource_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    return $pdo;
}

test('editing a system activity changes its description without duplicating or losing identity', function () {
    $pdo = activity_edit_fixture();
    $repo = new PdoProjectActivityRepository($pdo);
    $original = $repo->createSystem(1, 'Sent Final Color Approval', 'document_sent', 'project_document', 8, '2026-10-05');
    $edited = $repo->updateEntry((int)$original['id'], 1, '2026-10-05', 'Sent Final Color Approval V2');
    assert_equals($original['id'], $edited['id']);
    assert_equals('Sent Final Color Approval V2', $edited['description']);
    foreach (['entry_type', 'event_type', 'resource_type', 'resource_id', 'created_at'] as $field) {
        assert_equals($original[$field], $edited[$field]);
    }
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
    $repo->updateEntry((int)$original['id'], 1, '2026-10-05', 'Sent Final Color Approval V2');
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
});

test('editing manual activity updates its fields while new entries still insert', function () {
    $pdo = activity_edit_fixture();
    $repo = new PdoProjectActivityRepository($pdo);
    $original = $repo->createManual(1, '2026-10-04', 'Visit', 1, 10, 50);
    $edited = $repo->updateEntry((int)$original['id'], 1, '2026-10-05', 'Second visit', 2, 15, 75);
    assert_equals('Second visit', $edited['description']);
    assert_equals('2026-10-05', $edited['activity_date']);
    assert_equals(2.0, $edited['hours']);
    assert_equals(15.0, $edited['miles']);
    assert_equals(75.0, $edited['amount']);
    $repo->createManual(1, '2026-10-05', 'New entry');
    assert_equals(2, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
});

test('missing or other-project activity cannot update or create a replacement', function () {
    $pdo = activity_edit_fixture();
    $repo = new PdoProjectActivityRepository($pdo);
    $original = $repo->createManual(1, '2026-10-05', 'Original');
    foreach ([[999, 1], [(int)$original['id'], 2]] as [$id, $project]) {
        $rejected = false;
        try {
            $repo->updateEntry($id, $project, '2026-10-05', 'Changed');
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        assert_true($rejected);
    }
    assert_equals('Original', $repo->findById((int)$original['id'])['description']);
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
});
