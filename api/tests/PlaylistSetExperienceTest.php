<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PLAYLISTS\Repos\PdoPlaylistSetRepository;
use App\PLAYLISTS\Services\PlaylistSetService;

function set_experience_fixture(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', fn () => '2026-10-06 12:00:00');
    $pdo->exec('CREATE TABLE playlist_sets (id INTEGER PRIMARY KEY, updated_at TEXT)');
    $pdo->exec('INSERT INTO playlist_sets VALUES (1, NULL), (2, NULL)');
    $pdo->exec("CREATE TABLE playlist_set_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, playlist_set_id INTEGER,
        playlist_id INTEGER, target_set_id INTEGER, item_type TEXT,
        experience_key TEXT NOT NULL DEFAULT 'public', sort_order INTEGER,
        created_at TEXT, updated_at TEXT
    )");
    return $pdo;
}

test('set memberships retain independent experiences and defaults through reordering', function () {
    $pdo = set_experience_fixture();
    $repo = new PdoPlaylistSetRepository($pdo);
    $repo->replaceItems(1, [['playlist_id' => 29, 'experience_key' => 'showcase'], ['playlist_id' => 12]]);
    $repo->replaceItems(2, [['playlist_id' => 29, 'experience_key' => 'public']]);
    $items = $repo->listItems(1);
    assert_equals('showcase', $items[0]->experienceKey);
    assert_equals('public', $items[1]->experienceKey);
    assert_equals('public', $repo->listItems(2)[0]->experienceKey);
    $repo->replaceItems(1, [['playlist_id' => 12], ['playlist_id' => 29, 'experience_key' => 'showcase']]);
    assert_equals('showcase', $repo->listItems(1)[1]->experienceKey);
    $repo->replaceItems(1, [['item_type' => 'set', 'target_set_id' => 2, 'experience_key' => 'client']]);
    assert_equals('public', $repo->listItems(1)[0]->experienceKey);
});

test('invalid experience is rejected before deleting existing membership', function () {
    $pdo = set_experience_fixture();
    $repo = new PdoPlaylistSetRepository($pdo);
    $repo->replaceItems(1, [['playlist_id' => 29]]);
    $pdo->exec('CREATE TABLE player_experiences (
        player_experience_id INTEGER, experience_key TEXT, name TEXT, slide_flag TEXT,
        palette_viewer_key TEXT, rex_parent_experience_key TEXT, cta_page_id INTEGER, is_active INTEGER
    )');
    $rejected = false;
    try {
        (new PlaylistSetService($pdo))->replaceItems(1, [['playlist_id' => 29, 'experience_key' => 'invalid']]);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    assert_true($rejected);
    assert_equals('public', $repo->listItems(1)[0]->experienceKey);
});
