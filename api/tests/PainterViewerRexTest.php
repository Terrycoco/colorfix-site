<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\REX\DTO\RexReservation;
use App\REX\DTO\RexResolutionRequest;
use App\REX\Resolvers\ViewerResolver;
use App\PALETTES\Repos\PdoPVRepository;

function painter_viewer_rex_fixture(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schemas = [
        'palette_viewers' => 'palette_viewer_id INTEGER PRIMARY KEY, saved_palette_id INTEGER,
            project_id INTEGER, format TEXT, template_key TEXT, kicker_text TEXT, title TEXT,
            intro TEXT, notes TEXT, cta_label TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT',
        'projects' => 'id INTEGER PRIMARY KEY, project_name TEXT, playlist_id INTEGER',
        'project_palettes' => 'project_palette_id INTEGER PRIMARY KEY, project_id INTEGER,
            saved_palette_id INTEGER, area_label TEXT, note TEXT, is_final INTEGER, order_index INTEGER',
        'palette_viewer_project_palettes' => 'palette_viewer_project_palette_id INTEGER PRIMARY KEY,
            palette_viewer_id INTEGER, project_palette_id INTEGER, order_index INTEGER',
        'palette_viewer_photos' => 'palette_viewer_photo_id INTEGER PRIMARY KEY, palette_viewer_id INTEGER,
            photo_library_id INTEGER, photo_type TEXT, caption TEXT, rel_path TEXT,
            alt_text TEXT, updated_at TEXT, order_index INTEGER',
        'photo_library' => 'photo_library_id INTEGER PRIMARY KEY, rel_path TEXT,
            ai_alt_text TEXT, alt_text TEXT, updated_at TEXT',
        'playlists' => 'playlist_id INTEGER PRIMARY KEY, project_id INTEGER',
        'playlist_items' => 'playlist_item_id INTEGER PRIMARY KEY, playlist_id INTEGER,
            saved_palette_id INTEGER, photo_library_id INTEGER, image_url TEXT, title TEXT, order_index INTEGER,
            analyzer_role TEXT DEFAULT "ignore"',
        'saved_palettes' => 'id INTEGER PRIMARY KEY, display_title TEXT, nickname TEXT, palette_type TEXT',
        'saved_palette_members' => 'id INTEGER PRIMARY KEY, saved_palette_id INTEGER, color_id INTEGER,
            role_name TEXT, sheen TEXT, note TEXT, order_index INTEGER',
        'swatch_view' => 'id INTEGER PRIMARY KEY, name TEXT, brand TEXT, brand_name TEXT, code TEXT,
            hex6 TEXT, hcl_h REAL, hcl_c REAL, hcl_l REAL, int_only INTEGER, chip_num TEXT,
            cluster_id INTEGER, hue_cats TEXT, neutral_cats TEXT',
        'rex_reservations' => 'id INTEGER PRIMARY KEY, token TEXT, resolver_key TEXT,
            resource_type TEXT, resource_id INTEGER, status TEXT, revoked_at TEXT',
        'rex_reservation_links' => 'id INTEGER PRIMARY KEY, parent_reservation_id INTEGER,
            child_reservation_id INTEGER, relationship_key TEXT, sort_order INTEGER,
            created_at TEXT, updated_at TEXT',
    ];
    foreach ($schemas as $table => $columns) {
        $pdo->exec("CREATE TABLE {$table} ({$columns})");
    }
    $pdo->exec("INSERT INTO projects VALUES (7, 'Test House', NULL)");
    $pdo->exec("INSERT INTO palette_viewers
        (palette_viewer_id, saved_palette_id, project_id, format, title, is_active, created_at)
        VALUES (84, NULL, 7, 'painter', 'Painter Specifications', 1, '2026-10-04'),
               (85, 11, NULL, 'client', 'Kitchen Client', 1, '2026-10-04'),
               (86, 11, NULL, 'painter', 'Kitchen Painter', 1, '2026-10-04')");
    $pdo->exec("INSERT INTO saved_palettes VALUES
        (11, 'Kitchen Colors', 'Kitchen', 'interior'),
        (12, 'Exterior Colors', 'Exterior', 'exterior')");
    $pdo->exec("INSERT INTO project_palettes VALUES
        (101, 7, 11, 'Kitchen', 'Protect cabinets', 1, 1),
        (102, 7, 12, 'Exterior', 'Prep trim', 1, 0)");
    $pdo->exec("INSERT INTO palette_viewer_project_palettes VALUES
        (1, 84, 102, 0), (2, 84, 101, 1)");
    $pdo->exec("INSERT INTO swatch_view (id, name, hex6) VALUES
        (1, 'White', 'FFFFFF'), (2, 'Green', '448866')");
    $pdo->exec("INSERT INTO saved_palette_members VALUES
        (1, 11, 1, 'Walls', 'Eggshell', 'Two coats', 0),
        (2, 12, 2, 'Trim', 'Satin', 'Prime first', 0)");

    return $pdo;
}

test('painter REX preview describes all selected rooms without a single saved palette', function () {
    $resolver = new ViewerResolver(painter_viewer_rex_fixture());
    $descriptor = $resolver->previewDescribe('palette_viewer', 84, ['format' => 'painter']);
    $fields = array_column($descriptor->fields, 'value', 'label');

    assert_equals('Painter Specifications — Painter', $descriptor->title);
    assert_equals('Test House', $fields['Project']);
    assert_equals('2', $fields['Selected Palettes']);
    assert_equals('Exterior, Kitchen', $fields['Areas / Rooms']);
    assert_true(!isset($fields['Saved Palette ID']));
    assert_equals($descriptor->title, $resolver->previewDescribe('palette_viewer', 84, [])->title);
});

test('painter REX resolves a standalone viewer with ordered room specifications', function () {
    $resolver = new ViewerResolver(painter_viewer_rex_fixture());
    $reservation = new RexReservation(
        id: 1, token: 'painterToken', label: 'Painter Specifications', adminNote: null, qrKey: null,
        resolverKey: 'viewer', resourceType: 'palette_viewer', resourceId: 84,
        context: ['format' => 'painter'], status: 'active', revokedAt: null,
        createdAt: null, updatedAt: null,
    );
    $descriptor = $resolver->describe($reservation);
    assert_equals('Painter Specifications — Painter', $descriptor->title);
    $result = $resolver->resolve(new RexResolutionRequest(
        reservation: $reservation, matchedBy: 'token', lookupValue: 'painterToken',
        resourceType: 'palette_viewer', resourceId: 84, context: $reservation->context,
    ));
    $viewer = $result->destination['viewer'];

    assert_equals('painter', $result->destination['experience_key']);
    assert_equals(['Exterior', 'Kitchen'], array_column($viewer['plans'], 'title'));
    assert_equals('Green', $viewer['plans'][0]['swatches'][0]['name']);
    assert_equals('Satin', $viewer['plans'][0]['swatches'][0]['sheen']);
    assert_equals('Prep trim', $viewer['plans'][0]['painter_note']);
    assert_equals('White', $viewer['plans'][1]['swatches'][0]['name']);
    assert_equals('Test House', $viewer['painterView']['projectName']);
    assert_true(empty($viewer['meta']['not_final_warning']));
    assert_equals('/', $viewer['meta']['viewer_cta_url']);
    assert_equals('Painter Specifications', $result->shareMetadata->title);
});

test('REX keeps single palette client and painter previews working', function () {
    $resolver = new ViewerResolver(painter_viewer_rex_fixture());
    foreach ([85 => 'client', 86 => 'painter'] as $id => $format) {
        $descriptor = $resolver->previewDescribe('palette_viewer', $id, ['format' => $format]);
        $fields = array_column($descriptor->fields, 'value', 'label');
        assert_equals('11', $fields['Saved Palette ID']);
    }
});

test('standalone public PV keeps its own photos while project PV cannot restore them without Use selections', function () {
    $pdo = painter_viewer_rex_fixture();
    $pdo->exec("INSERT INTO saved_palettes VALUES (99,'Public Colors','Public','interior')");
    $pdo->exec("INSERT INTO palette_viewers (palette_viewer_id,saved_palette_id,format,title,is_active) VALUES (90,99,'public','Public Example',1)");
    $pdo->exec("INSERT INTO photo_library VALUES (41,'/photos/public.jpg','Public image',NULL,NULL)");
    $pdo->exec("INSERT INTO palette_viewer_photos (palette_viewer_photo_id,palette_viewer_id,photo_library_id,photo_type,rel_path,order_index)
        VALUES (1,90,41,'full','/photos/public.jpg',0),(2,85,41,'full','/photos/public.jpg',0)");
    $repo = new PdoPVRepository($pdo);
    assert_equals([41], array_column($repo->findById(90)['photos'], 'photo_library_id'));
    assert_equals([], $repo->findById(85)['photos']);
});

test('painter editor uses only checked project photos, not raw playlist images', function () {
    $pdo = painter_viewer_rex_fixture();
    $pdo->exec("INSERT INTO playlists VALUES (1, 7), (2, 8)");
    $pdo->exec("INSERT INTO photo_library VALUES
        (41, '/photos/kitchen.jpg', 'Kitchen rendering', NULL, '2026-10-04'),
        (42, '/photos/exterior.jpg', NULL, 'Exterior rendering', '2026-10-04'),
        (43, '/photos/kitchen-detail.jpg', 'Detail', NULL, NULL)");
    $pdo->exec("INSERT INTO playlist_items (playlist_item_id, playlist_id, saved_palette_id, photo_library_id, image_url, title, order_index) VALUES
        (1, 1, 11, 41, NULL, 'Kitchen', 0),
        (2, 1, 11, 41, NULL, 'Duplicate kitchen', 1),
        (3, 1, 12, 42, NULL, 'Exterior', 2),
        (4, 2, 11, NULL, '/photos/unrelated.jpg', 'Other project', 0),
        (5, 1, 11, NULL, '/photos/kitchen-detail.jpg', 'Kitchen detail', 3)");

    $empty = (new PdoPVRepository($pdo))->findPainterAdminDocument(84);
    assert_equals([], $empty['project_palettes'][0]['photos']);
    assert_equals([], $empty['project_palettes'][1]['photos']);
    $pdo->exec('CREATE TABLE project_photos (project_id INTEGER, photo_library_id INTEGER,
        `use` INTEGER, palette_id INTEGER, zoom INTEGER, main INTEGER, `before` INTEGER, sort_order INTEGER)');
    $pdo->exec('INSERT INTO project_photos VALUES (7,41,1,11,0,1,0,0),(7,42,1,12,0,1,0,1),(7,43,1,11,1,0,0,2)');
    $document = (new PdoPVRepository($pdo))->findPainterAdminDocument(84);
    assert_equals(['Exterior', 'Kitchen'], array_column($document['project_palettes'], 'area_label'));
    $exterior = $document['project_palettes'][0]['photos'];
    $kitchen = $document['project_palettes'][1]['photos'];
    assert_equals(1, count($exterior));
    assert_equals(2, count($kitchen));
    assert_equals(42, $exterior[0]['photo_library_id']);
    assert_equals('Kitchen rendering', $kitchen[0]['alt_text']);
    assert_equals('/photos/kitchen-detail.jpg', $kitchen[1]['image_url']);
    assert_true(str_starts_with($kitchen[0]['image_url'], '/photos/kitchen.jpg?v='));

    $pdo->exec('UPDATE project_photos SET `use`=0');
    $empty = (new PdoPVRepository($pdo))->findPainterAdminDocument(84);
    assert_equals([], $empty['project_palettes'][0]['photos']);
    assert_equals([], $empty['project_palettes'][1]['photos']);
});

test('painter REX preview rejects inactive viewers and empty room selections', function () {
    $pdo = painter_viewer_rex_fixture();
    $resolver = new ViewerResolver($pdo);
    foreach ([
        "UPDATE palette_viewers SET is_active = 0 WHERE palette_viewer_id = 84" => 'inactive',
        "UPDATE palette_viewers SET is_active = 1; UPDATE project_palettes SET is_final = 0" => 'no selected project palettes',
    ] as $sql => $expected) {
        $pdo->exec($sql);
        try {
            $resolver->previewDescribe('palette_viewer', 84, ['format' => 'painter']);
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), $expected), $e->getMessage());
            continue;
        }
        throw new RuntimeException('Expected Painter preview validation failure');
    }
});

test('painter room photos use the shared project roles and order without before images', function () {
    $pdo = painter_viewer_rex_fixture();
    $pdo->exec('CREATE TABLE project_photos (project_id INTEGER, photo_library_id INTEGER,
        `use` INTEGER, palette_id INTEGER, zoom INTEGER, main INTEGER, before INTEGER, sort_order INTEGER,
        PRIMARY KEY(project_id, photo_library_id))');
    $pdo->exec("INSERT INTO photo_library VALUES
        (41, '/photos/kitchen-main.jpg', 'Main', NULL, NULL),
        (42, '/photos/kitchen-zoom.jpg', 'Zoom', NULL, NULL),
        (43, '/photos/kitchen-before.jpg', 'Before', NULL, NULL),
        (44, '/photos/exterior-main.jpg', 'Exterior', NULL, NULL)");
    $repo = new \App\PROJECTS\Repos\PdoProjectPhotoRepository($pdo);
    $repo->save(7, [
        ['photo_library_id' => 43, 'use' => true, 'before' => true, 'palette_id' => 11],
        ['photo_library_id' => 42, 'use' => true, 'zoom' => true, 'palette_id' => 11],
        ['photo_library_id' => 41, 'use' => true, 'main' => true, 'palette_id' => 11],
        ['photo_library_id' => 44, 'use' => true, 'main' => true, 'palette_id' => 12],
    ], '');
    $viewer = (new \App\PALETTES\PV\PVService($pdo))->getPV(84);
    assert_equals([44], array_column($viewer['plans'][0]['photos'], 'photo_library_id'));
    assert_equals([42, 41], array_column($viewer['plans'][1]['photos'], 'photo_library_id'));
    $document = (new PdoPVRepository($pdo))->findPainterAdminDocument(84);
    assert_equals([42, 41], array_column($document['project_palettes'][1]['photos'], 'photo_library_id'));
});

test('painter PV automatically follows all FINAL rooms without manual viewer selections', function () {
    $pdo = painter_viewer_rex_fixture();
    $pdo->exec('DELETE FROM palette_viewer_project_palettes');
    $repo = new PdoPVRepository($pdo);
    assert_equals([102, 101], array_map('intval', array_column($repo->listPainterProjectPalettes(84), 'project_palette_id')));
    $pdo->exec('UPDATE project_palettes SET is_final = 0 WHERE project_palette_id = 102');
    $viewer = (new \App\PALETTES\PV\PVService($pdo))->getPV(84);
    assert_equals(['Kitchen'], array_column($viewer['plans'], 'title'));
    $pdo->exec('UPDATE project_palettes SET is_final = 1 WHERE project_palette_id = 102');
    $viewer = (new \App\PALETTES\PV\PVService($pdo))->getPV(84);
    assert_equals(['Exterior', 'Kitchen'], array_column($viewer['plans'], 'title'));
});
