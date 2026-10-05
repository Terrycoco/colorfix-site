<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexViewerPlaylistLinker;

function viewer_playlist_linker_fixture(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE palette_viewers (palette_viewer_id INTEGER PRIMARY KEY, saved_palette_id INTEGER, project_id INTEGER, format TEXT, is_active INTEGER)');
    $pdo->exec('CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY, project_id INTEGER)');
    $pdo->exec('CREATE TABLE playlist_items (playlist_id INTEGER, saved_palette_id INTEGER, order_index INTEGER)');
    $pdo->exec('CREATE TABLE project_palettes (project_id INTEGER, saved_palette_id INTEGER, order_index INTEGER)');
    $pdo->exec('CREATE TABLE rex_reservations (id INTEGER PRIMARY KEY, token TEXT, label TEXT, admin_note TEXT, qr_key TEXT, resolver_key TEXT, resource_type TEXT, resource_id INTEGER, context_json TEXT, status TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT, fallback_rex_id INTEGER, experience_key TEXT)');
    $pdo->exec('CREATE TABLE rex_reservation_links (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_reservation_id INTEGER, child_reservation_id INTEGER, relationship_key TEXT, sort_order INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(parent_reservation_id, child_reservation_id, relationship_key))');
    $pdo->exec("INSERT INTO playlists VALUES (56, 1), (57, 2)");
    $pdo->exec("INSERT INTO playlist_items VALUES (56, 157, 12), (56, 158, 4), (57, 999, 0)");
    $pdo->exec("INSERT INTO project_palettes VALUES (1, 162, 0), (1, 157, 2), (2, 999, 0)");
    $pdo->exec("INSERT INTO palette_viewers VALUES (78,157,NULL,'client',1), (79,157,NULL,'concept',1), (82,158,NULL,'concept',1), (84,NULL,1,'painter',1), (85,162,NULL,'client',1), (86,162,NULL,'client',0)");
    $pdo->exec("INSERT INTO rex_reservations (id,token,label,resolver_key,resource_type,resource_id,status,experience_key) VALUES
        (161,'concept','Concept','playlist_experience','playlist',56,'active','concept'),
        (162,'client','Client','playlist_experience','playlist',56,'active','client'),
        (163,'other','Other','playlist_experience','playlist',57,'active','client'),
        (164,'revoked','Revoked','playlist_experience','playlist',56,'revoked','client'),
        (165,'living','Living','viewer','palette_viewer',78,'active',NULL),
        (166,'living-concept','Living Concept','viewer','palette_viewer',79,'active',NULL),
        (169,'exterior-concept','Exterior Concept','viewer','palette_viewer',82,'active',NULL),
        (176,'painter','Painter','viewer','palette_viewer',84,'active',NULL),
        (177,'exterior','Exterior','viewer','palette_viewer',85,'active',NULL),
        (178,'inactive','Inactive','viewer','palette_viewer',86,'active',NULL)");
    return $pdo;
}

test('fetching or reusing a client viewer REX links all its project playlists exactly once', function () {
    $pdo = viewer_playlist_linker_fixture();
    $repo = new PdoRexReservationRepository($pdo);
    $linker = new RexViewerPlaylistLinker($pdo);
    $linker->link($repo->findById(165));
    $linker->link($repo->findById(177));
    $linker->link($repo->findById(177));
    assert_equals([177, 165], array_map('intval', $pdo->query('SELECT child_reservation_id FROM rex_reservation_links WHERE parent_reservation_id=162 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN)));
    assert_equals(2, (int)$pdo->query('SELECT COUNT(*) FROM rex_reservation_links')->fetchColumn());
});

test('concept viewer links use playlist membership and never leak into client or painter', function () {
    $pdo = viewer_playlist_linker_fixture();
    $repo = new PdoRexReservationRepository($pdo);
    $linker = new RexViewerPlaylistLinker($pdo);
    foreach ([166, 169, 176, 178] as $id) { $linker->link($repo->findById($id)); }
    assert_equals([169, 166], array_map('intval', $pdo->query('SELECT child_reservation_id FROM rex_reservation_links WHERE parent_reservation_id=161 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN)));
    assert_equals(2, (int)$pdo->query('SELECT COUNT(*) FROM rex_reservation_links')->fetchColumn());
});
