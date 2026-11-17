<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\PLAYLISTS\Repos\PdoPlaylistRepository;

// Standalone regression: share titles must not depend on playback inclusion flags.
(static function (): void {
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE playlist_items (playlist_item_id INTEGER, playlist_id INTEGER, order_index INTEGER, item_type TEXT, title TEXT, is_active INTEGER, site INTEGER, concept INTEGER, client INTEGER, showcase INTEGER)');
$insert = $pdo->prepare('INSERT INTO playlist_items VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0)');
$insert->execute([1, 60, 1, 'intro', 'Not the cover', 1]);
$insert->execute([2, 60, 2, 'cover-image', 'Retired cover', 0]);
$insert->execute([3, 60, 3, 'cover-image', '  ', 1]);
$insert->execute([4, 60, 4, 'cover-image', '  Authored Cover & Title  ', 1]);
$insert->execute([5, 61, 1, 'cover-image', 'Other playlist', 1]);
$repo = new PdoPlaylistRepository($pdo);
if ($repo->getCoverTitle(60) !== 'Authored Cover & Title') throw new RuntimeException('Cover title must win even with every playback flag disabled.');
if ($repo->getCoverTitle(999) !== '') throw new RuntimeException('Missing cover must allow existing fallback.');
$pdo->exec("UPDATE playlist_items SET title = '' WHERE playlist_item_id = 4");
if ($repo->getCoverTitle(60) !== '') throw new RuntimeException('Blank covers must allow existing fallback.');
$pdo->exec("UPDATE playlist_items SET title = 'Updated cover' WHERE playlist_item_id = 4");
if ($repo->getCoverTitle(60) !== 'Updated cover') throw new RuntimeException('Cover edits must be read without rebuilding reservations.');
echo "PASS: cover share title ignores playback flags, excludes inactive/other playlists, handles blank/missing covers, and reflects edits.\n";
})();
