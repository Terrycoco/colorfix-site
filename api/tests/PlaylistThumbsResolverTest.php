<?php
declare(strict_types=1);

require_once __DIR__ . '/PainterViewerRexTest.php';

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\DTO\RexResolutionRequest;
use App\REX\Resolvers\PlaylistThumbsResolver;
use App\REX\Services\RexPlaylistExperienceSyncService;

function playlist_thumbs_fixture(string $experience): PDO
{
    $pdo = painter_viewer_rex_fixture();
    foreach ([
        'playlists' => ['title', 'type', 'is_active', 'is_public', 'slug', 'headline',
            'page_title', 'meta_description', 'dek', 'intro_html', 'body_html',
            'hero_image_id', 'hero_image_url', 'hero_alt', 'indexable', 'published_at', 'updated_at'],
        'photo_library' => ['title'],
        'project_palettes' => ['created_at'],
        'rex_reservations' => ['label', 'admin_note', 'qr_key', 'context_json',
            'created_at', 'updated_at', 'fallback_rex_id', 'experience_key'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} TEXT");
        }
    }
    $pdo->exec("INSERT INTO playlists (playlist_id,project_id,title) VALUES (1,7,'Test House')");
    $pdo->exec('UPDATE projects SET playlist_id=1');
    $pdo->exec('CREATE TABLE rex_aliases (id INTEGER PRIMARY KEY, reservation_id INTEGER, alias TEXT)');
    $pdo->exec('CREATE TABLE project_photos (project_id INTEGER, photo_library_id INTEGER,
        `use` INTEGER, palette_id INTEGER, zoom INTEGER, main INTEGER, `before` INTEGER, sort_order INTEGER)');
    $pdo->exec("INSERT INTO photo_library (photo_library_id,rel_path,alt_text) VALUES
        (41,'/photos/kitchen.jpg','Kitchen'), (42,'/photos/exterior.jpg','Exterior'),
        (43,'/photos/unused.jpg','Unused')");
    $pdo->exec('INSERT INTO project_photos VALUES
        (7,41,1,11,0,1,0,1),(7,42,1,12,0,1,0,0),(7,43,0,12,1,0,0,2)');
    $stmt = $pdo->prepare("INSERT INTO palette_viewers
        (palette_viewer_id,saved_palette_id,format,title,is_active,created_at) VALUES
        (90,11,?,'Kitchen',1,'2026-10-05'),(91,12,?,'Exterior',1,'2026-10-05'),
        (92,12,'painter','Painter',1,'2026-10-05'),(93,12,'client','Inactive',0,'2026-10-05')");
    $stmt->execute([$experience, $experience]);
    $stmt = $pdo->prepare("INSERT INTO rex_reservations
        (id,token,label,resolver_key,resource_type,resource_id,status,experience_key) VALUES
        (1,'parent','Playlist','playlist_experience','playlist',1,'active',?),
        (2,'thumbs','Thumbs','playlist_thumbs','playlist',1,'active',?),
        (3,'kitchen','Kitchen','viewer','palette_viewer',90,'active',NULL),
        (4,'exterior','Exterior','viewer','palette_viewer',91,'active',NULL),
        (5,'painter','Painter','viewer','palette_viewer',92,'active',NULL),
        (6,'inactive','Inactive','viewer','palette_viewer',93,'active',NULL),
        (7,'revoked','Revoked','viewer','palette_viewer',91,'revoked',NULL)");
    $stmt->execute([$experience, $experience]);
    $pdo->exec("INSERT INTO rex_reservation_links
        (id,parent_reservation_id,child_reservation_id,relationship_key,sort_order) VALUES
        (1,1,2,'thumbs',100),(2,1,3,'viewer',1),(3,1,4,'viewer',0),
        (4,1,5,'viewer',0),(5,1,6,'viewer',0),(6,1,7,'viewer',0),(7,1,3,'viewer',2)");
    return $pdo;
}

foreach (['client', 'concept', 'public'] as $experience) {
    test("{$experience} thumbnail REX renders current PV data without falling back", function () use ($experience) {
        $pdo = playlist_thumbs_fixture($experience);
        $reservation = (new PdoRexReservationRepository($pdo))->findById(2);
        $result = (new PlaylistThumbsResolver($pdo))->resolve(new RexResolutionRequest(
            reservation: $reservation, matchedBy: 'token', lookupValue: 'thumbs',
            resourceType: 'playlist', resourceId: 1, context: [],
        ));
        $collection = $result->destination['collection'];
        assert_equals($experience, $collection['experience_key']);
        assert_equals([91, 90], array_column($collection['items'], 'pv_id'));
        assert_equals(['/t/exterior', '/t/kitchen'], array_column($collection['items'], 'viewer_url'));
        assert_equals('Green', $collection['items'][0]['swatches'][0]['name']);
        assert_equals('/photos/exterior.jpg', $collection['items'][0]['photo_url']);
        assert_equals('/t/parent', $collection['parent_url']);
    });
}

test('project client sync uses FINAL palettes instead of old concept slide palettes', function () {
    $pdo = playlist_thumbs_fixture('client');
    $pdo->exec('UPDATE palette_viewers SET is_active=0 WHERE palette_viewer_id=85');
    $pdo->exec("INSERT INTO saved_palettes VALUES (13,'Old concept','Old','exterior')");
    $pdo->exec("INSERT INTO project_palettes (project_palette_id,project_id,saved_palette_id,area_label,note,is_final,order_index)
        VALUES (103,7,13,'Exterior','',0,0)");
    $service = new RexPlaylistExperienceSyncService($pdo);
    $method = new ReflectionMethod($service, 'buildExperiencePlan');
    $items = [['playlist_item_id' => 1, 'client' => 1, 'concept' => 1, 'order_index' => 0]];
    $references = [1 => ['saved_palette_id' => 13]];
    $profile = ['slide_flag' => 'client', 'viewer_formats' => ['client','painter'], 'thumbs_format' => 'client'];
    $plan = $method->invoke($service, 1, 'Test House', 'client', $profile, $items, $references, 7);
    assert_equals(2, $plan['primary_viewer_count']);
    assert_true($plan['thumbs_required']);
    assert_equals([12,11], array_column($plan['desired_viewers'], 'saved_palette_id'));
    assert_equals(['client','client'], array_column($plan['desired_viewers'], 'viewer_format'));
    $concept = $method->invoke($service, 1, 'Test House', 'concept',
        ['slide_flag' => 'concept', 'viewer_formats' => ['concept'], 'thumbs_format' => 'concept'],
        $items, $references, 7);
    assert_equals(0, $concept['primary_viewer_count']);
    assert_equals(13, $concept['warnings'][0]['saved_palette_id']);
});
