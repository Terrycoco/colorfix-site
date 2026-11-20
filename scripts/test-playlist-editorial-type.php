<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/autoload.php';

use App\PLAYLISTS\Managers\PlaylistManager;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;

// Isolated test schema only; never connects to the application database.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn() => '2026-11-18 00:00:00');
$pdo->exec("CREATE TABLE playlists (
 playlist_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, title TEXT, type TEXT,
 editorial_type TEXT DEFAULT 'fixture-default', is_active INTEGER, is_public INTEGER,
 slug TEXT, headline TEXT, page_title TEXT, meta_description TEXT, dek TEXT,
 intro_html TEXT, body_html TEXT, hero_image_id INTEGER, hero_image_url TEXT, hero_alt TEXT,
 indexable INTEGER, published_at TEXT, updated_at TEXT, is_retired INTEGER DEFAULT 0)");
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER, rel_path TEXT, alt_text TEXT, title TEXT)');
$manager = new PlaylistManager($pdo);
$repo = new PdoPlaylistRepository($pdo);
$base = ['title' => 'Editorial fixture', 'type' => 'normal', 'project_id' => 7];
$id = $manager->saveAdminPlaylist($base)['playlist_id'];
$check = static function ($expected) use ($repo, $id): void {
    $actual = $repo->getAdminRowById($id)['editorial_type'];
    if ($actual !== $expected) throw new RuntimeException('Unexpected editorial_type: ' . var_export($actual, true));
};
$check('fixture-default');
$manager->saveAdminPlaylist($base + ['playlist_id' => $id, 'editorial_type' => 'art-study']);
$check('art-study');
$manager->saveAdminPlaylist($base + ['playlist_id' => $id]);
$check('art-study');
foreach ([$repo->listAdminRows(), $repo->listAdminRowsByProjectId(7)] as $rows) {
    if ($rows[0]['editorial_type'] !== 'art-study') throw new RuntimeException('List lost editorial_type');
}
$manager->saveAdminPlaylist($base + ['playlist_id' => $id, 'editorial_type' => null]);
$check(null);
$new = $manager->saveAdminPlaylist($base + ['editorial_type' => 'design'])['playlist_id'];
if ($repo->getAdminRowById($new)['editorial_type'] !== 'design') throw new RuntimeException('Insert lost editorial_type');
try {
    $manager->saveAdminPlaylist($base + ['playlist_id' => $id, 'editorial_type' => ['bad']]);
    throw new RuntimeException('Expected invalid type rejection');
} catch (InvalidArgumentException $expected) {}
echo "PASS: manager/repository insert, update, get, both admin lists, omitted value preservation, DB default, explicit null and invalid input.\n";
