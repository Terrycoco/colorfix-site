<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Repos\PdoProjectRepository;
use App\PROJECTS\Managers\ProjectManager;

function projectRoomsTestDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('CONCAT_WS', static function ($separator, ...$values) {
        return implode($separator, array_filter($values, static fn ($value) => $value !== null));
    });
    $pdo->exec('CREATE TABLE projects (
        id INTEGER PRIMARY KEY, project_name TEXT, client_id INTEGER,
        property_id INTEGER, playlist_id INTEGER, rooms TEXT
    )');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, name TEXT, address_id INTEGER)');
    $pdo->exec('CREATE TABLE addresses (
        id INTEGER PRIMARY KEY, street_1 TEXT, street_2 TEXT, city TEXT, state TEXT, postal_code TEXT
    )');
    $pdo->exec('CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY, title TEXT)');
    return $pdo;
}

test('project rooms round trip through create and every project read', function () {
    $pdo = projectRoomsTestDatabase();
    $repo = new PdoProjectRepository($pdo);
    $rooms = [['id' => 'living-dining', 'name' => 'Living & Dining Room']];
    $id = $repo->create('Test project', null, 4, null, $rooms);

    assert_equals($rooms, json_decode($pdo->query('SELECT rooms FROM projects')->fetchColumn(), true));
    assert_equals($rooms, $repo->findById($id)['rooms']);
    assert_equals($rooms, $repo->listAdminRows()[0]['rooms']);
    assert_equals($rooms, $repo->listByPropertyId(4)[0]['rooms']);
});

test('project room updates preserve omitted rooms and allow rename or clear', function () {
    $pdo = projectRoomsTestDatabase();
    $repo = new PdoProjectRepository($pdo);
    $rooms = [['id' => 'living', 'name' => 'Living Room']];
    $id = $repo->create('Test project', null, null, null, $rooms);

    $repo->update($id, 'Renamed project', null, null, null);
    assert_equals($rooms, $repo->findById($id)['rooms']);

    $renamed = [['id' => 'living', 'name' => 'Living & Dining Room']];
    $repo->update($id, 'Renamed project', null, null, null, $renamed);
    assert_equals($renamed, $repo->findById($id)['rooms']);

    $repo->update($id, 'Renamed project', null, null, null, []);
    assert_equals([], $repo->findById($id)['rooms']);
});

test('projects without rooms return an empty array', function () {
    $pdo = projectRoomsTestDatabase();
    $repo = new PdoProjectRepository($pdo);
    $id = $repo->create('New project', null, null, null);
    assert_equals([], $repo->findById($id)['rooms']);
    $pdo->exec('UPDATE projects SET rooms = NULL');
    assert_equals([], $repo->findById($id)['rooms']);
    assert_equals([], $repo->listAdminRows()[0]['rooms']);
    assert_equals(null, $repo->findById(999));
});

test('project manager saves trimmed rooms and preserves them for older callers', function () {
    $manager = new ProjectManager(projectRoomsTestDatabase());
    $id = $manager->saveProject([
        'project_name' => 'Project',
        'rooms' => [['id' => 'living', 'name' => ' Living Room ']],
    ]);
    $rooms = [['id' => 'living', 'name' => 'Living Room']];
    assert_equals($rooms, $manager->getProject($id)['rooms']);
    $manager->saveProject(['project_id' => $id, 'project_name' => 'Renamed']);
    assert_equals($rooms, $manager->getProject($id)['rooms']);
    $manager->saveProject(['project_id' => $id, 'rooms' => []]);
    assert_equals([], $manager->getProject($id)['rooms']);
});

test('project manager rejects malformed or duplicate rooms without writing', function () {
    $pdo = projectRoomsTestDatabase();
    $manager = new ProjectManager($pdo);
    foreach ([
        null,
        'Living Room',
        ['id' => 'living', 'name' => 'Living Room'],
        [['id' => 'living', 'name' => '']],
        [['id' => '__all__', 'name' => 'Reserved']],
        [['id' => 'living', 'name' => 'Living Room'], ['id' => 'living', 'name' => 'Exterior']],
        [['id' => 'living', 'name' => 'Living Room'], ['id' => 'other', 'name' => ' living room ']],
    ] as $rooms) {
        $rejected = false;
        try {
            $manager->saveProject(['rooms' => $rooms]);
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        assert_equals(true, $rejected);
        assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    }
});
